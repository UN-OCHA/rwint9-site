<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

/**
 * Import organizations from CSV.
 */
class ImportHpcFromCsvTest extends ImportBase {

  /**
   * Test the import form.
   */
  public function testImportForm() {
    // Clear the queue.
    $this->clearQueue($this->queueName);

    // Import the HPC CSV file.
    $this->importCsvFile('hpc', __DIR__ . '/fixtures/hpc_dataset.csv');

    // Assert the number of items in the queue.
    $this->assertEquals(10, $this->queue->numberOfItems());
    $this->runQueue($this->queueName);
    $this->assertEquals(0, $this->queue->numberOfItems());
  }

}
