<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

use DrupalTest\QueueRunnerTrait\QueueRunnerTrait;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Import organizations from CSV.
 */
class ImportBase extends ExistingSiteBase {

  use QueueRunnerTrait;

  /**
   * The queue to test with.
   *
   * @var string
   */
  protected $queueName = 'reliefweb_sync_orgs_process_csv_item';

  /**
   * The queue to test with.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * Web master.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $local_webmaster;

  /**
   * Create webmaster.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->local_webmaster = $this->createUser();
    $this->local_webmaster->addRole('webmaster');
    $this->local_webmaster->save();

    $this->queue = $this->container->get('queue')->get($this->queueName);
  }

  /**
   * Import csv file.
   */
  public function importCsvFile(string $source, string $file_path): void {
    // Login as the local_webmaster.
    $this->drupalLogin($this->local_webmaster);
    $this->drupalGet('/reliefweb/sync_orgs/import');
    $this->assertSession()->statusCodeEquals(200);

    // Upload a file.
    $this->submitForm([
      'source' => $source,
      'files[csv_file]' => $file_path,
    ], 'Import');

    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Clear all import records.
   */
  public function clearImportRecords(): void {
    $this->container->get('database')->truncate('reliefweb_sync_orgs_records')->execute();
  }

}
