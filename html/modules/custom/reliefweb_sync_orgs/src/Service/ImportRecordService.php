<?php

namespace Drupal\reliefweb_sync_orgs\Service;

use Drupal\Core\Database\Connection;

/**
 * Service for managing import records for reliefweb_sync_orgs.
 */
class ImportRecordService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructor.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Construct a reliefweb_sync_orgs_records.
   */
  public function constructReliefwebSyncOrgsRecord(string $source, string $id, array $item): array {
    return [
      'source' => $source,
      'id' => $id,
      'status' => $item['status'] ?? 'queued',
      'created' => time(),
      'changed' => time(),
      'message' => '',
      'csv_item' => $item,
    ];
  }

  /**
   * Retrieve existing records.
   */
  public function getExistingImportRecord(string $source, string $id): array {
    $record = $this->database->select('reliefweb_sync_orgs_records', 'r')
      ->fields('r')
      ->condition('source', $source)
      ->condition('id', $id)
      ->execute()
      ?->fetch(\PDO::FETCH_ASSOC);

    if (isset($record['csv_item'])) {
      $record['csv_item'] = json_decode($record['csv_item'], TRUE);
    }

    return is_array($record) ? $record : [];
  }

  /**
   * Save import records.
   */
  public function saveImportRecords(string $source, string $id, array $record): array {
    $existing_record = $this->getExistingImportRecord($source, $id);

    // Set timestamp for changed field.
    $record['changed'] = time();

    // Serialize json data.
    if (isset($record['csv_item'])) {
      $record['csv_item'] = json_encode($record['csv_item']);
    }

    // Create comparison copies without the 'changed' timestamp.
    $compare_existing = $existing_record;
    $compare_new = $record;
    unset($compare_existing['changed']);
    unset($compare_new['changed']);

    // Only update if the record has actually changed.
    if (!empty($existing_record)) {
      if ($compare_existing != $compare_new) {
        $this->database->update('reliefweb_sync_orgs_records')
          ->fields($record)
          ->condition('source', $source)
          ->condition('id', $id)
          ->execute();
      }
    }
    else {
      // Set timestamp for created field if not provided.
      if (!isset($record['created'])) {
        $record['created'] = time();
      }

      // Insert new record.
      $this->database->insert('reliefweb_sync_orgs_records')
        ->fields($record)
        ->execute();
    }

    return $record;
  }

  /**
   * Get all import records.
   */
  public function getAllImportRecords(): array {
    $query = $this->database->select('reliefweb_sync_orgs_records', 'r')
      ->fields('r')
      ->orderBy('created', 'DESC');

    $records = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($records as &$record) {
      if (isset($record['csv_item'])) {
        $record['csv_item'] = json_decode($record['csv_item'], TRUE);
      }
    }

    return $records;
  }

}
