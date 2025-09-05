<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

use Drupal\taxonomy\Entity\Term;
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
  protected $webmaster;

  /**
   * Create webmaster.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->webmaster = $this->createUser();
    $this->webmaster->addRole('webmaster');
    $this->webmaster->save();

    $this->queue = $this->container->get('queue')->get($this->queueName);
    $this->clearQueue($this->queueName);

    // Make sure organizations do exists as terms.
    $this->createTermIfNeeded('source', 4536, 'Action on Armed Violence');
    $this->createTermIfNeeded('source', 9417, 'ACAPS');
    $this->createTermIfNeeded('source', 51822, '3iSolution');
  }

  /**
   * Import csv file.
   */
  public function importCsvFile(string $source, string $file_path): void {
    // Login if not already logged in.
    if (!$this->loggedInUser) {
      $this->drupalLogin($this->webmaster);
    }

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

  /**
   * Create terms.
   */
  protected function createTermIfNeeded(string $vocabulary, int $id, string $title, array $extra = []) : Term {
    if ($term = Term::load($id)) {
      return $term;
    }

    $term = Term::create([
      'vid' => $vocabulary,
      'tid' => $id,
      'name' => $title,
    ] + $extra);
    $term->save();

    return $term;
  }

}
