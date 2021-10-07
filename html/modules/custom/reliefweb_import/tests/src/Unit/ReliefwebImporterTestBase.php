<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Component\Utility\Random;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\State;
use Drupal\Tests\reliefweb_import\Unit\Stub\ReliefwebImportCommandStub;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;

/**
 * Tests reliefweb importer.
 */
class ReliefwebImporterTestBase extends UnitTestCase {

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
   * @var \Drupal\Tests\reliefweb_import\Unit\Stub\ReliefwebImportCommandStub
   */
  protected $reliefwebImporter;

  /**
   * Random helper.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $random;

  /**
   * Prophesize services.
   */
  protected function prophesizeServices() {
    $this->database = $this->prophesize(Connection::class);
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->accountSwitcher = $this->prophesize(AccountSwitcherInterface::class);
    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->state = $this->prophesize(State::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->prophesizeServices();
    $this->reliefwebImporter = new ReliefwebImportCommandStub($this->database->reveal(), $this->entityTypeManager->reveal(), $this->accountSwitcher->reveal(), $this->httpClient->reveal(), $this->loggerFactory->reveal(), $this->state->reveal());
    $this->random = new Random();
  }

}
