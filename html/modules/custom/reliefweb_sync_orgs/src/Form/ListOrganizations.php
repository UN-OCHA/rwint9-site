<?php

namespace Drupal\reliefweb_sync_orgs\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_sync_orgs\Service\ImportRecordService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to manually create an organization.
 */
class ListOrganizations extends FormBase {

  /**
   * The import record service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportRecordService
   */
  protected $importRecordService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The pager manager service.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Constructs a new form.
   */
  public function __construct(ImportRecordService $import_record_service, EntityTypeManagerInterface $entity_type_manager, Connection $database, PagerManagerInterface $pager_manager) {
    $this->importRecordService = $import_record_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->pagerManager = $pager_manager;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_sync_orgs.import_record_service'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('pager.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_sync_orgs_list_organizations';
  }

  /**
   * Return a list of filters.
   */
  public function getFilters(array $defaults = [], array $totals = []) {
    $filters = [
      '#type' => 'fieldset',
      '#attributes' => [
        'class' => ['table-filter'],
      ],
      '#weight' => -10,
      '#title' => $this->t('Filter'),
      '#tree' => TRUE,
    ];
    $filters['status'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Status'),
      '#options' => [
        'success' => $this->t('Success (@count)', ['@count' => $totals['success'] ?? 0]),
        'skipped' => $this->t('Skipped (@count)', ['@count' => $totals['skipped'] ?? 0]),
        'exact' => $this->t('Exact match (@count)', ['@count' => $totals['exact'] ?? 0]),
        'partial' => $this->t('Partial (@count)', ['@count' => $totals['partial'] ?? 0]),
        'mismatch' => $this->t('Mismatch (@count)', ['@count' => $totals['mismatch'] ?? 0]),
        'fixed' => $this->t('Fixed (@count)', ['@count' => $totals['fixed'] ?? 0]),
        'ignored' => $this->t('Ignored (@count)', ['@count' => $totals['ignored'] ?? 0]),
      ],
      '#default_value' => array_values($defaults['status'] ?? []),
      '#attributes' => [
        'class' => ['form--inline'],
      ],
    ];
    $filters['source'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Source'),
      '#options' => $this->getSourceValues(),
      '#default_value' => array_values($defaults['source'] ?? []),
      '#attributes' => [
        'class' => ['form--inline'],
      ],
    ];
    $filters['text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#size' => 30,
      '#default_value' => $defaults['text'] ?? '',
      '#attributes' => [
        'class' => ['form--inline'],
      ],
    ];
    $filters['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['form--inline']],
    ];
    $filters['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#button_type' => 'primary',
    ];
    $filters['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#button_type' => 'secondary',
      '#submit' => [[$this, 'resetFilters']],
    ];

    return $filters;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $limit = 30;
    $default_value = [];

    $active_filters = $form_state->get('active_filters') ?? [];

    $header = [
      'checkbox' => '',
      'id' => $this->t('ID'),
      'status' => $this->t('Status'),
      'entity' => $this->t('Entity'),
      'item' => $this->t('Item'),
      'message' => $this->t('Message'),
      'created' => $this->t('Created'),
      'changed' => $this->t('Updated'),
      'operations' => $this->t('Operations'),
    ];

    // Get totals for pagination and status counts.
    [$totals_by_status, $total] = $this->getTotalsByStatus($active_filters);

    // Initialize pager.
    $pager = $this->pagerManager->createPager((int) $total, $limit);
    $current_page = $pager->getCurrentPage();
    $offset = $current_page * $limit;

    // Main query limited by pager offset/limit.
    $results = $this->getResults($active_filters, $offset, $limit);

    // Load all entities using tid.
    $entities = [];
    $tids = [];
    foreach ($results as $record) {
      if (isset($record['tid']) && !empty($record['tid'])) {
        $tids[] = $record['tid'];
      }
    }
    if (!empty($tids)) {
      $entities = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    }

    // Current URL.
    $current_url = Url::fromRoute('<current>', [], ['absolute' => FALSE])->toString();

    $rows = [];
    foreach ($results as $record) {
      // Decode csv_item if it exists.
      if (isset($record['csv_item']) && is_string($record['csv_item'])) {
        $record['csv_item'] = json_decode($record['csv_item'], TRUE);
      }

      // Make a safe CSS id for the row.
      $id = Html::cleanCssIdentifier(implode('--', [
        $record['source'] ?? '',
        $record['id'] ?? '',
      ]));

      $entity_info = '';
      $item_info = '';

      if (isset($record['tid']) && isset($entities[$record['tid']])) {
        $entity = $entities[$record['tid']];
        $entity_info = $entity->toLink()->toString();
      }

      $item_info = $record['csv_item']['display_name'] ?? '';

      $default_value[$id] = FALSE;
      $cells = [
        'checkbox' => [
          'data' => [
            '#type' => 'checkbox',
            '#title' => $this->t('Select'),
            '#title_display' => 'invisible',
            '#attributes' => ['class' => ['record-checkbox']],
            '#return_value' => $id,
            '#default_value' => $id,
            '#name' => 'selected_records[]',
          ],
        ],
        'id' => [
          'id' => $id,
          'data' => $record['id'],
          'class' => ['record-id'],
        ],
        'status' => [
          'data' => $record['status'],
        ],
        'entity' => [
          'data' => $entity_info,
        ],
        'item' => [
          'data' => $item_info,
        ],
        'message' => [
          'data' => $record['message'] ?? '',
        ],
        'created' => [
          'data' => date('Y-m-d H:i', $record['created']),
        ],
        'changed'  => [
          'data' => date('Y-m-d H:i', $record['changed']),
        ],
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [],
          ],
        ],
      ];

