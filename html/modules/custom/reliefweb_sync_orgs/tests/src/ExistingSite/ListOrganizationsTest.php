<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

/**
 * Test queue worker.
 */
class ListOrganizationsTest extends ImportBase {

  /**
   * Test overview page.
   */
  public function testOverviewPage() {
    // Login if not already logged in.
    if (!$this->loggedInUser) {
      $this->drupalLogin($this->webmaster);
    }

    // Clear database.
    $this->clearImportRecords();

    // Import both HDX and HPC csv files.
    $this->importCsvFile('hdx', __DIR__ . '/fixtures/hdx_dataset.csv');
    $this->importCsvFile('hpc', __DIR__ . '/fixtures/hpc_dataset.csv');

    // Run the queue.
    $this->runQueue($this->queueName);

    // Login as the webmaster.
    $this->drupalGet('/reliefweb/sync_orgs/overview');
    $this->assertSession()->statusCodeEquals(200);

    // Check that the correct organizations are displayed.
    $this->assertSession()->pageTextContains('HDX (9)');
    $this->assertSession()->pageTextContains('HPC (10)');

    // Make sure links are present.
    $this->assertSession()->linkExists('Import CSV file');
    $this->assertSession()->linkExists('Export to CSV');
    $this->assertSession()->linkExists('Import from export');

    // Filter by HDX.
    $this->submitForm([
      'filters[source][hdx]' => 'hdx',
    ], 'Apply');
    $this->assertSession()->statusCodeEquals(200);

    // Check that the correct organizations are displayed.
    $this->assertSession()->pageTextContains('HDX (9)');
    $this->assertSession()->pageTextContains('HPC (0)');
  }

}
