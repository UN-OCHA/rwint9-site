<?php

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

use Drupal\reliefweb_sync_orgs\Service\ImportExportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Test import export service.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
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

  /**
   * Test detectCsvEnclosure.
   */
  #[DataProvider('detectCsvEnclosureProvider')]
  public function testDetectCsvEnclosure(string $filename, string $expected_delimiter, ?string $expected_enclosure) {
    $f = @fopen(__DIR__ . '/fixtures/' . $filename, 'r');
    $enclosure_info = $this->importExportService->detectCsvEnclosure($f);
    $this->assertEquals($expected_delimiter, $enclosure_info['delimiter']);
    $this->assertEquals($expected_enclosure, $enclosure_info['enclosure']);
  }

  /**
   * Data provider for testDetectCsvEnclosure.
   */
  public static function detectCsvEnclosureProvider() {
    return [
      ['detect_csv_semicolon.csv', ';', '"'],
      ['detect_csv_semicolon_quote.csv', ';', '\''],
      ['detect_csv_semicolon_doublequote.csv', ';', '"'],
      ['detect_csv_colon.csv', ',', '"'],
      ['detect_csv_colon_quote.csv', ',', '\''],
      ['detect_csv_colon_doublequote.csv', ',', '"'],
      ['detect_csv_tab.tsv', "\t", '"'],
      ['detect_csv_tab_quote.tsv', "\t", '\''],
      ['detect_csv_tab_doublequote.tsv', "\t", '"'],
    ];
  }

}
