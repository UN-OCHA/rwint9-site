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
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Moderation service for the report nodes.
 */
class UserPostsService extends ModerationServiceBase {

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
      $string_translation
    );
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return [
      'job',
      'training',
      'report',
    ];
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
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'draft' => $this->t('draft'),
      'pending' => $this->t('pending'),
      'published' => $this->t('published'),
      'on-hold' => $this->t('on-hold'),
      'refused' => $this->t('refused'),
      'expired' => $this->t('expired'),
      'duplicate' => $this->t('duplicate'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    return [
      'id' => [
        'label' => $this->t('Id'),
        'type' => 'property',
        'specifier' => 'nid',
        'sortable' => TRUE,
      ],
      'type' => [
        'label' => $this->t('Type'),
        'type' => 'property',
        'specifier' => 'type',
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
      'deadline' => [
        'label' => $this->t('Deadline'),
        'type' => 'custom',
        'specifier' => 'deadline',
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

    // Retrieve the user for this page.
    $user_id = $this->getUserId();

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      $cells['id'] = $entity->id();
      $cells['type'] = $entity->bundle();
      $cells['status'] = new FormattableMarkup('<div class="rw-moderation-status" data-moderation-status="@status">@label</div>', [
        '@status' => $entity->getModerationStatus(),
        '@label' => $entity->getModerationStatusLabel(),
      ]);

      // Me if the entity author is the user for this My Posts page.
      $cells['poster'] = $user_id == $entity->getOwnerId() ? $this->t('me') : $this->t('other');

      // Jobs can have 1 source, training can have several.
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

      // The `reliefweb-moderation-table.hml.twig` template expects an array or
      // object with a date property for the "date" cells.
      $cells['date']['date'] = $this->getEntityCreationDate($entity);

      // Registration deadline for training or closing date for jobs.
      if ($entity->bundle() === 'training') {
        if ($entity->field_registration_deadline->isEmpty()) {
          $cells['deadline'] = $this->t('Ongoing');
        }
        else {
          $cells['deadline'] = $this->formatDate($entity->field_registration_deadline->value);
        }
      }
      elseif ($entity->bundle() === 'job') {
        $cells['deadline'] = $this->formatDate($entity->field_job_closing_date->value);
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
      'source',
    ]);

    // Filter by node id.
    $definitions['nid'] = [
      'type' => 'property',
      'field' => 'nid',
      'label' => $this->t('Id'),
      'shortcut' => 'i',
      'form' => 'omnibox',
      'widget' => 'search',
    ];

    // Filter by bundle.
    $allowed_bundles = [
      'job' => $this->t('Job'),
      'training' => $this->t('Training'),
    ];
    if ($this->currentUser->hasPermission('create report content')) {
      $allowed_bundles['report'] = $this->t('Report');
    }

    $definitions['bundle'] = [
      'type' => 'property',
      'field' => 'type',
      'label' => $this->t('Type'),
      'shortcut' => 'ty',
      'form' => 'other',
      'operator' => 'OR',
      'values' => $allowed_bundles,
    ];

    // Limit sources.
    $definitions['source']['autocomplete_callback'] = 'getSourcesTheUserHasPostedFor';

    // Filter on deadline.
    $definitions['deadline'] = [
      'type' => 'property',
      'field' => 'deadline',
      'label' => $this->t('Deadline'),
      'form' => 'omnibox',
      'widget' => 'datepicker',
      'join_callback' => 'joinDeadline',
      'condition_callback' => 'conditionDeadline',
      'operator' => 'AND',
    ];

    // Filter the author of the nodes.
    $definitions['poster'] = [
      'type' => 'property',
      'field' => 'uid',
      'label' => $this->t('Posted by'),
      'shortcut' => 'a',
      'form' => 'other',
      'operator' => 'AND',
      'values' => [
        'me' => $this->t('Me'),
        'other' => $this->t('Other'),
      ],
      // This is handled in ::filterQuery().
      'join_callback' => '',
      'condition_callback' => '',
    ];

    return $definitions;
  }

  /**
   * Get taxonomy term suggestions for the given term.
   *
   * @param string $filter
   *   Filter name.
   * @param string $term
   *   Autocomplete search term.
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $replacements
   *   Value replacements for the search condition.
   *
   * @return array
   *   List of suggestions. Each suggestion is an object with a value, label
   *   and optional abbreviation (abbr).
   */
  protected function getSourcesTheUserHasPostedFor($filter, $term, $conditions, array $replacements) {
    $user = $this->getUser();
    if (empty($user)) {
      return [];
    }

    $all_sources = parent::getTaxonomyTermAutocompleteSuggestions($filter, $term, $conditions, $replacements);

    // Filter sources.
    $sources = [];
    foreach ($all_sources as $source) {
      $sources[] = $source->value;
    }

    $rights = UserPostingRightsHelper::getUserPostingRights($user, $sources);

    // For editors, we allow returning sources that are blocked for the user.
    $min_right = $this->currentUser->hasPermission('edit any job content') ? 0 : 1;

    $allowed_sources = [];
    foreach ($all_sources as $source) {
      if (isset($rights[$source->value])) {
        if (isset($rights[$source->value]['job']) && $rights[$source->value]['job'] > $min_right) {
          $allowed_sources[] = $source;
        }
        elseif (isset($rights[$source->value]['training']) && $rights[$source->value]['training'] > $min_right) {
          $allowed_sources[] = $source;
        }
        elseif (isset($rights[$source->value]['report']) && $rights[$source->value]['report'] > $min_right) {
          $allowed_sources[] = $source;
        }
      }
    }

    return $allowed_sources;
  }

  /**
   * Deadline join callback.
   *
   * @see ::joinField()
   */
  protected function joinDeadline(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    $table_job = $this->getFieldTableName('node', 'field_job_closing_date');
    $field_name_job = $this->getFieldColumnName('node', 'field_job_closing_date', 'value');

    $table_training = $this->getFieldTableName('node', 'field_registration_deadline');
    $field_name_training = $this->getFieldColumnName('node', 'field_registration_deadline', 'value');

    // Join the job and training deadline tables.
    $table_alias_job = $query->leftJoin($table_job, $table_job, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");
    $table_alias_training = $query->leftJoin($table_training, $table_training, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");

    // Expression to get the deadline value.
    $expression = "COALESCE({$table_alias_job}.{$field_name_job}, {$table_alias_training}.{$field_name_training})";

    // Add deadline field.
    $query->addExpression($expression, 'deadline');

    // We have to return the expression instead of its alias for the condition
    // in ::conditionDeadline() to work.
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

    // Should not happen.
    if (empty($start) && empty($end)) {
      return;
    }
    // The $fields variable is the expression from ::joinDeadline() so we
    // need to use Condition::where() to add the condition to avoid Drupal
    // from stripping characters from the expression.
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
    // any restults if there is no user.
    if (empty($user)) {
      $query->alwaysFalse();
      return;
    }

    // Extract the poster filter, we'll handle it below.
    $filters['poster'] = array_filter($filters['poster'] ?? []);
    $posted_by_me = empty($filters['poster']) || !empty($filters['poster']['me']);
    $posted_by_other = empty($filters['poster']) || !empty($filters['poster']['other']);
    unset($filters['poster']);

    // Apply any other filtering.
    parent::filterQuery($query, $filters);

    // Retrieve any filtering on the bundles so that we can limit the conditions
    // on the allowed and blocked sources.
    $types = [];
    $filters['bundle'] = array_filter($filters['bundle'] ?? []);
    if (empty($filters['bundle']) || !empty($filters['bundle']['job'])) {
      $types[] = 'job';
    }
    if (empty($filters['bundle']) || !empty($filters['bundle']['training'])) {
      $types[] = 'training';
    }
    if (empty($filters['bundle']) || !empty($filters['bundle']['report'])) {
      $types[] = 'report';
    }

    // Get the user rights keyed by source ids and store the ones
    // for which the user is allowed to post.
    $allowed = [];
    $blocked = [];
    foreach (UserPostingRightsHelper::getUserPostingRights($user, []) as $tid => $rights) {
      foreach ($types as $type) {
        if (isset($rights[$type])) {
          if ($rights[$type] > 1) {
            $allowed[$type][$tid] = $tid;
          }
          elseif ($rights[$type] == 1) {
            $blocked[$type][$tid] = $tid;
          }
        }
      }
    }

    // We cannot retrieve any content if the user is not allowed for any source
    // and "other" is selected as filter so bail out.
    if (!$posted_by_me && empty($allowed)) {
      $query->alwaysFalse();
      return;
    }

    $node_table = $this->getEntityTypeDataTable('node');
    $node_id_field = $this->getEntityTypeIdField('node');
    $source_table = $this->getFieldTableName('node', 'field_source');
    $source_field = $this->getFieldColumnName('node', 'field_source', 'target_id');

    // Retrieve the entity table (node) and the source field table if joined
    // already.
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

    // Join the node table if it was not already.
    if (empty($node_table_alias)) {
      // The base table alias for the query is the content moderation table.
      $base_table_alias = $this->getQueryBaseTableAlias($query);
      $node_table_alias = $query->innerJoin($node_table, $node_table, "%alias.{$node_id_field} = {$base_table_alias}.content_entity_id");
    }

    // Join the source table if it was not already.
    if (empty($source_table_alias)) {
      // Left join because to be able to retrieve posts without a source.
      $source_table_alias = $query->leftJoin($source_table, $source_table, "%alias.entity_id = {$node_table}.{$node_id_field}");
    }

    if ($posted_by_me and !$posted_by_other) {
      $query->condition($node_table_alias . '.uid', $user->id(), '=');
    }
    elseif ($posted_by_other and !$posted_by_me) {
      $query->condition($node_table_alias . '.uid', $user->id(), '<>');
    }

    $poster_condition = NULL;

    // Posted by me only.
    if ($posted_by_me && !$posted_by_other) {
      $query->condition($node_table_alias . '.uid', $user->id(), '=');
    }
    // Posted by me or other.
    elseif ($posted_by_me && $posted_by_other) {
      $poster_condition = $query->orConditionGroup();
      $poster_condition->condition($node_table_alias . '.uid', $user->id(), '=');
    }
    // Poster by other.
    else {
      $poster_condition = $query->andConditionGroup();
      $poster_condition->condition($node_table_alias . '.uid', $user->id(), '<>');
    }

    // Posts from the organizations the user is allowed to post for.
    if (!empty($poster_condition)) {
      $allowed_condition = $query->orConditionGroup();
      foreach ($types as $type) {
        if (!empty($allowed[$type])) {
          // Ex: "(type = 'training' AND source_id IN (...))".
          $condition = $query->andConditionGroup()
            ->condition($source_table_alias . '.bundle', $type, '=')
            ->condition($source_table_alias . '.' . $source_field, array_keys($allowed[$type]), 'IN');
          $allowed_condition->condition($condition);
        }
      }
      if ($allowed_condition->count() > 0) {
        $poster_condition->condition($allowed_condition);
      }
      $query->condition($poster_condition);
    }

    // Filter out docs with sources the user is blocked for, except if the
    // current user is an Editor so that the user can see all the posts.
    if (!empty($blocked) && !$this->currentUser->hasPermission('edit any job content')) {
      foreach ($types as $type) {
        if (!empty($blocked[$type])) {
          $type_source_alias = $source_table_alias . '_' . $type;
          $type_source_join = "%alias.entity_id = {$node_table}.{$node_id_field} AND %alias.bundle = :type AND %alias.{$source_field} IN (:sources[])";
          $query->leftJoin($source_table, $type_source_alias, $type_source_join, [
            ':type' => $type,
            ':sources[]' => array_keys($blocked[$type]),
          ]);
          $query->isNull($type_source_alias . '.entity_id');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function wrapQuery(Select $query, $limit = 30) {
    // Get the order information.
    $info = $this->getOrderInformation();
    $sort_direction = $info['sort'] ?? 'desc';

    // Special handling of the headline sorting.
    $deadline_alias = '';
    if (isset($info['order']) && $info['order'] === 'deadline') {
      // Check if the join for the headline field was already performed.
      foreach ($query->getExpressions() as $expression) {
        if (strpos($expression['alias'], 'headline') === 0) {
          $deadline_alias = $expression['alias'];
          break;
        }
      }

      // If not, join the tables.
      if (empty($deadline_alias)) {
        // Entity information.
        $entity_type_id = $this->getEntityTypeId();
        $entity_base_table = $this->getEntityTypeDataTable($entity_type_id);
        $entity_id_field = $this->getEntityTypeIdField($entity_type_id);

        // Filter definition.
        $definition = $this->getFilterDefinitions()['deadline'];

        // Join the deadline tables.
        $deadline_alias = $this->joinDeadline($query, $definition, $entity_type_id, $entity_base_table, $entity_id_field);
      }
    }

    // Let the parent wrap the query.
    $wrapper = parent::wrapQuery($query, $limit);

    // Add the sort property to the wrapper query.
    if (!empty($deadline_alias)) {
      // Clear existing order.
      $existing_order = &$wrapper->getOrderBy();
      $existing_order = [];

      // Add field and order.
      $wrapper->addField('subquery', 'deadline');
      $wrapper->orderBy("subquery.deadline", $sort_direction);
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
