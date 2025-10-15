<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Test import HDX from CSV.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class ImportHdxFromCsvTest extends ImportBase {

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

    // Import the HDX CSV file.
    $this->importCsvFile('hdx', __DIR__ . '/fixtures/hdx_dataset.csv');

    // Assert the number of items in the queue.
    $this->assertEquals(9, $this->queue->numberOfItems());
    $this->runQueue($this->queueName);
    $this->assertEquals(0, $this->queue->numberOfItems());
  }

}
