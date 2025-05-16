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
class ReliefWebImporterImportRecordsController extends ControllerBase {

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
      $container->get('database')
    );
  }

  /**
   * Display a list of records.
   *
   * @return array
   *   Render array with a table of the available content importer plugins.
   */
  public function listFailedImportRecords(): array {
    $records = $this->getFailedImportRecords();
    if (empty($records)) {
      return [
        '#markup' => $this->t('No failed import records found.'),
      ];
    }

    $build = [];
    $headers = [
      $this->t('Imported item'),
      $this->t('importer'),
      $this->t('status'),
      $this->t('attempts'),
      $this->t('created'),
      $this->t('changed'),
      $this->t('message'),
    ];

    $rows = [];
    foreach ($records as $record) {
      $rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $record['imported_item_id'],
            '#url' => Url::fromUri($record['imported_item_url']),
          ],
        ],
        $record['importer'],
        $record['status'],
        $record['attempts'],
        date('c', (int) $record['created']),
        date('c', (int) $record['changed']),
        $record['message'],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No ReliefWeb Importer plugins found.'),
    ];

    return $build;
  }

  /**
   * Retrieve failed import records.
   *
   * @return array
   *   An array of import records keyed by the import item UUID.
   */
  protected function getFailedImportRecords(): array {
    $records = $this->database->select('reliefweb_import_records', 'r')
      ->fields('r')
      ->condition('status', 'success', '!=')
      ->execute()
      ?->fetchAllAssoc('imported_item_uuid', \PDO::FETCH_ASSOC) ?? [];

    return $records;
  }

}
