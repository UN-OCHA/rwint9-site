<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List reliefweb_import_records.
 */
class ReliefWebImporterStatisticsController extends ControllerBase {

  /**
   * Constructor.
   */
  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * List statistics.
   */
  public function listStatistics() : array {
    $feat_show_status_types = FALSE;

    $result = $this->getRecords();
    $tags = [
      'reliefweb_import_records:list',
    ];
    $status_types = $this->getStatusTypeValues();
    $editorial_flows = $this->getEditorialFlowValues();

    $default_table = [
      'headers' => [
        'source' => 'Source',
        'total' => 'Total',
        'success' => 'Success',
        'skipped' => 'Skipped',
        'error' => 'Error',
        'duplicate' => 'Duplicate',
      ],
      'rows' => [],
      'totals' => [
        'source' => 'Totals',
        'total' => 0,
        'success' => 0,
        'skipped' => 0,
        'error' => 0,
        'duplicate' => 0,
      ],
    ];

    if ($feat_show_status_types) {
      foreach ($status_types as $id => $label) {
        $default_table['headers']['s_' . $id] = $label;
        $default_table['totals']['s_' . $id] = 0;
      }
      $default_table['headers']['s_unknown'] = 'Unknown';
      $default_table['totals']['s_unknown'] = 0;
    }

    foreach ($editorial_flows as $id => $label) {
      $default_table['headers']['e_' . $id] = $label;
      $default_table['totals']['e_' . $id] = 0;
    }
    $default_table['headers']['e_unknown'] = 'Unknown';
    $default_table['totals']['e_unknown'] = 0;

    $table_data = [];
    foreach ($result as $record) {
      $importer = $record['importer'];
      if (!isset($table_data[$importer])) {
        $table_data[$importer] = $default_table;
      }

      // Skip records that do not have a valid status.
      if (!isset($table_data[$importer]['totals'][$record['status']])) {
        continue;
      }

      if (!isset($table_data[$importer]['rows'][$record['source']])) {
        $table_data[$importer]['rows'][$record['source']] = $default_table['totals'];
        $table_data[$importer]['rows'][$record['source']]['source'] = $record['source'];
      }

      $table_data[$importer]['totals'][$record['status']] += $record['num'];
      $table_data[$importer]['rows'][$record['source']][$record['status']] += $record['num'];

      if ($feat_show_status_types && !empty($record['status_type'])) {
        if (isset($table_data[$importer]['totals']['s_' . $record['status_type']])) {
          $table_data[$importer]['totals']['s_' . $record['status_type']] += $record['num'];
          $table_data[$importer]['rows'][$record['source']]['s_' . $record['status_type']] += $record['num'];
        }
        else {
          $table_data[$importer]['totals']['s_unknown'] += $record['num'];
          $table_data[$importer]['rows'][$record['source']]['s_unknown'] += $record['num'];
        }
      }

      if (!empty($record['editorial_flow'])) {
        if (isset($table_data[$importer]['totals']['e_' . $record['editorial_flow']])) {
          $table_data[$importer]['totals']['e_' . $record['editorial_flow']] += $record['num'];
          $table_data[$importer]['rows'][$record['source']]['e_' . $record['editorial_flow']] += $record['num'];
        }
        else {
          $table_data[$importer]['totals']['e_unknown'] += $record['num'];
          $table_data[$importer]['rows'][$record['source']]['e_unknown'] += $record['num'];
        }
      }

      $table_data[$importer]['rows'][$record['source']]['total'] += $record['num'];
      $table_data[$importer]['totals']['total'] += $record['num'];
    }

    foreach ($table_data as $importer => $data) {
      // Make sure each row has the same amount of columns.
      $rows = [];
      foreach ($data['rows'] as $row_data) {
        $row = [];
        $percentage = 0;

        if ($row_data['total'] > 0) {
          $percentage = round(($row_data['success'] ?? 0) / $row_data['total'] * 100);
          $row_data['success'] = ($row_data['success'] ?? 0) . ' (' . $percentage . '%)';
        }

        foreach ($data['headers'] as $header => $header_label) {
          // If the header is 'source', we need to create a link to the source.
          if ($header === 'source') {
            $row[] = [
              'data' => [
                '#type' => 'link',
                '#title' => $row_data['source'],
                '#url' => Url::fromRoute('reliefweb_moderation.content', [
                  'service' => 'reliefweb_import',
                ],
                [
                  'query' => [
                    'filters' => [
                      'source' => [
                        $row_data['source'] => $row_data['source'],
                      ],
                    ],
                  ],
                ]),
              ],
            ];
          }
          else {
            if (isset($row_data[$header])) {
              $row[] = $row_data[$header];
            }
            else {
              $row[] = '';
            }
          }
        }

        $class = '';
        if ($percentage < 50) {
          $class = 'reliefweb-importer-statistics-low';
        }
        elseif ($percentage < 80) {
          $class = 'reliefweb-importer-statistics-medium';
        }
        elseif ($percentage < 100) {
          $class = 'reliefweb-importer-statistics-high';
        }
        else {
          $class = 'reliefweb-importer-statistics-perfect';
        }
        $rows[] = [
          'data' => $row,
          'class' => [$class],
        ];
      }

      // Sort the rows by source.
      usort($rows, function ($a, $b) {
        return strcmp($a['data'][0]['data']['#title'], $b['data'][0]['data']['#title']);
      });

      // Add totals row.
      array_unshift($rows, $data['totals']);

      $build[$importer . '_header'] = [
        '#type' => 'markup',
        '#markup' => '<h2>' . $this->t('Statistics for @importer', ['@importer' => $importer]) . '</h2>',
      ];

      $build[$importer] = [
        '#type' => 'table',
        '#header' => $data['headers'],
        '#rows' => $rows,
        '#empty' => $this->t('No ReliefWeb Importer records found.'),
        '#sticky' => TRUE,
        '#no_striping' => TRUE,
        '#attributes' => [
          'class' => [
            'reliefweb-import-stats',
          ],
        ],
      ];
    }

    $build['#cache'] = [
      'max-age' => 60,
      'tags' => $tags,
    ];

    $build['#attached']['library'][] = 'common_design_subtheme/rw-moderation';

    return $build;
  }

  /**
   * Get records from database grouped by status and status_type.
   *
   * @return array
   *   An array with numbers.
   */
  protected function getRecords(): array {
    $sql = 'select importer, source, status, status_type, editorial_flow, count(*) as num
      from reliefweb_import_records
      group by importer, source, status, status_type, editorial_flow
      order by importer, source, status, status_type, editorial_flow';
    $records = $this->database->query($sql)->fetchAll(FetchAs::Associative);

    return $records ?: [];
  }

  /**
   * Get status type values from database.
   */
  protected function getStatusTypeValues() {
    $values = [];

    $status_types = reliefweb_import_status_type_values();
    foreach ($status_types as $status_type) {
      $values[$status_type['id']] = (string) $status_type['label'];
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
      $values[$editorial_flow['id']] = (string) $editorial_flow['label'];
    }

    return $values;
  }

}
