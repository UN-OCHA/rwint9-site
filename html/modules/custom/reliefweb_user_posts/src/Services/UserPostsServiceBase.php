<?php

namespace Drupal\reliefweb_user_posts\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Base moderation service for the user posts pages.
 */
abstract class UserPostsServiceBase extends ModerationServiceBase {

  /**
   * Supported content bundles for My posts.
   */
  public const BUNDLES = ['report', 'job', 'training'];

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager service.
   * @param \Drupal\Core\Pager\PagerParametersInterface $pager_parameters
   *   The pager parameter service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $user_posting_rights_manager
   *   The user posting rights manager service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    Connection $database,
    DateFormatterInterface $date_formatter,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    PagerManagerInterface $pager_manager,
    PagerParametersInterface $pager_parameters,
    RequestStack $request_stack,
    TranslationInterface $string_translation,
    UserPostingRightsManagerInterface $user_posting_rights_manager,
    RouteMatchInterface $route_match,
  ) {
    parent::__construct(
      $current_user,
      $database,
      $date_formatter,
      $entity_field_manager,
      $entity_type_manager,
      $pager_manager,
      $pager_parameters,
      $request_stack,
      $string_translation,
      $user_posting_rights_manager
    );
    $this->routeMatch = $route_match;
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
    return $this->t('My posts');
  }

  /**
   * Get the human-readable label for a bundle.
   *
   * @param string $bundle
   *   Bundle machine name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   Bundle label.
   */
  public static function getBundleLabel(string $bundle) {
    return match ($bundle) {
      'report' => t('Reports'),
      'job' => t('Jobs'),
      'training' => t('Training'),
      default => $bundle,
    };
  }

  /**
   * Whether the account can manage affiliated content of the given bundle.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   * @param string $bundle
   *   Content bundle.
   *
   * @return bool
   *   TRUE if the account has affiliated view or edit permission.
   */
  public static function hasAffiliatedAccess(AccountInterface $account, string $bundle): bool {
    return $account->hasPermission('edit affiliated ' . $bundle . ' content')
      || $account->hasPermission('view affiliated unpublished ' . $bundle . ' content');
  }

  /**
   * Get the My posts bundles available for a user.
   *
   * A bundle is available when the user can create it, has affiliated access,
   * or has authored at least one node of that type.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Profile user.
   *
   * @return string[]
   *   Available bundle machine names, in display order.
   */
  public function getAvailableBundles(AccountInterface $account): array {
    $available = [];
    foreach (static::BUNDLES as $bundle) {
      if (
        $account->hasPermission('create ' . $bundle . ' content')
        || static::hasAffiliatedAccess($account, $bundle)
        || $this->userHasAuthoredBundle($account, $bundle)
      ) {
        $available[] = $bundle;
      }
    }
    return $available;
  }

  /**
   * Check whether the user has authored at least one node of the given bundle.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   * @param string $bundle
   *   Node bundle.
   *
   * @return bool
   *   TRUE if the user authored at least one node of that type.
   */
  protected function userHasAuthoredBundle(AccountInterface $account, string $bundle): bool {
    $uid = (int) $account->id();
    if ($uid === 0) {
      return FALSE;
    }
    $query = $this->database->select('node_field_data', 'n');
    $query->addExpression('1');
    $query->condition('n.uid', $uid);
    $query->condition('n.type', $bundle);
    $query->range(0, 1);
    return (bool) $query->execute()->fetchField();
  }

  /**
   * Get overview statistics for this service's content type.
   *
   * @return array
   *   Associative array with:
   *   - mine: number of posts authored by the page user
   *   - colleagues: number of colleagues' posts, or NULL when not applicable
   *   - organizations: number of linked organizations for this type
   */
  public function getOverviewStats(): array {
    $user = $this->getUser();
    $mine = $this->executeQuery([
      'poster' => [
        'me' => 1,
        'colleagues' => 0,
      ],
    ], 1);
    $stats = [
      'mine' => (int) ($mine['totals']['total'] ?? 0),
      'colleagues' => NULL,
      'organizations' => count($this->getSourceFilterOptions()['options'] ?? []),
    ];

    if ($user && static::hasAffiliatedAccess($user, $this->getBundle())) {
      $colleagues = $this->executeQuery([
        'poster' => [
          'me' => 0,
          'colleagues' => 1,
        ],
      ], 1);
      $stats['colleagues'] = (int) ($colleagues['totals']['total'] ?? 0);
    }

    return $stats;
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    $headers = [
      'id' => [
        'label' => $this->t('Id'),
        'type' => 'property',
        'specifier' => 'nid',
        'sortable' => TRUE,
      ],
      'status' => [
        'label' => $this->t('Status'),
        'type' => 'property',
        'specifier' => 'moderation_status',
        'sortable' => TRUE,
      ],
      'poster' => [
        'label' => $this->t('Poster'),
      ],
      'source' => [
        'label' => $this->t('Source'),
      ],
      'title' => [
        'label' => $this->t('Title'),
        'type' => 'property',
        'specifier' => 'title',
        'sortable' => TRUE,
      ],
      'date' => [
        'label' => $this->t('Posted'),
        'type' => 'property',
        'specifier' => 'created',
        'sortable' => TRUE,
      ],
    ];

    if ($this->hasDeadlineColumn()) {
      $headers['deadline'] = [
        'label' => $this->t('Deadline'),
        'type' => 'custom',
        'specifier' => 'deadline',
        'sortable' => TRUE,
      ];
    }

    return $headers;
  }

  /**
   * Whether this service shows a deadline column and filter.
   *
   * @return bool
   *   TRUE for jobs and training.
   */
  protected function hasDeadlineColumn(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(array $results) {
    if (empty($results['entities'])) {
      return [];
    }

    $user_id = $this->getUserId();

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      $cells['id'] = $entity->id();
      $cells['status'] = new FormattableMarkup('<div class="rw-moderation-status" data-moderation-status="@status">@label</div>', [
        '@status' => $entity->getModerationStatus(),
        '@label' => $entity->getModerationStatusLabel(),
      ]);

      $poster_type = $user_id == $entity->getOwnerId() ? 'me' : 'colleague';
      $cells['poster'] = new FormattableMarkup('<div class="rw-user-posts-poster rw-user-posts-poster--@type">@label</div>', [
        '@type' => $poster_type,
        '@label' => $poster_type == 'me' ? $this->t('Me') : $this->t('Colleague'),
      ]);

      $sources = [];
      foreach ($entity->field_source->referencedEntities() as $source) {
        $source_label = $source->field_shortname->value ?? $source->label();
        $sources[] = $source->toLink($source_label)->toString();
      }
      if (count($sources) > 1) {
        $cells['source'] = [
          '#theme' => 'item_list',
          '#items' => $sources,
        ];
      }
      else {
        $cells['source'] = reset($sources);
      }

      $cells['title'] = $entity->toLink()->toString();

      // The reliefweb-moderation-table template expects a date property.
      $cells['date']['date'] = $this->getEntityCreationDate($entity);

      if ($this->hasDeadlineColumn()) {
        $cells['deadline'] = $this->getDeadlineCell($entity);
      }

      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'title',
      'status',
      'created',
    ]);

    // Dedicated fields in UserPostsPageFilterForm; not omnibox.
    unset($definitions['title']['form'], $definitions['created']['form']);

    $definitions['nid'] = [
      'type' => 'property',
      'field' => 'nid',
      'label' => $this->t('Id'),
      'shortcut' => 'i',
      'widget' => 'search',
    ];

    // Built as a dedicated autocomplete select in UserPostsPageFilterForm.
    $definitions['source'] = [
      'type' => 'field',
      'column' => 'target_id',
      'field' => 'field_source',
      'label' => $this->t('Source'),
      'operator' => 'OR',
      'widget' => 'autocomplete',
    ];

    if ($this->hasDeadlineColumn()) {
      $definitions['deadline'] = [
        'type' => 'property',
        'field' => 'deadline',
        'label' => $this->t('Deadline'),
        'widget' => 'datepicker',
        'join_callback' => 'joinDeadline',
        'condition_callback' => 'conditionDeadline',
        'operator' => 'AND',
      ];
    }

    $definitions['poster'] = [
      'type' => 'property',
      'field' => 'uid',
      'label' => $this->t('Posted by'),
      'shortcut' => 'a',
      'form' => 'other',
      'operator' => 'AND',
      'values' => [
        'me' => $this->t('Me'),
        'colleagues' => $this->t('Colleagues'),
      ],
      // Handled in ::filterQuery().
      'join_callback' => '',
      'condition_callback' => '',
    ];

    return $definitions;
  }

  /**
   * Get source options for the My posts source filter select.
   *
   * @return array
   *   Associative array with:
   *   - options: tid => label
   *   - attributes: tid => attribute map (e.g. data-shortname)
   */
  public function getSourceFilterOptions(): array {
    $user = $this->getUser();
    if (empty($user)) {
      return [
        'options' => [],
        'attributes' => [],
      ];
    }

    $bundle = $this->getBundle();
    $min_right = $this->currentUser->hasPermission('edit any job content') ? 0 : 1;
    $tids = [];

    foreach ($this->userPostingRightsManager->getUserPostingRights($user, []) as $tid => $rights) {
      if (isset($rights[$bundle]) && (int) $rights[$bundle] > $min_right) {
        $tids[(int) $tid] = (int) $tid;
      }
    }

    // Also include sources from the user's own posts of this type.
    $source_table = $this->getFieldTableName('node', 'field_source');
    $source_field = $this->getFieldColumnName('node', 'field_source', 'target_id');
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin($source_table, 'source', 'source.entity_id = n.nid');
    $query->addField('source', $source_field, 'tid');
    $query->condition('n.uid', $user->id());
    $query->condition('n.type', $bundle);
    $query->distinct();
    foreach ($query->execute() as $row) {
      $tid = (int) $row->tid;
      if ($tid > 0) {
        $tids[$tid] = $tid;
      }
    }

    if (empty($tids)) {
      return [
        'options' => [],
        'attributes' => [],
      ];
    }

    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    $options = [];
    $attributes = [];
    foreach ($terms as $tid => $term) {
      $options[$tid] = $term->label();
      if (!$term->get('field_shortname')->isEmpty()) {
        $attributes[$tid]['data-shortname'] = $term->get('field_shortname')->value;
      }
    }
    natcasesort($options);

    return [
      'options' => $options,
      'attributes' => $attributes,
    ];
  }

  /**
   * Deadline join callback.
   *
   * @see ::joinField()
   */
  protected function joinDeadline(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    $bundle = $this->getBundle();
    if ($bundle === 'job') {
      $table = $this->getFieldTableName('node', 'field_job_closing_date');
      $field_name = $this->getFieldColumnName('node', 'field_job_closing_date', 'value');
    }
    elseif ($bundle === 'training') {
      $table = $this->getFieldTableName('node', 'field_registration_deadline');
      $field_name = $this->getFieldColumnName('node', 'field_registration_deadline', 'value');
    }
    else {
      return '';
    }

    $table_alias = $query->leftJoin($table, $table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");
    $expression = "{$table_alias}.{$field_name}";
    $query->addExpression($expression, 'deadline');
    return $expression;
  }

  /**
   * Condition callback for the deadline.
   *
   * @see \Drupal\reliefweb_moderation\ModerationServiceBase::addFilterCondition()
   */
  protected function conditionDeadline(array $definition, Condition $condition, $fields, $value, $operator) {
    if (!is_array($value)) {
      if ($operator === '>=') {
        $start = intval($value);
        $end = NULL;
      }
      else {
        $start = NULL;
        $end = intval($value);
      }
    }
    else {
      $start = isset($value[0]) ? intval($value[0]) : NULL;
      $end = isset($value[1]) ? intval($value[1]) : NULL;
    }

    if (empty($start) && empty($end)) {
      return;
    }
    elseif (empty($start)) {
      $end += 86399;
      $condition->where("UNIX_TIMESTAMP({$fields}) <= {$end}");
    }
    elseif (empty($end)) {
      $condition->where("UNIX_TIMESTAMP({$fields}) >= {$start}");
    }
    else {
      $end += 86399;
      $condition->where("UNIX_TIMESTAMP({$fields}) BETWEEN {$start} AND {$end}");
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function filterQuery(Select $query, array $filters = []) {
    $user = $this->getUser();

    // This should never happen, but just in case make sure we don't return
    // any results if there is no user.
    if (empty($user)) {
      $query->alwaysFalse();
      return;
    }

    $filters['poster'] = array_filter($filters['poster'] ?? []);
    // Accept legacy "other" key from old bookmarked URLs.
    if (!empty($filters['poster']['other'])) {
      $filters['poster']['colleagues'] = $filters['poster']['other'];
      unset($filters['poster']['other']);
    }
    $posted_by_me = empty($filters['poster']) || !empty($filters['poster']['me']);
    $posted_by_colleagues = empty($filters['poster']) || !empty($filters['poster']['colleagues']);
    unset($filters['poster']);

    parent::filterQuery($query, $filters);

    $bundle = $this->getBundle();
    $can_see_colleagues = static::hasAffiliatedAccess($user, $bundle);

    // Without affiliated access, colleagues' posts are never included.
    if (!$can_see_colleagues) {
      $posted_by_colleagues = FALSE;
      if (!$posted_by_me) {
        $query->alwaysFalse();
        return;
      }
    }

    $allowed = [];
    $blocked = [];

    foreach ($this->userPostingRightsManager->getUserPostingRights($user, []) as $tid => $rights) {
      if (!isset($rights[$bundle])) {
        continue;
      }
      if ($rights[$bundle] > 1) {
        if ($can_see_colleagues) {
          $allowed[$tid] = $tid;
        }
      }
      elseif ($rights[$bundle] == 1) {
        $blocked[$tid] = $tid;
      }
    }

    if (!$posted_by_me && empty($allowed)) {
      $query->alwaysFalse();
      return;
    }

    $node_table = $this->getEntityTypeDataTable('node');
    $node_id_field = $this->getEntityTypeIdField('node');
    $source_table = $this->getFieldTableName('node', 'field_source');
    $source_field = $this->getFieldColumnName('node', 'field_source', 'target_id');

    $node_table_alias = '';
    $source_table_alias = '';
    foreach ($query->getTables() as $alias => $info) {
      if (isset($info['table'])) {
        if (empty($node_table_alias) && $info['table'] === $node_table) {
          $node_table_alias = $alias;
        }
        elseif (empty($source_table_alias) && $info['table'] === $source_table) {
          $source_table_alias = $alias;
        }
      }
    }

    if (empty($node_table_alias)) {
      $base_table_alias = $this->getQueryBaseTableAlias($query);
      $node_table_alias = $query->innerJoin($node_table, $node_table, "%alias.{$node_id_field} = {$base_table_alias}.content_entity_id");
    }

    if (empty($source_table_alias)) {
      $source_table_alias = $query->leftJoin($source_table, $source_table, "%alias.entity_id = {$node_table_alias}.{$node_id_field}");
    }

    $poster_condition = NULL;

    // Posted by me only.
    if ($posted_by_me && !$posted_by_colleagues) {
      $query->condition($node_table_alias . '.uid', $user->id(), '=');
    }
    // Posted by me or colleagues.
    elseif ($posted_by_me && $posted_by_colleagues) {
      $poster_condition = $query->orConditionGroup();
      $poster_condition->condition($node_table_alias . '.uid', $user->id(), '=');
    }
    // Posted by colleagues only.
    else {
      $poster_condition = $query->andConditionGroup();
      $poster_condition->condition($node_table_alias . '.uid', $user->id(), '<>');
    }

    // Posts from organizations the user is allowed to post for.
    if (!empty($poster_condition)) {
      $allowed_condition = $query->orConditionGroup();
      if (!empty($allowed)) {
        $condition = $query->andConditionGroup()
          ->condition($source_table_alias . '.bundle', $bundle, '=')
          ->condition($source_table_alias . '.' . $source_field, array_keys($allowed), 'IN');
        $allowed_condition->condition($condition);
      }
      if ($allowed_condition->count() > 0) {
        $poster_condition->condition($allowed_condition);
      }
      elseif (!$posted_by_me) {
        // Colleagues selected but no allowed sources.
        $query->alwaysFalse();
        return;
      }
      $query->condition($poster_condition);
    }

    // Filter out docs with sources the user is blocked for, except editors.
    if (!empty($blocked) && !$this->currentUser->hasPermission('edit any job content')) {
      $type_source_alias = $source_table_alias . '_' . $bundle;
      $type_source_join = "%alias.entity_id = {$node_table_alias}.{$node_id_field} AND %alias.bundle = :type AND %alias.{$source_field} IN (:sources[])";
      $query->leftJoin($source_table, $type_source_alias, $type_source_join, [
        ':type' => $bundle,
        ':sources[]' => array_keys($blocked),
      ]);
      $query->isNull($type_source_alias . '.entity_id');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function wrapQuery(Select $query, $limit = 30) {
    if (!$this->hasDeadlineColumn()) {
      return parent::wrapQuery($query, $limit);
    }

    $info = $this->getOrderInformation();
    $sort_direction = $info['sort'] ?? 'desc';

    $deadline_alias = '';
    if (isset($info['order']) && $info['order'] === 'deadline') {
      foreach ($query->getExpressions() as $expression) {
        if (($expression['alias'] ?? '') === 'deadline') {
          $deadline_alias = $expression['alias'];
          break;
        }
      }

      if (empty($deadline_alias)) {
        $entity_type_id = $this->getEntityTypeId();
        $entity_base_table = $this->getEntityTypeDataTable($entity_type_id);
        $entity_id_field = $this->getEntityTypeIdField($entity_type_id);
        $definition = $this->getFilterDefinitions()['deadline'];
        $deadline_alias = $this->joinDeadline($query, $definition, $entity_type_id, $entity_base_table, $entity_id_field);
      }
    }

    $wrapper = parent::wrapQuery($query, $limit);

    if (!empty($deadline_alias)) {
      $existing_order = &$wrapper->getOrderBy();
      $existing_order = [];
      $wrapper->addField('subquery', 'deadline');
      $wrapper->orderBy('subquery.deadline', $sort_direction);
    }

    return $wrapper;
  }

  /**
   * Get the user ID for this my posts page.
   *
   * @return int|null
   *   User ID or NULL if the user couldn't be retrieved.
   */
  protected function getUserId() {
    $user = $this->getUser();
    return !empty($user) ? $user->id() : NULL;
  }

  /**
   * Get the user entity for this my posts page.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   User entity or NULL if the user couldn't be retrieved.
   */
  protected function getUser() {
    return $this->routeMatch->getParameter('user');
  }

}
