<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\ModerationServiceBase;

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

    /** @var \Drupal\Core\Entity\EntityInterface[] $entities */
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
  protected function initFilterDefinitions($filters = []) {
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
