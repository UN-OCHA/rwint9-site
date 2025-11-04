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

    // Detect delimiter and enclosure.
    $enclosure_info = NULL;
    try {
      $enclosure_info = $this->detectCsvEnclosure($f);
    }
    catch (\Exception $e) {
      throw new \Exception("Unable to read from file: $filename - " . $e->getMessage(), 0, $e);
    }
    $header = fgetcsv($f, NULL, $enclosure_info['delimiter'], $enclosure_info['enclosure']);

    // Replace all spaces with underscores.
    $header_lowercase = array_map(function ($value) {
      return str_replace(' ', '_', trim(strtolower($value)));
    }, $header);

    // Get data.
    while ($row = fgetcsv($f, NULL, $enclosure_info['delimiter'], $enclosure_info['enclosure'])) {
      $data = [];
      for ($i = 0; $i < count($row); $i++) {
        $data[$header_lowercase[$i]] = trim($row[$i] ?? '');
      }

      // Skip empty rows silently.
      if (empty(array_filter($data))) {
        continue;
      }

      // Skip rows having an ignore flag.
      if (isset($data['ignore']) && !empty($data['ignore'])) {
        continue;
      }

      // Make sure Id field is present.
      if (empty($data[$field_info['id']])) {
        // Close the file.
        fclose($f);

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

    // Detect delimiter and enclosure.
    $enclosure_info = NULL;
    try {
      $enclosure_info = $this->detectCsvEnclosure($f);
    }
    catch (\Exception $e) {
      throw new \Exception("Unable to read from file: $filename - " . $e->getMessage(), 0, $e);
    }
    $header = fgetcsv($f, NULL, $enclosure_info['delimiter'], $enclosure_info['enclosure']);

    // Replace all spaces with underscores.
    $header_lowercase = array_map(function ($value) {
      return str_replace(' ', '_', trim(strtolower($value)));
    }, $header);

    // Get data.
    while ($row = fgetcsv($f, NULL, $enclosure_info['delimiter'], $enclosure_info['enclosure'])) {
      $data = [];
      for ($i = 0; $i < count($row); $i++) {
        $data[$header_lowercase[$i]] = trim($row[$i] ?? '');
      }

      // Skip rows having an ignore flag.
      if (isset($data['ignore']) && !empty($data['ignore'])) {
        continue;
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

    // Reversed mapping.
    $reversed_mapping = [];
    if (isset($field_info)) {
      foreach ($field_info as $source => $info) {
        $reversed_mapping[$source] = array_flip($info['mapping'] ?? []);
      }
    }

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
        'use_sheet_data' => '',
        'rw_homepage' => '',
        'homepage' => isset($reversed_mapping[$source]['field_homepage']) ? $record['csv_item'][$reversed_mapping[$source]['field_homepage']] : '',
        'rw_countries' => '',
        'countries' => isset($reversed_mapping[$source]['field_country']) ? $record['csv_item'][$reversed_mapping[$source]['field_country']] : '',
        'rw_short_name' => '',
        'short_name' => isset($reversed_mapping[$source]['field_short_name']) ? $record['csv_item'][$reversed_mapping[$source]['field_short_name']] : '',
        'rw_description' => '',
        'description' => isset($reversed_mapping[$source]['description']) ? $record['csv_item'][$reversed_mapping[$source]['description']] : '',
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

        if ($term->hasField('field_homepage') && !$term->get('field_homepage')->isEmpty()) {
          $row['rw_homepage'] = $term->get('field_homepage')->uri;
        }

        if ($term->hasField('field_country') && !$term->get('field_country')->isEmpty()) {
          $countries = [];
          foreach ($term->get('field_country')->referencedEntities() as $country_term) {
            $countries[] = $country_term->getName();
          }
          $row['rw_countries'] = implode('; ', $countries);
        }

        if ($term->hasField('field_shortname') && !$term->get('field_shortname')->isEmpty()) {
          $row['rw_short_name'] = $term->get('field_shortname')->value;
        }

        if ($term->hasField('description') && !$term->get('description')->isEmpty()) {
          $row['rw_description'] = $term->get('description')->value;
        }
      }

      $export_data[] = $row;
    }

    return $export_data;
  }

  /**
   * Detect the delimiter and enclosure of a CSV or TSV file.
   */
  public function detectCsvEnclosure($file_handle): array {
    if (!is_resource($file_handle) || get_resource_type($file_handle) !== 'stream') {
      throw new \Exception("Parameter \$file_handle must be a valid file handle (resource of type 'stream')");
    }

    $header = fgets($file_handle);
    if (empty($header)) {
      throw new \Exception("Unable to read header line from file");
    }

    $delimiter = NULL;
    $enclosure = NULL;

    $delimiters = ["\t", ',', ';'];
    $enclosures = ['"', "'"];

    foreach ($delimiters as $delim) {
      $fields = str_getcsv($header, $delim);
      if (count($fields) > 1) {
        $delimiter = $delim;
        foreach ($enclosures as $enc) {
          $fields = str_getcsv($header, $delimiter, $enc);
          if (strpos($header, $enc) !== FALSE && count($fields) > 1) {
            $enclosure = $enc;
            break;
          }
        }

        // If header does not contain enclosure, check next line.
        if ($enclosure === NULL) {
          $row = fgets($file_handle);
          foreach ($enclosures as $enc) {
            $fields = str_getcsv($row, $delimiter, $enc);
            if (strpos($row, $enc) !== FALSE && count($fields) > 1) {
              $enclosure = $enc;
              break;
            }
          }
        }

        break;
      }
    }

    // Fallback to double quotes if none detected.
    if ($enclosure === NULL) {
      $enclosure = '"';
    }

    // Rewind the file handle for future reading.
    rewind($file_handle);

    return [
      'delimiter' => $delimiter,
      'enclosure' => $enclosure,
    ];
  }

}
