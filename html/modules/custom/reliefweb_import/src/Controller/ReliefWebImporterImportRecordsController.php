<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
      $container->get('database'),
    );
  }

  /**
   * Change status of an import record.
   */
  public function changeStatus(Request $request, string $uuid, string $status) : Response {
    $record = $this->getImportRecord($uuid);
    if (empty($record)) {
      throw new \InvalidArgumentException('Import record not found for UUID: ' . $uuid);
    }

    $statuses = reliefweb_import_status_type_values();
    if (!isset($statuses[$status])) {
      throw new \InvalidArgumentException('Invalid status type: ' . $status);
    }

    $status_info = $statuses[$status];
    $this->database->update('reliefweb_import_records')
      ->fields([
        'status' => $status_info['status'],
        'status_type' => $status_info['id'],
        'changed' => time(),
        'attempts' => $status_info['attempts'] ?? 99,
      ])
      ->condition('imported_item_uuid', $uuid)
      ->execute();

    // Redirect to the import record page.
    if ($destination = $request->query->get('destination')) {
      return new RedirectResponse($destination);
    }

    $previousUrl = $request->server->get('HTTP_REFERER');
    $response = new RedirectResponse($previousUrl);

    return $response;
  }

  /**
   * Change status of an import record.
   */
  public function changeEditor(Request $request, string $uuid, string $editor) : Response {
    $record = $this->getImportRecord($uuid);
    if (empty($record)) {
      throw new \InvalidArgumentException('Import record not found for UUID: ' . $uuid);
    }

    $statuses = reliefweb_import_editorial_flow_values();
    if (!isset($statuses[$editor])) {
      throw new \InvalidArgumentException('Invalid editorial flow: ' . $editor);
    }

    $editorial_flow = $statuses[$editor];
    $this->database->update('reliefweb_import_records')
      ->fields([
        'status' => $editorial_flow['status'],
        'editorial_flow' => $editorial_flow['id'],
        'changed' => time(),
        'attempts' => $editorial_flow['attempts'] ?? 99,
      ])
      ->condition('imported_item_uuid', $uuid)
      ->execute();

    // Redirect to the import record page.
    if ($destination = $request->query->get('destination')) {
      return new RedirectResponse($destination);
    }

    $previousUrl = $request->server->get('HTTP_REFERER');
    $response = new RedirectResponse($previousUrl);

    return $response;
  }

  /**
   * Retrieve failed import records.
   *
   * @return array
   *   An array of import records keyed by the import item UUID.
   */
  protected function getImportRecord(string $uuid): array {
    $records = $this->database->select('reliefweb_import_records', 'r')
      ->fields('r')
      ->condition('imported_item_uuid', $uuid)
      ->execute()
      ?->fetchAllAssoc('imported_item_uuid', \PDO::FETCH_ASSOC) ?? [];

    // Deserialize the extra field.
    foreach ($records as &$record) {
      if (isset($record['extra'])) {
        $record['extra'] = json_decode($record['extra'], TRUE);
      }
    }

    return reset($records) ?: [];
  }

}
