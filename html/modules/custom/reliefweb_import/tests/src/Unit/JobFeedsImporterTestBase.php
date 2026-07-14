<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Component\Utility\Random;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\State;
use Drupal\reliefweb_import\Service\JobFeedsImporter;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests reliefweb importer.
 */
#[CoversClass(JobFeedsImporter::class)]
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
   * @var \Drupal\reliefweb_import\Service\JobFeedsImporter
   */
  protected JobFeedsImporter $jobImporter;

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
    $this->jobImporter = new JobFeedsImporter(
      $this->database->reveal(),
      $this->entityTypeManager->reveal(),
      $this->accountSwitcher->reveal(),
      $this->httpClient->reveal(),
      $this->loggerFactory->reveal(),
      $this->state->reveal(),
    );
    $this->random = new Random();
  }

  /**
   * Invokes a protected method on the job importer.
   *
   * @param string $method_name
   *   The method name.
   * @param array $arguments
   *   The arguments to pass to the method.
   *
   * @return mixed
   *   The result of the method call.
   */
  protected function invokeProtectedMethod(string $method_name, array $arguments = []) {
    $reflection = new \ReflectionClass($this->jobImporter);
    $method = $reflection->getMethod($method_name);

    return $method->invokeArgs($this->jobImporter, $arguments);
  }

  /**
   * Sets the HTTP client on the job importer.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  protected function setHttpClient(ClientInterface $http_client): void {
    $reflection = new \ReflectionProperty(JobFeedsImporter::class, 'httpClient');
    $reflection->setValue($this->jobImporter, $http_client);
  }

}
