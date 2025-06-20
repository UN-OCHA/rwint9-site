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
          ],
          'rows' => [],
        ];
      }

      if (!isset($table_data[$importer]['headers'][$record['status']])) {
        $table_data[$importer]['headers'][$record['status']] = $record['status'];
      }
      if (!empty($record['status_type']) && !isset($table_data[$importer]['headers'][$record['status'] . '::' . $record['status_type']])) {
        $table_data[$importer]['headers'][$record['status'] . '::' . $record['status_type']] = $record['status_type'];
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
    }

    foreach ($table_data as $importer => $data) {
      // Make sure each row has the same amount of columns.
      $rows = [];
      foreach ($data['rows'] as $row_data) {
        $row = [];
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
        $rows[] = $row;
      }

      $build[$importer] = [
        '#type' => 'table',
        '#header' => $data['headers'],
        '#rows' => $rows,
        '#empty' => $this->t('No ReliefWeb Importer records found.'),
        '#sticky' => TRUE,
      ];
    }

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
