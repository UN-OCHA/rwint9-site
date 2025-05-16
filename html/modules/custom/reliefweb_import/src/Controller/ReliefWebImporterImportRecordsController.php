<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Pager\PagerManagerInterface;
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
    protected PagerManagerInterface $pager_manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Display a list of records.
   *
   * @return array
   *   Render array with a table of the available content importer plugins.
   */
  public function listFailedImportRecords(): array {
    $limit = 20;
    $count = count($this->getFailedImportRecords(0, 999999));
    if ($count == 0) {
      return [
        '#markup' => $this->t('No failed import records found.'),
      ];
    }

    $pager = $this->pager_manager->createPager($count, $limit);
    $records = $this->getFailedImportRecords($pager->getCurrentPage(), $limit);

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

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Retrieve failed import records.
   *
   * @return array
   *   An array of import records keyed by the import item UUID.
   */
  protected function getFailedImportRecords(int $page = 0, int $limit = 20): array {
    $records = $this->database->select('reliefweb_import_records', 'r')
      ->fields('r')
      ->condition('status', 'success', '!=')
      ->range($page * $limit, $limit)
      ->orderBy('created', 'DESC')
      ->execute()
      ?->fetchAllAssoc('imported_item_uuid', \PDO::FETCH_ASSOC) ?? [];

    return $records;
  }

}
