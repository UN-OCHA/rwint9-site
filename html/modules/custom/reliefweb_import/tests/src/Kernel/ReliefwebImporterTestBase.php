<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_import\Kernel;

use Drupal\reliefweb_import\Command\ReliefwebImportCommand;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Component\Utility\Random;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand
 */
class ReliefwebImporterTestBase extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * The database connection.
   */
  protected $database;

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The account switcher.
   */
  protected $accountSwitcher;

  /**
   * An http client.
   */
  protected $httpClient;

  /**
   * The logger factory.
   */
  protected $loggerFactory;

  /**
   * The state store.
   */
  protected $state;

  /**
   * Reliefweb importer.
   *
   * @var \Drupal\reliefweb_import\Command\ReliefwebImportCommand
   */
  protected $reliefwebImporter;

  /**
   * Random helper.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $random;

  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  public static $modules = [
    'reliefweb_import',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = \Drupal::service('database');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
    $this->accountSwitcher = \Drupal::service('account_switcher');
    $this->httpClient = \Drupal::service('http_client');
    $this->loggerFactory = \Drupal::service('logger.factory');
    $this->state = \Drupal::service('state');

    $this->reliefwebImporter = new ReliefwebImportCommand($this->database, $this->entityTypeManager, $this->accountSwitcher, $this->httpClient, $this->loggerFactory, $this->state);
    $this->random = new Random();
  }

}
