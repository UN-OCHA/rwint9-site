<?php

namespace Drupal\reliefweb_import\Service;

use Drupal\Core\Database\Query\Select;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginManager;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Moderation service for the report nodes.
 */
class ReliefWebImporterModeration extends ModerationServiceBase {

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
    protected ReliefWebImporterPluginManager $pluginManager,
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
    return $this->t('ReliefWeb Importer (@stats)', [
      '@stats' => Link::fromTextAndUrl(
        $this->t('Statistics'),
        Url::fromRoute('reliefweb_import.reliefweb_importer.stats')
      )->toString(),
    ]);
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
        'label' => $this->t('Report'),
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
        'label' => $this->t('Report created'),
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

    $status_types = reliefweb_import_status_type_values();
    $editorial_flows = reliefweb_import_editorial_flow_values();

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($records as $record) {
      $entity = $entities[$record['entity_id']] ?? NULL;

      $cells = [];

      $imported_item_id = $record['imported_item_id'] ?? '';
      $id_parts = explode('/', $imported_item_id);
      $short_id = end($id_parts);
      $cells['imported-item'] = [
        'data' => [
          '#type' => 'link',
          '#title' => $short_id,
          '#url' => Url::fromUri($record['imported_item_url']),
          '#attributes' => [
            'title' => $record['imported_item_id'],
          ],
        ],
      ];

      // Entity data cell.
      $data = [];
      if ($entity) {
        // Title.
        $data['title'] = $entity->toLink()->toString();

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
            $details['format'][] = $item_title;
          }
        }
        // Language.
        $details['language'] = [];
        foreach ($this->sortTaxonomyTermFieldItems($entity->field_language) as $item) {
          if (!empty($item->entity)) {
            $item_title = $item->entity->label();
            $details['language'][] = $item_title;
          }
        }

        $data['details'] = array_filter($details);

        // Revision information.
        $data['revision'] = $this->getEntityRevisionData($entity);

