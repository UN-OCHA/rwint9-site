<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

/**
 * Test export.
 */
class ExportRecordsToTsvTest extends ImportBase {

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();

    // Clear database.
    $this->clearImportRecords();

    // Import HDX csv files.
    $this->importCsvFile('hdx', __DIR__ . '/fixtures/hdx_dataset.csv');

    // Run the queue.
    $this->runQueue($this->queueName);
  }

  /**
   * Test export page.
   */
  public function testExportPage() {
    // Login if not already logged in.
    if (!$this->loggedInUser) {
      $this->drupalLogin($this->webmaster);
    }

    // Check export page.
    $this->drupalGet('/reliefweb/sync_orgs/export');
    $this->assertSession()->statusCodeEquals(200);

    $response = $this->getSession()
      ->getDriver()
      ->getContent();

    // Put contents into a memory stream and use fgetcsv to parse.
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $response);
    rewind($stream);

    $records = [];
    // Get the header row.
    $header = fgetcsv($stream, 0, "\t");
    while ($line = fgetcsv($stream, 0, "\t")) {
      $row = [];
      foreach ($line as $key => $value) {
        $row[$header[$key]] = $value;
      }
      $records[] = $row;
    }

    fclose($stream);

    // Check that record has a term id set.
    foreach ($records as $record) {
      if ($record['ID'] === '921f24a0-52f5-429b-ae59-54674b3f177e') {
        $this->assertNotEmpty($record['Term ID']);
        $this->assertEquals('4536', $record['Term ID']);
      }
    }
  }

  /**
   * Test export service.
   */
  public function testExportService() {
    $service = $this->container->get('reliefweb_sync_orgs.import_export_service');
    $entityTypeManager = $this->container->get('entity_type.manager');

    // Test the export service functionality.
    $exportedData = $service->getRecordsForExport($entityTypeManager);
    $this->assertNotEmpty($exportedData);
  }

}
