<?php

namespace Drupal\reliefweb_user_posts\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Database\Query\Select;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Moderation service for the report nodes.
 */
class UserPostsService extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return [
      'job',
      'training',
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
      'on_hold' => $this->t('on-hold'),
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
        'type' => '',
        'specifier' => 'moderation_state',
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

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      // Edit link + status cell.
      $cells['id'] = $entity->id();
      $cells['type'] = $entity->bundle();
      $cells['status'] = new FormattableMarkup('<div class="rw-moderation-status" data-moderation-status="@status">@label</div>', [
        '@status' => $entity->getModerationStatus(),
        '@label' => $entity->getModerationStatusLabel(),
      ]);
      $cells['poster'] = $this->currentUser->id() == $entity->getOwner()->id() ? $this->t('me') : $this->t('other');
      $cells['source'] = '';
      $cells['title'] = $entity->toLink()->toString();
      $cells['date'] = $this->getEntityCreationDate($entity);

      $cells['deadline'] = '';
      if ($entity->field_registration_deadline) {
        if ($entity->field_registration_deadline->value == 'Array') {
          $cells['deadline'] = $this->t('Ongoing');
        }
        else {
          $cells['deadline'] = $this->formatDate($entity->field_registration_deadline->value);
        }
      }
      elseif ($entity->field_job_closing_date) {
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
      'author',
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
    $definitions['bundle'] = [
      'type' => 'property',
      'field' => 'type',
      'label' => $this->t('Type'),
      'shortcut' => 'ty',
      'form' => 'other',
      'values' => [
        'job' => $this->t('Job'),
        'training' => $this->t('Training'),
      ],
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

    $definitions['author2'] = [
      'type' => 'property',
      'field' => 'uid',
      'label' => $this->t('Posted by'),
      'shortcut' => 'a',
      'form' => 'other',
      'join_callback' => 'joinPoster',
      'operator' => 'OR',
      'values' => [
        'me' => $this->t('Me'),
        'other' => $this->t('Other'),
      ],
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
    $all_sources = parent::getTaxonomyTermAutocompleteSuggestions($filter, $term, $conditions, $replacements);

    // Filter sources.
    $sources = [];
    foreach ($all_sources as $source) {
      $sources[] = $source->value;
    }

    $rights = UserPostingRightsHelper::getUserPostingRights($this->currentUser, $sources);

    $allowed_sources = [];
    foreach ($all_sources as $source) {
      if (isset($rights[$source->value])) {
        if (isset($rights[$source->value]['job']) && $rights[$source->value]['job'] > 1) {
          $allowed_sources[] = $source;
        }
        elseif (isset($rights[$source->value]['training']) && $rights[$source->value]['training'] > 1) {
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

    // Add joins.
    $table_alias_job = $query->leftJoin($table_job, $table_job, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");
    $table_alias_training = $query->leftJoin($table_training, $table_training, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");

    // Add deadline field.
    $query->addExpression("COALESCE({$table_alias_job}.{$field_name_job}, {$table_alias_training}.{$field_name_training})", 'deadline');
    return "COALESCE({$table_alias_job}.{$field_name_job}, {$table_alias_training}.{$field_name_training})";
  }

  /**
   * Condition callback.
   */
  protected function conditionDeadline($definition, $base, $fields, $value, $operator) {
    $base->where("UNIX_TIMESTAMP({$fields}) {$operator} {$value[0]} AND {$value[1]}");
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

}