        // Filter out empty data.
        $cells['data'] = array_filter($data);
      }
      else {
        // No entity found, show importer info.
        $extra_items = [
          $this->t('Message: @message', [
            '@message' => $record['message'],
          ]),
          $this->t('Attempts: @attempts', [
            '@attempts' => $record['attempts'],
          ]),
        ];

        if (isset($record['extra'])) {
          foreach ($record['extra']['inoreader'] as $label => $item) {
            // Convert the label to a human-readable format.
            $label = ucfirst(str_replace('_', ' ', $label));

            if (strpos($item, 'http') === 0) {
              $item = [
                '#type' => 'link',
                '#title' => $label,
                '#url' => Url::fromUri($item),
              ];
              $extra_items[] = $item;
            }
            else {
              $extra_items[] = $label . ': ' . $item;
            }
          }
        }

        $data['extra'] = [
          '#theme' => 'item_list',
          '#items' => $extra_items,
        ];

        // Filter out empty data.
        $cells['info'] = array_filter($data);
      }

      $cells['importer'] = $record['importer'];
      $cells['status']['label'] = [
        '#type' => 'markup',
        '#markup' => $record['status'],
      ];

      if (isset($status_types[$record['status_type']])) {
        $cells['status']['label']['#markup'] .= ' (' . $status_types[$record['status_type']]['label'] . ')';
      }
      elseif (!empty($record['status_type'])) {
        $cells['status']['label']['#markup'] .= ' (' . $record['status_type'] . ')';
      }

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
            '#markup' => $this->dateFormatter->format($entity->getCreatedTime(), 'custom', 'd/m/Y H:i:s'),
          ],
        ];
      }
      else {
        $editorial_links = [];
        foreach ($editorial_flows as $editorial_flow => $editorial_flow_info) {
          $link = Url::fromRoute('reliefweb_import.reliefweb_importer.change_editorial_flow', [
            'uuid' => $record['imported_item_uuid'],
            'editorial_flow' => $editorial_flow,
          ], [
            'query' => [
              'destination' => $this->requestStack->getCurrentRequest()->getRequestUri() . '#row-' . $short_id,
            ],
          ]);
          $editorial_links[$editorial_flow_info['id']] = [
            'title' => $editorial_flow_info['label'],
            'url' => $link,
            'attributes' => [
              'title' => $editorial_flow_info['description'],
            ],
          ];

          // Move current editor to the first position.
          if ($editorial_flow_info['id'] === $record['editorial_flow']) {
            $editorial_links = [$editorial_flow_info['id'] => $editorial_links[$editorial_flow_info['id']]] + $editorial_links;
          }
        }

        $cells['node_created']['editorial_flow_label'] = [
          '#type' => 'markup',
          '#markup' => $this->t('Workflow'),
        ];
        $cells['node_created']['editorial_flow_'] = [
          '#type' => 'dropbutton',
          '#dropbutton_type' => 'rw-moderation',
          '#links' => $editorial_links,
        ];
      }

      $rows[] = [
        'id' => 'row-' . $short_id,
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

    $definitions['editorial_flow'] = [
      'form' => 'editorial_flow',
      'type' => 'field',
      'label' => $this->t('Editorial flow'),
      'field' => 'editorial_flow',
      'column' => 'value',
      'operator' => 'OR',
      'values' => $this->getEditorialFlowValues(),
    ];

    $definitions['status_type'] = [
      'form' => 'status_type',
      'type' => 'field',
      'label' => $this->t('Status type'),
      'field' => 'status_type',
      'column' => 'value',
      'operator' => 'OR',
      'values' => $this->getStatusTypeValues(),
    ];

    $definitions['importer'] = [
      'form' => 'importer',
      'type' => 'field',
      'label' => $this->t('Importer'),
      'field' => 'importer',
      'column' => 'value',
      'operator' => 'OR',
      'values' => $this->getImporterValues(),
    ];

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
   * Get importer values.
   */
  protected function getImporterValues() {
    $values = [];

    foreach ($this->pluginManager->getDefinitions() as $plugin_id => $definition) {
      $values[$plugin_id] = $definition['label'];
    }

    asort($values);

    return $values;
  }

  /**
   * Get status type values from database.
   */
  protected function getStatusTypeValues() {
    $values = [];

    $status_types = reliefweb_import_status_type_values();
    foreach ($status_types as $status_type) {
      $values[$status_type['id']] = $status_type['label'];
    }

    return $values;
  }

  /**
   * Get editorial flow values from database.
   */
  protected function getEditorialFlowValues() {
    $values = [];

    $editorial_flows = reliefweb_import_editorial_flow_values();
    foreach ($editorial_flows as $editorial_flow) {
      $values[$editorial_flow['id']] = $editorial_flow['label'];
    }

    return $values;
  }

  /**
   * Get source values from database.
   */
  protected function getSourceValues() {
    $query = $this->database->select('reliefweb_import_records', 'r')
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

    $query = $this->database->select('reliefweb_import_records', 'r')
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
        if (!empty($record['entity_id'])) {
          $entity_ids[] = $record['entity_id'];
        }
      }
      $data['entities'] = $this->entityTypeManager
        ->getStorage('node')
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
    $wrapper->addField('subquery', 'imported_item_uuid', 'entity_id');
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
          elseif ($name === 'importer') {
            $query->condition('r.importer', $values, 'IN');
            continue;
          }
          elseif ($name === 'editorial_flow') {
            $query->condition('r.editorial_flow', $values, 'IN');
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
    $records = $this->database->select('reliefweb_import_records', 'r')
      ->fields('r')
      ->condition('imported_item_uuid', $ids, 'IN')
      ->orderBy('created', 'DESC')
      ->execute()
      ?->fetchAllAssoc('imported_item_uuid', \PDO::FETCH_ASSOC) ?? [];

    // Deserialize the extra field.
    foreach ($records as &$record) {
      if (isset($record['extra'])) {
        $record['extra'] = json_decode($record['extra'], TRUE);
      }
    }

    return $records;
  }

}
