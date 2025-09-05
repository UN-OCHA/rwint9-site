<?php

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

use Drupal\reliefweb_sync_orgs\Service\ImportExportService;

/**
 * Test import export service.
 */
class ImportExportServiceTest extends ImportBase {

  /**
   * Import export service.
   */
  protected ImportExportService $importExportService;

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->importExportService = $this->container->get('reliefweb_sync_orgs.import_export_service');
  }

  /**
   * Test import CSV file.
   */
  public function testImportCsvFile() {
    $queue_name = 'reliefweb_sync_orgs_process_csv_item';
    $filename = __DIR__ . '/fixtures/hdx_dataset.csv';
    $source = 'hdx';

    $this->importExportService->importFromCsv($queue_name, $filename, $source);

    $this->assertEquals($this->queue->numberOfItems(), 9);
  }

  /**
   * Test file not found.
   */
  public function testImportCsvFileNotFound() {
    $queue_name = 'reliefweb_sync_orgs_process_csv_item';
    $filename = __DIR__ . '/fixtures/non_existent_file.csv';
    $source = 'hdx';

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("Unable to open file: $filename");

    $this->importExportService->importFromCsv($queue_name, $filename, $source);

    $this->assertEquals($this->queue->numberOfItems(), 0);
  }

  /**
   * Test non existing source.
   */
  public function testImportCsvFileNonExistingSource() {
    $queue_name = 'reliefweb_sync_orgs_process_csv_item';
    $filename = __DIR__ . '/fixtures/hdx_dataset.csv';
    $source = 'non_existing_source';

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("No field info found for source: $source");

    $this->importExportService->importFromCsv($queue_name, $filename, $source);
  }

  /**
   * Test import CSV file.
   */
  public function testImportTsvFile() {
    $queue_name = 'reliefweb_sync_orgs_process_tsv_item';
    $filename = __DIR__ . '/fixtures/hdx_export.tsv';
    $source = 'hdx';
    $queue = $this->container->get('queue')->get($queue_name);

    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue->deleteQueue();

    $this->importExportService->importFromTsv($queue_name, $filename, $source);

    $this->assertEquals($queue->numberOfItems(), 9);
  }

}
