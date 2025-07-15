<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\ContentProcessorPluginBaseTestCase;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor\Report;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Report content processor plugin.
 */
#[CoversClass(Report::class)]
#[Group('reliefweb_post_api')]
class ReportTest extends ContentProcessorPluginBaseTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->plugin = $this->contentProcessorPluginManager->getPluginByBundle('report');
  }

  /**
   * Test get plugin label.
   */
  public function testGetPluginLabel(): void {
    $this->assertEquals('Reports', (string) $this->plugin->getPluginLabel());
  }

  /**
   * Test get entity type.
   */
  public function testGetEntityType(): void {
    $this->assertEquals('node', $this->plugin->getEntityType());
  }

  /**
   * Test get bundle.
   */
  public function testGetBundle(): void {
    $this->assertEquals('report', $this->plugin->getBundle());
  }

  /**
   * Test get resource.
   */
  public function testGetResource(): void {
    $this->assertEquals('reports', $this->plugin->getResource());
  }

  /**
   * Test process.
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
   * Test process with wrong bundle.
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
   * Test process with refused status.
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
   * Test validate files with unallowed image url.
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
   * Test validate files with unallowed file url.
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
