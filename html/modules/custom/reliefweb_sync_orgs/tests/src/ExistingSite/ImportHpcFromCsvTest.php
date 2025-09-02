<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use DrupalTest\QueueRunnerTrait\QueueRunnerTrait;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Import organizations from CSV.
 */
class ImportHpcFromCsvTest extends ExistingSiteBase {

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
  }

  /**
   * Test the import form.
   */
  public function testImportForm() {
    // Clear the queue.
    $this->clearQueue($this->queueName);

    // Login as the webmaster.
    $this->drupalLogin($this->webmaster);
    $this->drupalGet('/reliefweb/sync_orgs/import');
    $this->assertSession()->statusCodeEquals(200);

    // Upload an HPC file.
    $this->submitForm([
      'source' => 'hpc',
      'files[csv_file]' => __DIR__ . '/fixtures/hpc_dataset.csv',
    ], 'Import');

    $this->assertSession()->statusCodeEquals(200);

    // Assert the number of items in the queue.
    $this->assertEquals(20, $this->queue->numberOfItems());
    $this->runQueue($this->queueName);
    $this->assertEquals(0, $this->queue->numberOfItems());
  }

  /**
   * Create terms.
   */
  protected function createTermIfNeeded($vocabulary, $id, $title, array $extra = []) : Term {
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

  /**
   * Create user if needed.
   */
  protected function createUserIfNeeded($id, $name, array $extra = []) : User {
    if ($user = User::load($id)) {
      return $user;
    }

    $user = User::create([
      'uid' => $id,
      'name' => $name,
      'mail' => $this->randomMachineName(32) . '@localhost.localdomain',
      'status' => 1,
    ] + $extra);
    $user->save();

    return $user;
  }

}
