<?php

namespace Drupal\reliefweb_sync_orgs\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Moderation service for the report nodes.
 */
class ReliefWebSyncModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
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
  ) {
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->pagerManager = $pager_manager;
    $this->pagerParameters = $pager_parameters;
    $this->requestStack = $request_stack;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return '';
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
    return $this->t('ReliefWeb Organization Sync');
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    return [
      'imported-item' => [
        'label' => $this->t('Imported item'),
      ],
      'data' => [
        'label' => $this->t('Source term'),
      ],
      'importer' => [
        'label' => $this->t('Importer'),
      ],
      'status' => [
        'label' => $this->t('Status'),
      ],
      'source' => [
        'label' => $this->t('Source'),
      ],
      'changed' => [
        'label' => $this->t('Import changed'),
      ],
      'node_created' => [
        'label' => $this->t('Source term created'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(array $results) {
    if (empty($results['records'])) {
      return [];
    }

    $records = $results['records'];

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($records as $record) {
      $entity = $entities[$record['tid']] ?? NULL;

      $cells = [];

      $id = implode('-', [
        $record['source'] ?? '',
        $record['id'] ?? '',
      ]);

      $cells['imported-item'] = $record['id'] ?? '';

      // Entity data cell.
      $data = [];
      if ($entity) {
        // Title.
        $data['title'] = $entity->toLink()->toString();

        $data['details'] = [];

        // Revision information.
        $data['revision'] = $this->getEntityRevisionData($entity);

        // Filter out empty data.
        $cells['data'] = array_filter($data);
      }
      else {
        // No entity found, show importer info.
        $extra_items = [];
        if (!empty($record['message'])) {
          $extra_items[] = $this->t('Message: @message', [
            '@message' => $record['message'] ?? '',
          ]);
        }

        if (isset($record['csv_item'])) {
          foreach ($record['csv_item'] as $label => $item) {
            if (!in_array($label, ['name', 'display_name', 'org_acronym', 'org abbreviation', 'org name'])) {
              continue;
            }

            // Convert the label to a human-readable format.
            $label = ucfirst(str_replace('_', ' ', $label));
            $extra_items[] = $label . ': ' . substr($item ?? '', 0, 250) . (strlen($item) > 250 ? '...' : '');
          }
        }

        $data['extra'] = [
          '#theme' => 'item_list',
          '#items' => $extra_items,
        ];

        // Filter out empty data.
        $cells['info'] = array_filter($data);
      }

      $cells['importer'] = $record['importer'] ?? $this->t('Unknown');
      $cells['status']['label'] = [
        '#type' => 'markup',
        '#markup' => $record['status'],
      ];

      $cells['source'] = $record['source'];

      // Date cell.
      $cells['changed'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => $this->dateFormatter->format($record['changed'], 'custom', 'd/m/Y H:i:s'),
        ],
      ];

      // Report date cell.
      if ($entity) {
        $cells['node_created'] = [
          'data' => [
            '#type' => 'markup',
            '#markup' => $this->dateFormatter->format($record['created'], 'custom', 'd/m/Y H:i:s'),
          ],
        ];
      }

      $rows[] = [
        'id' => 'row-' . $id,
        'data' => $cells,
      ];
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'success' => $this->t('Success'),
      'skipped' => $this->t('Skipped'),
      'error' => $this->t('Error'),
      'duplicate' => $this->t('Duplicate'),
      'queued' => $this->t('Queued'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefaultStatuses() {
    $statuses = $this->getFilterStatuses();
    unset($statuses['success']);
    unset($statuses['duplicate']);
    return array_keys($statuses);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
    ]);

    $definitions['source'] = [
      'form' => 'source',
      'type' => 'field',
      'label' => $this->t('source'),
      'field' => 'source',
      'column' => 'value',
      'operator' => 'OR',
      'values' => $this->getSourceValues(),
    ];

    return $definitions;
  }

  /**
   * Get source values from database.
   */
  protected function getSourceValues() {
    $query = $this->database->select('reliefweb_sync_orgs_records', 'r')
      ->fields('r', ['source'])
      ->condition('r.source', NULL, 'IS NOT NULL')
      ->distinct()
      ->orderBy('source');

    $results = $query->execute()
      ?->fetchCol();

    $values = array_combine($results, $results);

    return $values;
  }

  /**
   * Execute the query to get the moderation table rows' data.
   *
   * @param array $filters
   *   User selected filter.
   * @param int $limit
   *   Number of items to retrieve.
   *
   * @return array
   *   Associative array with the the list of entities matching the query,
   *   the totals of entities per status and the pager.
   */
  protected function executeQuery(array $filters, $limit = 30) {
    $data = [];

    $query = $this->database->select('reliefweb_sync_orgs_records', 'r')
      ->fields('r')
      ->distinct();

    // We use MySQL variables to store the count of entities per status.
    // This is much faster than executing several count queries with the same
    // filters.
    $variables = [];
    foreach (array_keys($this->getStatuses()) as $status) {
      // Statuses are machine names so it's safe to use them directly.
      $variables['@' . strtr($status, '-', '_')] = $status;
    }

    // Prepare the case expression to increment the variables.
    $cases = '';
    foreach ($variables as $variable => $status) {
      $cases .= "WHEN '{$status}' THEN {$variable} := {$variable} + 1 ";
    }

    // Add the expression to increment the counters.
    $query->addExpression("CASE r.status {$cases} END");

    // Filter the query with the form filters.
    $this->filterQuery($query, $filters);

    // Wrap the query in a parent query to which the ordering and limiting is
    // applied.
    //
    // The point here is that we have the filtered query return all the results
    // populating the counter variables doing so and the wrapper query will
    // return only a subset of the data according to the current page, limit
    // and sort criterium.
    $wrapper = $this->wrapQuery($query, $limit);

    $variables_keys = array_keys($variables);

    // Initialize the counters.
    $this->getDatabase()
      ->query('SET ' . implode(' := 0, ', $variables_keys) . ' := 0');

    // Retrieve the entity ids.
    $ids = $wrapper
      ->execute()
      ?->fetchCol() ?? [];

    // Retrieve the counters.
    $totals = $this->getDatabase()
      ->query('SELECT ' . implode(', ', $variables_keys))
      ?->fetchAssoc() ?? [];

    // Load the records.
    if (!empty($ids)) {
      $data['records'] = $this->getImportRecords($ids);
      $entity_ids = [];
      foreach ($data['records'] as $record) {
        if (!empty($record['tid'])) {
          $entity_ids[] = $record['tid'];
        }
      }
      $data['entities'] = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadMultiple($entity_ids);
    }
    else {
      $data['records'] = [];
      $data['entities'] = [];
    }

    // Parse the total of entities per status.
    $total = 0;
    foreach ($totals as $name => $number) {
      if (isset($variables[$name])) {
        $number = intval($number, 10);
        $data['totals'][$variables[$name]] = $number;
        $total += $number;
      }
    }
    $data['totals']['total'] = $total;

    // Initialize the pager with the total.
    $data['pager'] = $this->pagerManager->createPager($total, $limit);

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  protected function wrapQuery(Select $query, $limit = 30) {
    $sort_direction = 'desc';

    // Wrap the query.
    $wrapper = $this->getDatabase()->select($query, 'subquery');
    $wrapper->addField('subquery', 'id', 'tid');
    $wrapper->addField('subquery', 'changed', 'sort');

    // Keep track of the subquery.
    // @todo review if that's still necessary.
    $wrapper->addMetaData('subquery', $query);

    // Add the sort property to the wrapper query.
    $wrapper->orderBy('subquery.created', $sort_direction);

    // Set the query range to the wrapper.
    $page = $this->pagerManager->findPage();
    $wrapper->distinct()->range($page * $limit, $limit);

    return $wrapper;
  }

  /**
   * {@inheritdoc}
   */
  protected function filterQuery(Select $query, array $filters = []) {
    // Merge with the service inner filters.
    if (!empty($this->filters)) {
      $filters = array_merge_recursive($this->filters, $filters);
    }

    // Skip if there are no filters.
    if (empty($filters)) {
      return;
    }

    // Available widgets for the filter form.
    $widgets = [
      'autocomplete',
      'datepicker',
      'search',
      'fieldnotset',
      'none',
    ];

    // Parse filters.
    $available_filters = $this->getFilterDefinitions();
    foreach ($filters as $name => $values) {
      if (isset($available_filters[$name]['type'], $available_filters[$name]['field'])) {
        $definition = $available_filters[$name];
        $widget = !empty($definition['widget']) ? $definition['widget'] : NULL;

        // Check the widget.
        if (isset($widget) && !in_array($widget, $widgets)) {
          continue;
        }

        // Parse the selected values, keeping only the valid ones.
        $values = $this->parseFilterValues($values, $definition['values'] ?? NULL);

        // Add the conditions.
        if (count($values) > 0) {
          // Special case for the status filter which is against the base table.
          if ($name === 'status') {
            $query->condition('r.status', $values, 'IN');
            continue;
          }
          elseif ($name === 'source') {
            $query->condition('r.source', $values, 'IN');
            continue;
          }
        }
      }
    }
  }

  /**
   * Retrieve import records by uuid.
   *
   * @return array
   *   An array of import records keyed by the import item UUID.
   */
  protected function getImportRecords(array $ids): array {
    $records = $this->database->select('reliefweb_sync_orgs_records', 'r')
      ->fields('r')
      ->condition('id', $ids, 'IN')
      ->orderBy('created', 'DESC')
      ->execute()
      ?->fetchAll(FetchAs::Associative) ?? [];

    // Deserialize the extra field.
    foreach ($records as &$record) {
      if (isset($record['csv_item'])) {
        $record['csv_item'] = json_decode($record['csv_item'], TRUE);
      }
    }

    return $records;
  }

}
