<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\ContentProcessorPluginBaseTest;

/**
 * Tests the Report content processor plugin.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor\Report
 *
 * @group reliefweb_post_api
 */
class ReportTest extends ContentProcessorPluginBaseTest {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->plugin = $this->contentProcessorPluginManager->getPluginByBundle('report');
  }

  /**
   * @covers ::getPluginLabel
   */
  public function testGetPluginLabel(): void {
    $this->assertEquals('Reports', (string) $this->plugin->getPluginLabel());
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
    $this->assertEquals('report', $this->plugin->getBundle());
  }

  /**
   * @covers ::getResource
   */
  public function testGetResource(): void {
    $this->assertEquals('reports', $this->plugin->getResource());
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

    $data = ['source' => [123]] + $this->getPostApiData();
    unset($data['file']);
    unset($data['image']);

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'report');
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

    $data = ['source' => [123]] + $this->getPostApiData();

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'training');
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
    $this->expectExceptionMessage('is not a report');

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

    $data = ['source' => [123]] + $this->getPostApiData();

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'report');
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

  /**
   * @covers ::validateFiles
   */
  public function testValidateFilesUnallowedImageUrl(): void {
    $data = $this->getPostApiData();
    $data['image']['url'] = 'https://wrong.test/test.jpg';

    // Unallowed image URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Unallowed image URL');
    $this->plugin->validateFiles($data);
  }

  /**
   * @covers ::validateFiles
   */
  public function testValidateFilesUnallowedFileUrl(): void {
    $data = $this->getPostApiData();
    $data['file'][0]['url'] = 'https://wrong.test/test.pdf';

    // Unallowed file URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Unallowed file URL');
    $this->plugin->validateFiles($data);
  }

}
