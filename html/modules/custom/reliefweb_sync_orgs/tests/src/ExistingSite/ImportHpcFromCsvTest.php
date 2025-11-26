<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Test import HPC from CSV.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class ImportHpcFromCsvTest extends ImportBase {

  /**
   * Test the import form.
   */
  public function testImportForm() {
    // Login if not already logged in.
    if (!$this->loggedInUser) {
      $this->drupalLogin($this->webmaster);
    }

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
