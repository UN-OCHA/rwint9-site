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
    $this->assertEquals('Report content processor', (string) $this->plugin->getPluginLabel());
  }

  /**
   * @covers ::getEntityType
   */
  public function testGetEntityType(): void {
    $this->assertEquals('node', $this->plugin->getEntityType());
  }

  /**
   * @covers ::getEntityBundle
   */
  public function testGetEntityBundle(): void {
    $this->assertEquals('report', $this->plugin->getEntityBundle());
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

    $entity = $this->createEntity('node', 'report');
    $entity->uuid = $plugin->generateUuid($data['url']);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
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

    $entity = $this->createEntity('node', 'training');
    $entity->nid = 123;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('is not a report');

    $plugin->process($data);
  }

}
