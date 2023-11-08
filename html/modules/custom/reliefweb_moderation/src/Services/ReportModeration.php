<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Moderation service for the report nodes.
 */
class ReportModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'report';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->t('Reports');
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
        'label' => $this->t('Report'),
      ],
      'date' => [
        'label' => $this->t('Posted'),
        'type' => 'property',
        'specifier' => 'created',
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

    // Check if the reports are linked as Key Content.
    $urls = preg_filter('/^/', '/node/', array_keys($entities));

    $key_content_table = $this->getFieldTableName('taxonomy_term', 'field_key_content');
    $key_content_active_field = $this->getFieldColumnName('taxonomy_term', 'field_key_content', 'active');
    $key_content_url_field = $this->getFieldColumnName('taxonomy_term', 'field_key_content', 'url');
    $taxonomy_term_table = $this->getEntityTypeDataTable('taxonomy_term');
    $taxonomy_term_id_field = $this->getEntityTypeIdField('taxonomy_term');
    $taxonomy_term_label_field = $this->getEntityTypeLabelField('taxonomy_term');

    $query = $this->database->select($key_content_table, 'f');
    $query->innerJoin($taxonomy_term_table, 'td', "td.{$taxonomy_term_id_field} = f.entity_id");
    $query->addField('td', $taxonomy_term_id_field, 'tid');
    $query->addField('td', $taxonomy_term_label_field, 'name');
    $query->addField('f', $key_content_active_field, 'active');
    $query->addExpression("SUBSTR(f.{$key_content_url_field}, 7)", 'nid');
    $query->condition("f.{$key_content_url_field}", $urls, 'IN');
    $key_content = $query->execute()?->fetchAllAssoc('nid', \PDO::FETCH_ASSOC);

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      // Edit link + status cell.
      $cells['edit'] = $this->getEntityEditAndStatusData($entity);

      // Entity data cell.
      $data = [];

      // Title.
      $data['title'] = $entity->toLink()->toString();

      // Headline.
      $headline = $entity->field_headline->value;
      $headline_title = $entity->field_headline_title->value;
      if (!empty($headline) && !empty($headline_title)) {
        $data['headline_title'] = $headline_title;
      }

      // Country and source info.
      $info = [];
      // Country.
      $country_link = $this->getTaxonomyTermLink($entity->field_primary_country->first());
      if (!empty($country_link)) {
        $info['country'] = $country_link;
      }
      // Source.
      $sources = [];
      foreach ($this->sortTaxonomyTermFieldItems($entity->field_source) as $item) {
        $source_link = $this->getTaxonomyTermLink($item);
        if (!empty($source_link)) {
          $sources[] = $source_link;
        }
      }
      if (!empty($sources)) {
        $info['source'] = $sources;
      }
      $data['info'] = array_filter($info);

      // Details.
      $details = [];
      // Content format.
      $details['format'] = [];
      foreach ($this->sortTaxonomyTermFieldItems($entity->field_content_format) as $item) {
        if (!empty($item->entity)) {
          $item_title = $item->entity->label();
          $item_parameter = 'selection[content_format][]';
          $item_value = $item->entity->id() . ':' . $item_title;
          $details['format'][] = $this->getFilterLink($item_title, $item_parameter, $item_value);
        }
      }
      // Language.
      $details['language'] = [];
      foreach ($this->sortTaxonomyTermFieldItems($entity->field_language) as $item) {
        if (!empty($item->entity)) {
          $item_title = $item->entity->label();
          $item_parameter = 'selection[language][]';
          $item_value = $item->entity->id() . ':' . $item_title;
          $details['language'][] = $this->getFilterLink($item_title, $item_parameter, $item_value);
        }
      }
      // OCHA Product.
      $details['ocha-product'] = [];
      foreach ($entity->field_ocha_product as $item) {
        if (!empty($item->entity)) {
          $item_title = $item->entity->label();
          $item_parameter = 'selection[ocha_product][]';
          $item_value = $item->entity->id() . ':' . $item_title;
          $item_link = $this->getFilterLink($item_title, $item_parameter, $item_value);
          $details['ocha-product'][] = $this->t('OCHA product: @name', [
            '@name' => $item_link,
          ]);
        }
      }
      // Key content for a country/disaster.
      $details['key-content'] = [];
      if (isset($key_content[$entity->id()])) {
        $key_content_data = $key_content[$entity->id()];
        $details['key-content'][] = $this->t('@active Key Content for @name', [
          '@active' => !empty($key_content_data['active']) ? $this->t('Active') : $this->t('Archive'),
          '@name' => Link::fromTextAndUrl($key_content_data['name'], Url::fromUri('entity:taxonomy_term/' . $key_content_data['tid']))->toString(),
        ]);
      }
      // Author.
      $details['author'] = $this->getEntityAuthorData($entity);
      $data['details'] = array_filter($details);

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // Date cell.
      $cells['date'] = [
        'date' => $this->getEntityCreationDate($entity),
        'bury' => !empty($entity->field_bury->value),
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
      'draft' => $this->t('Draft'),
      'on-hold' => $this->t('On-hold'),
      'to-review' => $this->t('To review'),
      'published' => $this->t('Published'),
      'embargoed' => $this->t('Embargoed'),
      'archive' => $this->t('Archived'),
      'reference' => $this->t('Reference'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefaultStatuses() {
    $statuses = $this->getFilterStatuses();
    unset($statuses['archive']);
    return array_keys($statuses);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    $buttons = [
      'draft' => [
        '#value' => $this->t('Save as draft'),
      ],
      'to-review' => [
        '#value' => $this->t('To review'),
      ],
      'published' => [
        '#value' => $this->t('Publish'),
      ],
      'on-hold' => [
        '#value' => $this->t('On-hold'),
      ],
      'reference' => [
        '#value' => $this->t('Reference'),
      ],
    ];

    // @todo replace with permission.
    if (UserHelper::userHasRoles(['administrator', 'webmaster'])) {
      $buttons['archive'] = [
        '#value' => $this->t('Archive'),
      ];
    }

    return $buttons;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublishedStatus($status) {
    return $status === 'to-review' || $status === 'published';
  }

  /**
   * {@inheritdoc}
   */
  public function isEditableStatus($status, ?AccountInterface $account = NULL) {
    if ($status === 'archive') {
      return UserHelper::userHasRoles(['administrator', 'webmaster'], $account);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function disableNotifications(EntityModeratedInterface $entity, $status) {
    $previous_status = isset($entity->original) ? $entity->original->getModerationStatus() : '';
    // Disable if not published or previously published to avoid resending
    // notifications when making modification to a published report.
    $entity->notifications_content_disable = $status !== 'published' || $previous_status === 'published';
  }

  /**
   * {@inheritdoc}
   */
  public function entityAccess(EntityModeratedInterface $entity, $operation = 'view', ?AccountInterface $account = NULL) {
    $access_result = parent::entityAccess($entity, $operation, $account);

    if ($operation !== 'view') {
      // Normally editors can edit any kind of reports
      // but there are some exceptions like archived reports.
      $access = !$access_result->isForbidden() &&
        $this->isEditableStatus($entity->getModerationStatus(), $account);

      $access_result = $access ? $access_result : AccessResult::forbidden();
    }

    return $access_result;
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
      'primary_country',
      'country',
      'source',
      'theme',
      'content_format',
      'disaster',
      'disaster_type',
      // Disable vulnerable group field (#kUklB1e4).
      /*'vulnerable_groups',*/
      'language',
      'organization_type',
      'ocha_product',
      'created',
      'original_publication_date',
      'author',
      'user_role',
      'reviewer',
      'reviewed',
      'comments',
      'title',
      'body',
      'headline_title',
      'headline_summary',
      'headline',
      'bury',
      'key_content',
    ]);

    // Values are hardcoded to avoid the use of a query.
    return array_merge_recursive($definitions, [
      'feature' => [
        'type' => 'field',
        'label' => $this->t('Feature'),
        'field' => 'field_feature',
        'column' => 'target_id',
        'form' => 'other',
        'values' => [
          10635 => 'Location Map',
          12490 => 'Must Read',
        ],
      ],
    ]);
  }

}
