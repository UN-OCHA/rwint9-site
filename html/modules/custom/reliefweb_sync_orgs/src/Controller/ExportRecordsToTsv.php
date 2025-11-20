<?php

namespace Drupal\reliefweb_sync_orgs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_sync_orgs\Service\ImportExportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Controller for exporting organization records to a TSV file.
 */
class ExportRecordsToTsv extends ControllerBase {

  /**
   * Import export service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportExportService
   */
  protected $importExportService;

  /**
   * {@inheritdoc}
   */
  public function __construct(ImportExportService $importExportService) {
    $this->importExportService = $importExportService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_sync_orgs.import_export_service')
    );
  }

  /**
   * Exports organization records to a TSV file.
   */
  public function export() {
    $data = $this->importExportService->getRecordsForExport($this->entityTypeManager());

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
      'Use sheet data',
      'rw_homepage',
      'homepage',
      'rw_countries',
      'countries',
      'rw_short_name',
      'short_name',
      'rw_description',
      'description',
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

}
