<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Component\Utility\Random;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\State;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\reliefweb_import\Unit\Stub\JobFeedsImporterStub;
use GuzzleHttp\ClientInterface;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter
 */
class JobFeedsImporterTestBase extends UnitTestCase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection|Prophecy\Prophecy\ObjectProphecy
   */
  protected Connection|ObjectProphecy $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|Prophecy\Prophecy\ObjectProphecy
   */
  protected EntityTypeManagerInterface|ObjectProphecy $entityTypeManager;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface|Prophecy\Prophecy\ObjectProphecy
   */
  protected AccountSwitcherInterface|ObjectProphecy $accountSwitcher;

  /**
   * An http client.
   *
   * @var \GuzzleHttp\ClientInterface|Prophecy\Prophecy\ObjectProphecy
   */
  protected ClientInterface|ObjectProphecy $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|Prophecy\Prophecy\ObjectProphecy
   */
  protected LoggerChannelFactoryInterface|ObjectProphecy $loggerFactory;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface|Prophecy\Prophecy\ObjectProphecy
   */
  protected StateInterface|ObjectProphecy $state;

  /**
   * Reliefweb importer.
   *
   * @var \Drupal\Tests\reliefweb_import\Unit\Stub\JobFeedsImporterStub
   */
  protected JobFeedsImporterStub $jobImporter;
  /**
   * Random helper.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected Random $random;

  /**
   * Prophesize services.
   */
  protected function prophesizeServices(): void {
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
    parent::setUp();
    $this->prophesizeServices();
    $this->jobImporter = new JobFeedsImporterStub(
      $this->database->reveal(),
      $this->entityTypeManager->reveal(),
      $this->accountSwitcher->reveal(),
      $this->httpClient->reveal(),
      $this->loggerFactory->reveal(),
      $this->state->reveal(),
    );
    $this->random = new Random();
  }

}
