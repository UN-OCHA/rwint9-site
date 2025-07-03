<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
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
    $result = $this->getRecords();

    $table_data = [];
    foreach ($result as $record) {
      $importer = $record['importer'];
      if (!isset($table_data[$importer])) {
        $table_data[$importer] = [
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
      }

      if (!isset($table_data[$importer]['headers'][$record['status']])) {
        $table_data[$importer]['headers'][$record['status']] = ucfirst(str_replace('_', ' ', $record['status']));
        $table_data[$importer]['totals'][$record['status']] = 0;
      }
      $table_data[$importer]['totals'][$record['status']] += $record['num'];

      if (!empty($record['status_type'])) {
        if (!isset($table_data[$importer]['headers'][$record['status'] . '::' . $record['status_type']])) {
          $table_data[$importer]['headers'][$record['status'] . '::' . $record['status_type']] = ucfirst(str_replace('_', ' ', $record['status'])) . ': ' . ucfirst(str_replace('_', ' ', $record['status_type']));
          $table_data[$importer]['totals'][$record['status'] . '::' . $record['status_type']] = 0;
        }
        $table_data[$importer]['totals'][$record['status'] . '::' . $record['status_type']] += $record['num'];
      }

      $key = $record['status'] . (empty($record['status_type']) ? '' : '::' . $record['status_type']);
      if (!isset($table_data[$importer]['rows'][$record['source']])) {
        $table_data[$importer]['rows'][$record['source']] = [
          'source' => $record['source'],
        ];
      }
      if (!isset($table_data[$importer]['rows'][$record['source']][$key])) {
        $table_data[$importer]['rows'][$record['source']][$key] = $record['num'];
      }
      if (!isset($table_data[$importer]['rows'][$record['source']]['total'])) {
        $table_data[$importer]['rows'][$record['source']]['total'] = 0;
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
      ];
    }

    $build['#cache'] = [
      'max-age' => 60,
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
    $sql = 'select importer, source, status, status_type, count(*) as num
      from reliefweb_import_records g
      group by importer, source, status, status_type
      order by importer, source, status, status_type';
    $records = $this->database->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

    return $records ?: [];
  }

}
