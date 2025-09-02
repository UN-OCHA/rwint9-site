<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

/**
 * Import organizations from CSV.
 */
class ImportHdxFromCsvTest extends ImportBase {

  /**
   * Test the import form.
   */
  public function testImportForm() {
    // Clear the queue.
    $this->clearQueue($this->queueName);

    // Import the HDX CSV file.
    $this->importCsvFile('hdx', __DIR__ . '/fixtures/hdx_dataset.csv');

    // Assert the number of items in the queue.
    $this->assertEquals(9, $this->queue->numberOfItems());
    $this->runQueue($this->queueName);
    $this->assertEquals(0, $this->queue->numberOfItems());
  }

}
