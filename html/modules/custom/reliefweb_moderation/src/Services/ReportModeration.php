<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
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

      // Embargo date.
      $embargo_date = $entity->field_embargo_date->value;
      if (!empty($embargo_date)) {
        $data['embargo_date'] = $embargo_date;
      }

      // Country and source info.
      $info = [];

      // User posting rights.
      if ($entity instanceof NodeInterface && $entity->getOwner()->hasRole('contributor')) {
        $info['posting_rights'] = UserPostingRightsHelper::renderRight(UserPostingRightsHelper::getEntityAuthorPostingRights($entity));
      }

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
      'pending' => $this->t('Pending'),
      'published' => $this->t('Published'),
      'embargoed' => $this->t('Embargoed'),
      'refused' => $this->t('Refused'),
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
    $buttons = [];
    $new = empty($status) || $status === 'draft' || $entity->isNew();

    // Only show save as draft for non-published but editable documents.
    if ($new || in_array($status, ['draft', 'on-hold'])) {
      $buttons['draft'] = [
        '#value' => $this->t('Save as draft'),
      ];
    }

    // Editors can publish, put on hold or refuse a document.
    // @todo use permission.
    if (UserHelper::userHasRoles(['editor'])) {
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
    }
    elseif (UserHelper::userHasRoles(['administrator', 'webmaster'])) {
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
        'archive' => [
          '#value' => $this->t('Archive'),
        ],
      ];
    }
    // Other users can submit for review, on-hold or published if trusted.
    else {
      $buttons = [
        'draft' => [
          '#value' => $this->t('Save as draft'),
        ],
        'to-review' => [
          '#value' => $new ? $this->t('Submit') : $this->t('Submit changes'),
        ],
        'on-hold' => [
          '#value' => $this->t('On-hold'),
        ],
      ];

      // Add confirmation when attempting to change published document.
      if ($status === 'published') {
        $message = $this->t('Press OK to submit the changes for review by the ReliefWeb editors. The report may become unpublished while being reviewed.');
        $buttons['to-review']['#attributes']['onclick'] = 'return confirm("' . $message . '")';
      }
    }

    if (UserHelper::userHasRoles(['contributor'])) {
      // Warning message when saving as a draft.
      if (isset($buttons['draft'])) {
        $message = $this->t('You are saving this document as a draft. It will not be visible to visitors. If you wish to proceed with the publication kindly click on @buttons instead.', [
          '@buttons' => implode(' or ', array_map(function ($item) {
            return $item['#value'];
          }, array_slice($buttons, 1))),
        ]);
        $buttons['draft']['#attributes']['onclick'] = 'return confirm("' . $message . '")';
      }
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
    if ($status === 'archive' || $status === 'refused') {
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
      'posting_rights',
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
    $definitions = array_merge_recursive($definitions, [
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

    // Add a filter to restrict to content with an embargo date.
    $definitions['embargo_date'] = [
      'type' => 'field',
      'label' => $this->t('Embargo date'),
      'field' => 'field_embargo_date',
      'column' => 'value',
      'form' => 'other',
      // No specific widget as the join is enough.
      'widget' => 'none',
      'join_callback' => 'joinEmbargoDate',
    ];

    // Add a filter to restrict to content posted by a Contributor.
    $definitions['contribution'] = [
      'type' => 'other',
      'field' => 'roles_target_id',
      // Not naming that 'Contribution' to avoid confusion with the Donor
      // Contributions theme.
      'label' => $this->t('From contributor'),
      'form' => 'other',
      // No specific widget as the join is enough.
      'widget' => 'none',
      'join_callback' => 'joinContribution',
    ];

    return $definitions;
  }

  /**
   * Contribution join callback.
   *
   * @see ::joinField()
   */
  protected function joinContribution(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Join the users_roles table restricting to the contributor role.
    $table = 'user__roles';
    $query->innerJoin($table, $table, "%alias.entity_id = {$entity_base_table}.uid AND %alias.bundle = :bundle AND %alias.roles_target_id = :role", [
      ':bundle' => 'user',
      ':role' => 'contributor',
    ]);

    // No field to return as the inner join is enough.
    return '';
  }

  /**
   * Emnbargo date join callback.
   *
   * @see ::joinField()
   */
  protected function joinEmbargoDate(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Join the embargo date field and restrict to content with a value.
    $table = $this->getFieldTableName('node', 'field_embargo_date');
    $field_name = $this->getFieldColumnName('node', 'field_embargo_date', 'value');
    $query->innerJoin($table, $table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field} AND %alias.{$field_name} IS NOT NULL");

    // No field to return as the inner join is enough.
    return '';
  }

}
