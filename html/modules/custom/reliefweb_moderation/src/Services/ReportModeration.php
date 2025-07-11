<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
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
      'origin' => [
        'label' => $this->t('Origin'),
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

      // Author and reviewer.
      $details['author'] = $this->t('author: @author', [
        '@author' => $this->getEntityAuthorData($entity),
      ]);
      $details['reviewer'] = $this->t('reviewer: @reviewer', [
        '@reviewer' => $this->getEntityReviewerData($entity),
      ]);

      $data['details'] = array_filter($details);

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // Retrieve the origin of the document.
      $options = $entity->field_origin->first()->getPossibleOptions();
      if ($entity instanceof NodeInterface) {
        if ($entity->getOwner()->hasRole('contributor')) {
          $cells['origin'] = $this->t('Contributor');
        }
        elseif ($entity->getOwner()->hasRole('submitter')) {
          $cells['origin'] = $this->t('Submitter');
        }
        else {
          $cells['origin'] = $options[$entity->field_origin->value] ?? $this->t('N/A');
        }
      }
      else {
        $cells['origin'] = $options[$entity->field_origin->value] ?? $this->t('N/A');
      }

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
      'reference' => $this->t('Reference'),
      'pending' => $this->t('Pending'),
      'refused' => $this->t('Refused'),
      'archive' => $this->t('Archived'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefaultStatuses() {
    $statuses = $this->getFilterStatuses();
    unset($statuses['archive']);
    unset($statuses['refused']);
    return array_keys($statuses);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    if (UserHelper::userHasRoles(['editor'])) {
      return $this->getButtonsForEditors($status, $entity);
    }
    elseif (UserHelper::userHasRoles(['contributor'])) {
      return $this->getButtonsForContributors($status, $entity);
    }
    elseif (UserHelper::userHasRoles(['submitter'])) {
      return $this->getButtonsForSubmitters($status, $entity);
    }

    return [];
  }

  /**
   * Get the buttons for the editors.
   *
   * @param string $status
   *   Current entity status.
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return array
   *   Buttons.
   */
  protected function getButtonsForEditors(string $status, EntityModeratedInterface $entity): array {
    // Editors can publish, put on hold or refuse a document.
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

    // Add extra buttons to manage content submitted via the API.
    if ($entity->hasField('field_post_api_provider') && !empty($entity->field_post_api_provider?->target_id)) {
      $buttons['pending'] = [
        '#value' => $this->t('Pending'),
      ];
      // Note: once refused the document is not editable anymore unless
      // the current user is also an administrator or webmaster.
      // @see ::isEditableStatus().
      $buttons['refused'] = [
        '#value' => $this->t('Refused'),
      ];
    }

    // Admin and webmasters can also edit archived or refused documents.
    // @see ::isEditableStatus().
    if (UserHelper::userHasRoles(['administrator', 'webmaster'])) {
      $buttons['archive'] = [
        '#value' => $this->t('Archive'),
      ];

      // Allow to refuse already refused documents (from contributors or API).
      if ($status === 'refused') {
        $buttons['refused'] = [
          '#value' => $this->t('Refused'),
        ];
      }
    }

    return $buttons;
  }

  /**
   * Get the buttons for the contributors.
   *
   * @param string $status
   *   Current entity status.
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return array
   *   Buttons.
   */
  protected function getButtonsForContributors(string $status, EntityModeratedInterface $entity): array {
    $new = empty($status) || $status === 'draft' || $entity->isNew();

    $buttons = [
      'draft' => [
        '#value' => $this->t('Save as draft'),
      ],
      'pending' => [
        '#value' => $new ? $this->t('Submit') : $this->t('Submit changes'),
      ],
      'on-hold' => [
        '#value' => $this->t('On-hold'),
      ],
    ];

    // Add confirmation when attempting to change published document.
    if ($status === 'to-review' || $status === 'published') {
      $message = $this->t('Press OK to submit the changes for review by the ReliefWeb editors. The report may become unpublished while being reviewed.');
      $buttons['pending']['#attributes']['onclick'] = 'return confirm("' . $message . '")';
    }

    // Warning message when saving as a draft.
    if (isset($buttons['draft'])) {
      $message = $this->t('You are saving this document as a draft. It will not be visible to visitors. If you wish to proceed with the publication kindly click on @buttons instead.', [
        '@buttons' => implode(' or ', array_map(function ($item) {
          return $item['#value'];
        }, array_slice($buttons, 1))),
      ]);
      $buttons['draft']['#attributes']['onclick'] = 'return confirm("' . $message . '")';
    }

    return $buttons;
  }

  /**
   * Get the buttons for the submitters.
   *
   * @param string $status
   *   Current entity status.
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return array
   *   Buttons.
   */
  protected function getButtonsForSubmitters(string $status, EntityModeratedInterface $entity): array {
    $new = empty($status) || $status === 'draft' || $entity->isNew();

    $buttons = [
      'pending' => [
        '#value' => $new ? $this->t('Submit') : $this->t('Submit changes'),
      ],
    ];

    if (!$new) {
      $buttons['on-hold'] = [
        '#value' => $this->t('Unpublish'),
      ];
    }

    // Add confirmation when attempting to change published document.
    if ($status === 'to-review' || $status === 'published') {
      $message = $this->t('Press OK to submit the changes for review by the ReliefWeb editors. The report may become unpublished while being reviewed.');
      $buttons['pending']['#attributes']['onclick'] = 'return confirm("' . $message . '")';
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
    elseif ($status === 'refused') {
      return UserHelper::userHasRoles(['administrator', 'editor', 'webmaster'], $account);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function disableNotifications(EntityModeratedInterface $entity, $status) {
    $previous_status = $entity->getOriginal()?->getModerationStatus() ?? '';
    // Disable if not published or previously published to avoid resending
    // notifications when making modification to a published report.
    $entity->notifications_content_disable = $status !== 'published' || $previous_status === 'published';
  }

  /**
   * {@inheritdoc}
   */
  public function entityAccess(EntityModeratedInterface $entity, $operation = 'view', ?AccountInterface $account = NULL) {
    $account = $account ?: $this->currentUser;

    $access_result = parent::entityAccess($entity, $operation, $account);

    if ($operation !== 'view') {
      // Normally editors can edit any kind of reports
      // but there are some exceptions like archived reports.
      $access = !$access_result->isForbidden() &&
        $this->isEditableStatus($entity->getModerationStatus(), $account);

      $access_result = $access ? $access_result : AccessResult::forbidden();
    }

    // Submitters have stricter access rules.
    if ($account->hasRole('submitter') && !$account->hasRole('contributor') && !$account->hasRole('editor') && !$access_result->isForbidden()) {
      $access_result = $this->entityAccessForSubmitters($entity, $operation, $account, $access_result);
    }

    return $access_result;
  }

  /**
   * {@inheritdoc}
   */
  public function entityCreateAccess(AccountInterface $account): AccessResultInterface {
    $access_result = parent::entityCreateAccess($account);
    // Disallow report creation for submitters without posting rights.
    if ($account->hasPermission('create report only if allowed or trusted for a source') && !UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($account, $this->getBundle())) {
      return AccessResult::forbidden();
    }
    return $access_result;
  }

  /**
   * Perform addition access check for submitters.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity being accessed.
   * @param string $operation
   *   Access operation being performed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User trying to access.
   * @param \Drupal\Core\Access\AccessResultInterface $access_result
   *   Current access result.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The updated access result.
   */
  protected function entityAccessForSubmitters(EntityModeratedInterface $entity, string $operation, AccountInterface $account, AccessResultInterface $access_result): AccessResultInterface {
    $owner = $entity->getOwnerId() === $account->id() && $account->id() > 0;

    $allowed = match($operation) {
      'view' => $owner,
      'create' => UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($account, $this->getBundle()),
      'update' => $owner && UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($account, $this->getBundle()),
      'delete' => FALSE,
      'view_moderation_information' => !$access_result->isForbidden(),
      default => !$access_result->isForbidden(),
    };

    $access_result->forbiddenIf(!$allowed);
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
      'origin',
      'automated_classification',
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
    $definitions['document_origin'] = [
      'type' => 'other',
      'field' => 'roles_target_id',
      'label' => $this->t('Document origin'),
      'form' => 'document_origin',
      // No specific widget as the join is enough.
      'widget' => 'none',
      'values' => [
        'api' => $this->t('API'),
        'contributor' => $this->t('Contributor'),
        'submitter' => $this->t('Submitter'),
        'editor' => $this->t('Editor'),
      ],
      'join_callback' => 'joinDocumentOrigin',
    ];

    return $definitions;
  }

  /**
   * Document origin join callback.
   *
   * @see ::joinField()
   */
  protected function joinDocumentOrigin(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    $values = array_flip($values);

    // Join the origin field, restricting to API submissions.
    if (isset($values['api'])) {
      $origin_table = 'node__field_origin';
      $origin_alias = $query->leftJoin($origin_table, $origin_table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");

      $or_group ??= $query->conditionGroupFactory('OR');
      $or_group->condition($origin_alias . '.field_origin_value', 3, '=');
      unset($values['api']);
    }

    // Join the users_roles table, restricting to some roles.
    if (!empty($values)) {
      $poster_roles = ['contributor', 'editor', 'submitter'];

      $role_table = 'user__roles';
      $role_field = $role_table . '.roles_target_id';

      // Retrieve the contributor, submitter or editor users so that we
      // filter on their user ids instead of trying to join the user roles
      // table which can results in duplicate entries in the count query
      // due to the fact users may have several of those roles.
      // There is a limited number of users who post reports so this is a
      // reasonable approach in terms of performance.
      $user_query = $this->database->select($entity_base_table, $entity_base_table);
      $user_query->innerJoin($role_table, $role_table, "%alias.entity_id = {$entity_base_table}.uid AND %alias.bundle = :bundle", [
        ':bundle' => 'user',
      ]);
      $user_query->addField($entity_base_table, 'uid', 'uid');
      $user_query->addExpression("GROUP_CONCAT(DISTINCT {$role_field} ORDER BY {$role_field} ASC)", 'roles');
      $user_query->condition($role_table . '.roles_target_id', $poster_roles, 'IN');
      $user_query->groupBy($entity_base_table . '.uid');
      $user_records = $user_query->execute();

      // Use 0 as default to ensure there is always a condition and empty
      // results are properly displayed when there is no user with the selected
      // roles.
      $user_ids = [0 => 0];

      // Filter users to exclude those with more poster roles that the filtered
      // ones. This way when selecting 'submitter' for example, then only users
      // with the submitter role and without the contributor or editor role will
      // be considered.
      foreach ($user_records as $record) {
        $roles = explode(',', $record->roles);
        // Skip the user if it has a poster role that is not in the list of
        // selected roles in the filter.
        foreach ($roles as $role) {
          if (!isset($values[$role])) {
            continue 2;
          }
        }
        $user_ids[$record->uid] = $record->uid;
      }

      $or_group ??= $query->conditionGroupFactory('OR');
      $or_group->condition($entity_base_table . '.uid', $user_ids, 'IN');
    }

    if (isset($or_group)) {
      $query->condition($or_group);
    }

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