      // Operations (dropbutton) column.
      $cells['operations']['data']['#links']['create'] = [
        'title' => $this->t('Create new'),
        'url' => Url::fromRoute('reliefweb_sync_orgs.create_organization_manually', [
          'source' => $record['source'],
          'id' => $record['id'],
        ], [
          'query' => [
            'destination' => $current_url . '#row-' . $id,
          ],
        ]),
      ];
      $cells['operations']['data']['#links']['fix'] = [
        'title' => $this->t('Fix manually'),
        'url' => Url::fromRoute('reliefweb_sync_orgs.fix_organization_manually', [
          'source' => $record['source'],
          'id' => $record['id'],
        ], [
          'query' => [
            'destination' => $current_url . '#row-' . $id,
          ],
        ]),
      ];

      $rows[$id] = $cells;
    }

    $form['filters'] = $this->getFilters($active_filters, $totals_by_status);

    $form['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#default_value' => $default_value,
      '#empty' => $this->t('No organization records found.'),
      '#js_select' => TRUE,
      '#multiple' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create new organizations'),
      '#submit' => [[$this, 'createNewOnes']],
    ];
    $form['actions']['ignore'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ignore these records'),
      '#submit' => [[$this, 'ignoreRecords']],
    ];

    $form['pager'] = [
      '#type' => 'pager',
    ];

    return $form;
  }

  /**
   * Get totals by status and overall total for the current filters.
   *
   * @param array $filters
   *   Active filters from the form state.
   *
   * @return array
   *   Array of 2 elements: [totals_by_status, total].
   */
  protected function getTotalsByStatus(array $filters): array {
    $parameters = [];
    $sql = 'select status, count(status) from reliefweb_sync_orgs_records where status is not null';

    if (!empty($filters['status'])) {
      $sql .= ' and status in (:status[])';
      $parameters[':status[]'] = array_filter(array_values($filters['status']));
    }
    if (!empty($filters['source'])) {
      $sql .= ' and source in (:source[])';
      $parameters[':source[]'] = array_filter(array_values($filters['source']));
    }
    if (!empty($filters['text'])) {
      $sql .= ' and JSON_EXTRACT(csv_item, \'$.name\') like :text';
      $parameters[':text'] = '%' . $this->database->escapeLike($filters['text']) . '%';
    }

    $sql .= ' group by status order by status';

    $total = 0;
    $totals_by_status = $this->database->query($sql, $parameters)->fetchAllKeyed();
    foreach ($totals_by_status as $count) {
      $total += $count;
    }

    return [$totals_by_status, $total];
  }

  /**
   * Build the base results query with applied filters (without range/pager).
   *
   * @param array $filters
   *   Active filters.
   * @param int $offset
   *   Offset for the results.
   * @param int $limit
   *   Limit for the results.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select query with conditions applied.
   */
  protected function getResults(array $filters, int $offset, int $limit): array {
    $sql = 'SELECT * FROM {reliefweb_sync_orgs_records} where status is not null';
    $parameters = [];

    if (!empty($filters['status'])) {
      $sql .= ' AND status IN (:status[])';
      $parameters[':status[]'] = array_filter(array_values($filters['status']));
    }
    if (!empty($filters['source'])) {
      $sql .= ' AND source IN (:source[])';
      $parameters[':source[]'] = array_filter(array_values($filters['source']));
    }
    if (!empty($filters['text'])) {
      $sql .= ' AND ' . $this->buildWhereClauseForTextSearch();
      $parameters[':text'] = '%' . $this->database->escapeLike(strtolower($filters['text'])) . '%';
    }

    $sql .= ' ORDER BY changed DESC';
    $sql .= ' LIMIT ' . $limit;
    $sql .= ' OFFSET ' . $offset;

    return $this->database->query($sql, $parameters)->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Build the where clause for the json searchable feeds.
   */
  protected function buildWhereClauseForTextSearch(): string {
    $search_fields = reliefweb_sync_orgs_searchable_fields();
    $query = '(';
    $conditions = [];
    foreach ($search_fields as $field) {
      $conditions[] = "LOWER(JSON_EXTRACT(csv_item, '$.$field')) LIKE :text";
    }
    $query .= implode(' OR ', $conditions) . ')';

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $active_filters = [];
    $input = $form_state->getUserInput();
    if (isset($input['filters'])) {
      $active_filters = $input['filters'];
    }

    // Clean the input.
    if (isset($active_filters['status'])) {
      $active_filters['status'] = array_filter($active_filters['status']);
    }
    if (isset($active_filters['source'])) {
      $active_filters['source'] = array_filter($active_filters['source']);
    }

    $form_state->set('active_filters', $active_filters);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for creating new organizations.
   */
  public function createNewOnes(array &$form, FormStateInterface $form_state) {
    $this->submitForm($form, $form_state);
    $record_ids = $form_state->getUserInput()['selected_records'] ?? [];
    if (empty($record_ids)) {
      $this->messenger()->addWarning($this->t('No records selected to ignore.'));
      return;
    }

    foreach ($record_ids as $record_id) {
      [$source, $id] = explode('--', $record_id, 2);
      $record = $this->importRecordService->getExistingImportRecord($source, $id);
      if (!$record) {
        continue;
      }

      // Create a new taxonomy term for the organization.
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'name' => $record['csv_item']['display_name'],
        'vid' => 'source',
        'field_shortname' => [
          'value' => $record['csv_item']['display_name'],
        ],
      ]);

      // Save the term.
      $term->save();

      // Update the record with the created organization.
      $record['tid'] = $term->id();
      $record['status'] = 'fixed';
      $this->importRecordService->saveImportRecords($source, $id, $record);
    }
  }

  /**
   * Submit handler for ignoring records.
   */
  public function ignoreRecords(array &$form, FormStateInterface $form_state) {
    $this->submitForm($form, $form_state);
    $record_ids = $form_state->getUserInput()['selected_records'] ?? [];
    if (empty($record_ids)) {
      $this->messenger()->addWarning($this->t('No records selected to ignore.'));
      return;
    }

    foreach ($record_ids as $record_id) {
      [$source, $id] = explode('--', $record_id, 2);
      $record = $this->importRecordService->getExistingImportRecord($source, $id);
      if (!$record) {
        continue;
      }

      $record['status'] = 'ignored';
      $this->importRecordService->saveImportRecords($source, $id, $record);
    }
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
   * Submit handler to reset all filters.
   */
  public function resetFilters(array &$form, FormStateInterface $form_state) {
    // Redirect to the same page without filters.
    $form_state->setRedirect('reliefweb_sync_orgs.overview');

    // Rebuild the form.
    $form_state->setRebuild(FALSE);
  }

}
