<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

/**
 * Test queue worker.
 */
class ListOrganizationsTest extends ImportBase {

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();

    // Clear database.
    $this->clearImportRecords();

    // Import both HDX and HPC csv files.
    $this->importCsvFile('hdx', __DIR__ . '/fixtures/hdx_dataset.csv');
    $this->importCsvFile('hpc', __DIR__ . '/fixtures/hpc_dataset.csv');

    // Run the queue.
    $this->runQueue($this->queueName);
  }

  /**
   * Test overview page.
   */
  public function testOverviewPage() {
    // Login if not already logged in.
    if (!$this->loggedInUser) {
      $this->drupalLogin($this->webmaster);
    }

    // Check overview page.
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

  /**
   * Test create organization manually.
   */
  public function testCreateOrganizationManually() {
    $organization_type = $this->createTermIfNeeded('organization_type', 270, 'Academic and Research Institution');

    // Login if not already logged in.
    if (!$this->loggedInUser) {
      $this->drupalLogin($this->webmaster);
    }

    // Check create organization form.
    $this->drupalGet('/reliefweb/sync_orgs/create-organization-manually/hdx/c832172a-2485-4951-8f2f-1295ce46809e');
    $this->assertSession()->statusCodeEquals(200);

    // Check that the organization info is displayed.
    $this->assertSession()->pageTextContains('ACF West and Central Africa GIS and Surveillance System');

    // Submit the form.
    $this->submitForm([
      'name' => 'ACF West and Central Africa GIS and Surveillance System',
      'short_name' => 'ACF-ROWCA',
      'organization_type' => $organization_type->id(),
      'country' => 'Central African Republic (54)',
    ], 'Save');

    // Load the overview page.
    $this->drupalGet('/reliefweb/sync_orgs/overview');
    $this->assertSession()->statusCodeEquals(200);

    // Check that the new organization is displayed.
    $this->assertSession()->pageTextContains('ACF West and Central Africa GIS and Surveillance System');
    $this->assertSession()->pageTextContains('Organization created manually');
  }

}
