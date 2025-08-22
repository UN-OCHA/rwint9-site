<?php

namespace Drupal\reliefweb_sync_orgs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\reliefweb_sync_orgs\Service\ImportRecordService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Controller for exporting organization records to a TSV file.
 */
class ExportRecordsToTsv extends ControllerBase {

  /**
   * The import record service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportRecordService
   */
  protected $importRecordService;

  /**
   * {@inheritdoc}
   */
  public function __construct(ImportRecordService $importRecordService) {
    $this->importRecordService = $importRecordService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_sync_orgs.import_record_service')
    );
  }

  /**
   * Exports organization records to a TSV file.
   */
  public function export() {
    $data = $this->getRecordsForExport();

    $filename = 'reliefweb_sync_orgs_records_' . date('Ymd_His') . '.tsv';
    $headers = [
      'Source',
      'ID',
      'Name',
      'Status',
      'Created',
      'Changed',
      'Message',
      'Term Name',
      'Term ID',
      'Parent Name',
      'Parent ID',
      'Create New',
    ];

    // Convert to TSV.
    $handle = fopen('php://memory', 'r+');
    fputcsv($handle, $headers, "\t");
    foreach ($data as $row) {
      fputcsv($handle, array_values($row), "\t");
    }
    rewind($handle);
    $csv = trim(stream_get_contents($handle));
    fclose($handle);

    $response = new Response();
    $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
    $response->headers->set('Content-Disposition', $disposition);
    $response->headers->set('Content-Type', 'text/tsv');
    $response->headers->set('Expires', 0);
    $response->headers->set('Content-Transfer-Encoding', 'binary');
    $response->setContent($csv);

    return $response;
  }

  /**
   * Get all records for export and match terms.
   */
  public function getRecordsForExport() {
    $records = $this->importRecordService->getAllImportRecords();

    // Create a list of tid to load them all together.
    $tids = [];
    foreach ($records as $record) {
      if (isset($record['tid'])) {
        $tids[] = $record['tid'];
      }
    }

    // Load all terms in one go.
    $terms = $this->entityTypeManager()
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
