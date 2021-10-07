<?php

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $database = $this
      ->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->getMock();

    $entityTypeManager = $this
      ->getMockBuilder(EntityTypeManagerInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $accountSwitcher = $this
      ->getMockBuilder(AccountSwitcherInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $httpClient = $this
      ->getMockBuilder(ClientInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $loggerFactory = $this
      ->getMockBuilder(LoggerChannelFactoryInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $state = $this
      ->getMockBuilder(State::class)
      ->disableOriginalConstructor()
      ->getMock();

    $database = $this->prophesize(Connection::class)->reveal();
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class)->reveal();
    $accountSwitcher = $this->prophesize(AccountSwitcherInterface::class)->reveal();
    $httpClient = $this->prophesize(ClientInterface::class)->reveal();
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class)->reveal();
    $state = $this->prophesize(State::class)->reveal();

    $this->reliefwebImporter = new ReliefwebImportCommandStub($database, $entityTypeManager, $accountSwitcher, $httpClient, $loggerFactory, $state);
    $this->random = new Random();
  }

}
