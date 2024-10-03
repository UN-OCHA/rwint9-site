<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\ContentProcessorPluginBaseTest;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;

/**
 * Tests the Training content processor plugin.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor\Training
 *
 * @group reliefweb_post_api
 */
class TrainingTest extends ContentProcessorPluginBaseTest {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->plugin = $this->contentProcessorPluginManager->getPluginByBundle('training');
  }

  /**
   * @covers ::getPluginLabel
   */
  public function testGetPluginLabel(): void {
    $this->assertEquals('Training', (string) $this->plugin->getPluginLabel());
  }

  /**
   * @covers ::getEntityType
   */
  public function testGetEntityType(): void {
    $this->assertEquals('node', $this->plugin->getEntityType());
  }

  /**
   * @covers ::getBundle
   */
  public function testGetBundle(): void {
    $this->assertEquals('training', $this->plugin->getBundle());
  }

  /**
   * @covers ::getResource
   */
  public function testGetResource(): void {
    $this->assertEquals('training', $this->plugin->getResource());
  }

  /**
   * @covers ::process
   */
  public function testProcess(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $statement = $this->createStatementMock('fetchAllKeyed', [123 => 123]);
    $select = $this->createSelectMock($statement);
    $database = $this->createDatabaseMock($select);

    // Create a new instance of the current plugin with some mocked services.
    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
      'database' => $database,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'training');
    $entity->uuid = $plugin->generateUuid($data['url']);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $plugin->process($data);
    $this->assertSame($data['title'], $entity->label());
  }

  /**
   * @covers ::process
   */
  public function testProcessWrongBundle(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    // Create a new instance of the current plugin with some mocked services.
    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'job');
    $entity->nid = 123;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('is not a training');

    $plugin->process($data);
  }

  /**
   * @covers ::process
   */
  public function testProcessRefused(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    // Create a new instance of the current plugin with some mocked services.
    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'training');
    $entity->nid = 123;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->moderation_status = 'refused';
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('is marked as refused');

    $plugin->process($data);
  }

}
