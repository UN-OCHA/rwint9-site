<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_post_api\Commands\ReliefWebPostApiCommands;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Symfony\Component\ErrorHandler\BufferingLogger;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb Post API drush commands.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Commands\ReliefWebPostApiCommands
 *
 * @group reliefweb_post_api
 */
class ReliefWebPostApiCommandsTest extends ExistingSiteBase {

  /**
   * Logger.
   *
   * @var \Symfony\Component\ErrorHandler\BufferingLogger
   */
  protected BufferingLogger $logger;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->logger = new BufferingLogger();
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor(): void {
    $this->assertInstanceOf(ReliefWebPostApiCommands::class, $this->createTestCommandHandler());
  }

  /**
   * @covers ::process
   */
  public function testProcessEmptyQueue(): void {
    $queue = $this->createConfiguredMock(QueueInterface::class, [
      'claimItem' => FALSE,
    ]);

    $queue_factory = $this->createConfiguredMock(QueueFactory::class, [
      'get' => $queue,
    ]);

    $handler = $this->createTestCommandHandler([
      'queue' => $queue_factory,
    ]);

    $handler->process(['limit' => 1]);
    $this->assertSame([
      ['info', 'Processed 0 queued submissions.', []],
    ], $this->logger->cleanLogs());
  }

  /**
   * @covers ::process
   */
  public function testProcessUnsupportedBundle(): void {
    $item = new \stdClass();
    $item->data = ['bundle' => 'unsupported'];
    $item->item_id = 'abc';

    $queue = $this->createConfiguredMock(QueueInterface::class, [
      'claimItem' => $item,
    ]);

    $queue_factory = $this->createConfiguredMock(QueueFactory::class, [
      'get' => $queue,
    ]);

    $handler = $this->createTestCommandHandler([
      'queue' => $queue_factory,
    ]);

    $handler->process(['limit' => 1]);
    $this->assertSame([
      ['error', 'Unsupported bundle: unsupported, skipping item: abc.', []],
      ['info', 'Processed 1 queued submissions.', []],
    ], $this->logger->cleanLogs());
  }

  /**
   * @covers ::process
   */
  public function testProcessProcessException(): void {
    $item = new \stdClass();
    $item->data = [
      'bundle' => 'report',
      'uuid' => 'ba98249e-f453-4bff-92a7-5ffa7229d62b',
    ];
    $item->item_id = 'abc';

    $queue = $this->createConfiguredMock(QueueInterface::class, [
      'claimItem' => $item,
    ]);

    $queue_factory = $this->createConfiguredMock(QueueFactory::class, [
      'get' => $queue,
    ]);

    $plugin = $this->createMock(ContentProcessorPluginInterface::class);
    $plugin->expects($this->any())
      ->method('process')
      ->willThrowException(new \Exception('test exception'));

    $plugin_manager = $this->createConfiguredMock(ContentProcessorPluginManagerInterface::class, [
      'getPluginByBundle' => $plugin,
    ]);

    $handler = $this->createTestCommandHandler([
      'queue' => $queue_factory,
      'plugin.manager.reliefweb_post_api.content_processor' => $plugin_manager,
    ]);

    $handler->process(['limit' => 1]);
    $this->assertSame([
      ['info', 'Processing queued report abc (ba98249e-f453-4bff-92a7-5ffa7229d62b).', []],
      ['error', 'Error processing report abc (ba98249e-f453-4bff-92a7-5ffa7229d62b): test exception.', []],
      ['info', 'Processed 1 queued submissions.', []],
    ], $this->logger->cleanLogs());
  }

  /**
   * @covers ::process
   */
  public function testProcess(): void {
    $item = new \stdClass();
    $item->data = [
      'bundle' => 'report',
      'uuid' => 'ba98249e-f453-4bff-92a7-5ffa7229d62b',
    ];
    $item->item_id = 'abc';

    $queue = $this->createConfiguredMock(QueueInterface::class, [
      'claimItem' => $item,
    ]);

    $queue_factory = $this->createConfiguredMock(QueueFactory::class, [
      'get' => $queue,
    ]);

    $entity = $this->createConfiguredMock(Node::class, [
      'id' => 123,
      'uuid' => 'ba98249e-f453-4bff-92a7-5ffa7229d62b',
      'getRevisionLogMessage' => 'Automatic creation from POST API.',
    ]);

    $plugin = $this->createConfiguredMock(ContentProcessorPluginInterface::class, [
      'process' => $entity,
    ]);

    $plugin_manager = $this->createConfiguredMock(ContentProcessorPluginManagerInterface::class, [
      'getPluginByBundle' => $plugin,
    ]);

    $handler = $this->createTestCommandHandler([
      'queue' => $queue_factory,
      'plugin.manager.reliefweb_post_api.content_processor' => $plugin_manager,
    ]);

    $handler->process(['limit' => 1]);
    $this->assertSame([
      ['info', 'Processing queued report abc (ba98249e-f453-4bff-92a7-5ffa7229d62b).', []],
      ['info', 'Successfully created  entity with ID: 123 (ba98249e-f453-4bff-92a7-5ffa7229d62b).', []],
      ['info', 'Processed 1 queued submissions.', []],
    ], $this->logger->cleanLogs());
  }

  /**
   * Create a test drush command handler.
   *
   * @param array $services
   *   Services.
   *
   * @return \Drupal\reliefweb_post_api\Commands\ReliefWebPostApiCommands
   *   The drush command handler.
   */
  protected function createTestCommandHandler(array $services = []): ReliefWebPostApiCommands {
    $container = \Drupal::getContainer();

    $services = [
      $services['queue'] ?? $container->get('queue'),
      $services['plugin.manager.reliefweb_post_api.content_processor'] ?? $container->get('plugin.manager.reliefweb_post_api.content_processor'),
    ];

    $handler = new ReliefWebPostApiCommands(...$services);
    $handler->setLogger($this->logger);
    return $handler;
  }

}
