<?php

namespace Drupal\reliefweb_sync_orgs\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;

/**
 * Import export service.
 */
class ImportExportService {

  /**
   * Queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The import record service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportRecordService
   */
  protected $importRecordService;

  /**
   * Constructs a new InoreaderImportForm.
   */
  public function __construct(
    QueueFactory $queue_factory,
    ImportRecordService $importRecordService,
  ) {
    $this->queueFactory = $queue_factory;
    $this->importRecordService = $importRecordService;
  }

  /**
   * Get queue.
   */
  protected function getQueue(string $name): QueueInterface {
    return $this->queueFactory->get($name);
  }

  /**
   * Import from csv.
   */
  public function importFromCsv(string $queue_name, string $filename, string $source) {
    $count = 0;

    $field_info = reliefweb_sync_orgs_field_info($source);
    if (empty($field_info)) {
      throw new \Exception("No field info found for source: $source");
    }

    $f = @fopen($filename, 'r');
    if (!$f) {
      throw new \Exception("Unable to open file: $filename");
    }
    $header = fgetcsv($f, NULL, ',');

    // Replace all spaces with underscores.
    $header_lowercase = array_map(function ($value) {
      return str_replace(' ', '_', trim(strtolower($value)));
    }, $header);

    // Get data.
    while ($row = fgetcsv($f, NULL, ',')) {
      $data = [];
      for ($i = 0; $i < count($row); $i++) {
        $data[$header_lowercase[$i]] = trim($row[$i] ?? '');
      }

      // Skip empty rows silently.
      if (empty(array_filter($data))) {
        continue;
      }

      // Make sure Id field is present.
      if (empty($data[$field_info['id']])) {
        throw new \Exception(strtr('Row @row_number is missing the ID field.', [
          '@row_number' => $count + 1,
        ]));
      }

      // Add source to the data.
      $data['_source'] = $source;

      // Add row number to the data.
      $data['_row_number'] = $count + 1;

      $this->getQueue($queue_name)->createItem($data);
      $count++;
    }

    fclose($f);

    return $count;
  }

  /**
   * Import from tsv.
   */
  public function importFromTsv(string $queue_name, string $filename) {
    $count = 0;

    $f = fopen($filename, 'r');
    if (!$f) {
      throw new \Exception("Unable to open file: $filename");
    }
    $header = fgetcsv($f, NULL, "\t");

    // Replace all spaces with underscores.
    $header_lowercase = array_map(function ($value) {
      return str_replace(' ', '_', trim(strtolower($value)));
    }, $header);

    // Get data.
    while ($row = fgetcsv($f, NULL, "\t")) {
      $data = [];
      for ($i = 0; $i < count($row); $i++) {
        $data[$header_lowercase[$i]] = trim($row[$i] ?? '');
      }

      // Add row number to the data.
      $data['_row_number'] = $count + 1;

      $this->getQueue($queue_name)->createItem($data);
      $count++;
    }

    fclose($f);

    return $count;
  }

  /**
   * Get all records for export and match terms.
   */
  public function getRecordsForExport(EntityTypeManagerInterface $entityTypeManager) {
    $records = $this->importRecordService->getAllImportRecords();

    // Create a list of tid to load them all together.
    $tids = [];
    foreach ($records as $record) {
      if (isset($record['tid'])) {
        $tids[] = $record['tid'];
      }
    }

    // Load all terms in one go.
    $terms = $entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadMultiple($tids);

    // Field info.
    $field_info = reliefweb_sync_orgs_field_info();

    $export_data = [];
    foreach ($records as $record) {
      $source = $record['source'] ?? '';
      $name = $record['csv_item'][$field_info[$source]['label_field']] ?? '';

      // Prepare each record for CSV export.
      $row = [
        'source' => $record['source'],
        'id' => $record['id'],
        'name' => $name,
        'status' => $record['status'],
        'created' => date('Y-m-d H:i:s', $record['created']),
        'changed' => date('Y-m-d H:i:s', $record['changed']),
        'message' => $record['message'],
        'term_name' => '',
        'term_id' => '',
        'parent_name' => '',
        'parent_id' => '',
        'create_new' => '',
      ];

      // Add term information if available.
      if (isset($record['tid']) && isset($terms[$record['tid']])) {
        /** @var \Drupal\taxonomy\Entity\Term $term */
        $term = $terms[$record['tid']];
        $row['term_name'] = $term->getName();
        $row['term_id'] = $term->id();

        if ($term->hasField('parent') && !$term->get('parent')->isEmpty()) {
          $parent = $term->get('parent')->entity;
          if ($parent) {
            $row['parent_name'] = $parent->getName();
            $row['parent_id'] = $parent->id();
          }
        }
      }

      $export_data[] = $row;
    }

    return $export_data;
  }

}
