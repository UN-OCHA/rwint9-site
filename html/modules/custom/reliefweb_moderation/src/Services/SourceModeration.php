<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Moderation service for the source terms.
 */
class SourceModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'source';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return 'taxonomy_term';
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->t('Sources');
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    return [
      'edit' => [
        'label' => '',
      ],
      'data' => [
        'label' => $this->t('Source'),
        'type' => 'property',
        'specifier' => 'name',
        'sortable' => TRUE,
      ],
      'date' => [
        'label' => $this->t('Created'),
        'type' => 'property',
        'specifier' => 'tid',
        'sortable' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(array $results) {
    if (empty($results['entities'])) {
      return [];
    }

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      // Edit link + status cell.
      $cells['edit'] = $this->getEntityEditAndStatusData($entity);

      // Entity data cell.
      $data = [];

      // Title.
      $title = $entity->label();
      $shortname = $entity->field_shortname->value;
      if (!empty($shortname) && $shortname) {
        $title .= ' (' . $shortname . ')';
      }
      $data['title'] = $entity->toLink($title)->toString();

      // Country, organization type and homepage info.
      $info = [];
      // Country.
      $countries = [];
      foreach ($entity->field_country as $item) {
        $country_link = $this->getTaxonomyTermLink($item);
        if (!empty($country_link)) {
          $countries[] = $country_link;
        }
      }
      if (!empty($countries)) {
        $info['country'] = $countries;
      }
      // Organization type.
      if (!$entity->field_organization_type->isEmpty()) {
        $info['organization_type'] = $entity->field_organization_type->entity->label();
      }
      // Homepage.
      if (!$entity->field_homepage->isEmpty()) {
        $homepage_uri = $entity->field_homepage->uri;
        if (preg_match('#https?://#', $homepage_uri) !== 1) {
          $homepage_uri = 'http://' . $homepage_uri;
        }
        $homepage_title = $entity->field_homepage->title ?: $homepage_uri;
        $info['homepage'] = Link::fromTextAndUrl($homepage_title, Url::fromUri($homepage_uri));
      }
      $data['info'] = array_filter($info);

      // Allowed content types.
      $details = [];
      // The order matches the numeric values of the field.
      $content_types = ['job', 'report', 'training'];
      $allowed_content_types = [];
      foreach ($entity->field_allowed_content_types as $item) {
        $item_value = $item->value;
        if (isset($content_types[$item_value])) {
          $item_title = $content_types[$item_value];
          // This will - on purpose - override the content type selection to
          // use this item value.
          $item_parameter = 'filters[content_type]';
          $allowed_content_types[] = $this->getFilterLink($item_title, $item_parameter, [
            $item_value => $item_value,
          ]);
        }
      }
      if (!empty($allowed_content_types)) {
        $allowed_content_types[0] = $this->t('Allowed content types: @type', [
          '@type' => $allowed_content_types[0],
        ]);
        $details['content_type'] = $allowed_content_types;
      }
      else {
        $details['content_type'] = $this->t('No allowed content type selected');
      }
      $data['details'] = array_filter($details);

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // Date cell.
      $cells['date'] = [
        'date' => $this->getEntityCreationDate($entity),
      ];

      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'active' => $this->t('Active'),
      'inactive' => $this->t('Inactive'),
      'archive' => $this->t('Archive'),
      'blocked' => $this->t('Blocked'),
      'duplicate' => $this->t('Duplicate'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    return [
      'active' => [
        '#value' => $this->t('Active'),
      ],
      'inactive' => [
        '#value' => $this->t('Inactive'),
      ],
      'archive' => [
        '#value' => $this->t('Archive'),
      ],
      'blocked' => [
        '#value' => $this->t('Blocked'),
      ],
      'duplicate' => [
        '#value' => $this->t('Duplicate'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isViewableStatus($status, ?AccountInterface $account = NULL) {
    return $status === 'active' || $status === 'inactive' || UserHelper::userHasRoles(['editor'], $account);
  }

  /**
   * {@inheritdoc}
   */
  public function entityPresave(EntityModeratedInterface $entity) {
    // Ensure all posting rights are 'blocked' if the status is 'blocked'.
    $status = $entity->getModerationStatus();
    if ($status === 'blocked') {
      $changed = FALSE;

      // Update the posting rights field, setting everything as blocked.
      if (!$entity->get('field_user_posting_rights')->isEmpty()) {
        foreach ($entity->get('field_user_posting_rights') as $item) {
          if ($item->get('job')->getValue() != 1 || $item->get('training')->getValue() != 1) {
            $item->get('job')->setValue(1);
            $item->get('training')->setValue(1);
            $changed = TRUE;
          }
        }
      }

      // Add a message if something changed.
      if ($changed) {
        $entity->setNewRevision(TRUE);
        $entity->setRevisionLogMessage(trim(implode(' ', [
          'Posting rights changed to blocked due to source being blocked.',
          $entity->getRevisionLogMessage() ?? '',
        ])));
      }
    }

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
      'name',
      'shortname',
      'organization_type',
      'country',
      'content_type',
    ]);
    unset($definitions['organization_type']['join_callback']);
    return $definitions;
  }

}
