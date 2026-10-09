<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\ContentProcessorPluginBaseTestCase;
use Drupal\reliefweb_post_api\Enum\ContentProcessorMessage;
use Drupal\reliefweb_post_api\Exception\DocumentNotFoundException;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
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
    $this->expectExceptionMessage(ContentProcessorMessage::ExistingEntityWrongBundle->format([
      '@uuid' => $entity->uuid(),
      '@bundle' => 'report',
    ]));

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
    $this->expectExceptionMessage(ContentProcessorMessage::SkippingTerminalEntity->format([
      '@uuid' => $entity->uuid(),
      '@status' => 'refused',
    ]));

    $plugin->process($data);
  }

  /**
   * Test process with duplicate status.
   */
  public function testProcessDuplicate(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData();

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'report');
    $entity->nid = 124;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->moderation_status = 'duplicate';
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::SkippingTerminalEntity->format([
      '@uuid' => $entity->uuid(),
      '@status' => 'duplicate',
    ]));

    $plugin->process($data);
  }

  /**
   * Test process with archive status.
   */
  public function testProcessArchive(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData();

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'report');
    $entity->nid = 126;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->moderation_status = 'archive';
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::SkippingTerminalEntity->format([
      '@uuid' => $entity->uuid(),
      '@status' => 'archive',
    ]));

    $plugin->process($data);
  }

  /**
   * Test process skips save when the payload hash is unchanged.
   */
  public function testProcessUnchanged(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData();
    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'report');
    $entity->nid = 125;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->title = 'Original title';
    $entity->moderation_status = 'on-hold';
    $entity->set('field_post_api_hash', HashHelper::generateHash($data, ['provider', 'user']));
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $result = $plugin->process($data);
    $this->assertSame($entity, $result);
    $this->assertSame('Original title', $entity->label());
    $this->assertSame('on-hold', $entity->getModerationStatus());
  }

  /**
   * Test partial process updates only provided fields.
   */
  public function testProcessPartial(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $base = $this->getPostApiData();
    $provider = $this->getTestProvider();
    $uuid = $plugin->generateUuid($base['url']);

    $entity = $this->createEntity('node', 'report');
    $entity->nid = 126;
    $entity->uuid = $uuid;
    $entity->title = 'Original title';
    $entity->set('field_theme', []);
    $entity->set('field_headline', 1);
    $entity->set('field_headline_title', 'Keep me');
    $entity->moderation_status = 'published';
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $uuid, $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $data = [
      'partial' => TRUE,
      'uuid' => $uuid,
      'provider' => $provider->uuid(),
      'user' => $provider->getUserId(),
      'theme' => [4596],
    ];

    $result = $plugin->process($data);
    $this->assertSame($entity, $result);
    $this->assertSame('Original title', $entity->label());
    $this->assertSame('1', (string) $entity->field_headline->value);
    $this->assertSame('Keep me', $entity->field_headline_title->value);
  }

  /**
   * Test partial process fails when the document is missing.
   */
  public function testProcessPartialNotFound(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $provider = $this->getTestProvider();
    $uuid = '00000000-0000-4000-8000-000000000099';

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $uuid, NULL],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(DocumentNotFoundException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::DocumentNotFound->value);

    $plugin->process([
      'partial' => TRUE,
      'uuid' => $uuid,
      'provider' => $provider->uuid(),
      'user' => $provider->getUserId(),
      'theme' => [4596],
    ]);
  }

  /**
   * Test validate files with unallowed image download url.
   */
  public function testValidateFilesUnallowedImageUrl(): void {
    $data = $this->getPostApiData();
    $data['image']['download_url'] = 'https://wrong.test/test.jpg';

    // Unallowed image download URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::UnallowedTypeUrl->format([
      '@type' => 'image',
      '@url' => 'https://wrong.test/test.jpg',
    ]));
    $this->plugin->validateFiles($data);
  }

  /**
   * Test validate files rejects body uuid that does not match the map key.
   */
  public function testValidateFilesFileUuidKeyMismatch(): void {
    $data = $this->getPostApiData();
    $file_uuid = array_key_first($data['file']);
    $file = $data['file'][$file_uuid];
    $wrong_uuid = '22222222-2222-4222-8222-222222222222';
    $data['file'] = [$file_uuid => ['uuid' => $wrong_uuid] + $file];

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::FileUuidKeyMismatch->format([
      '@uuid' => $wrong_uuid,
      '@key' => $file_uuid,
    ]));
    $this->plugin->validateFiles($data);
  }

  /**
   * Test validate files rejects unknown attachment uuid without url on update.
   */
  public function testValidateFilesUnknownFileUuid(): void {
    $data = $this->getPostApiData();
    $unknown_uuid = '33333333-3333-4333-8333-333333333333';
    $data['uuid'] = '44444444-4444-4444-8444-444444444444';
    unset($data['file']);
    $data['file'] = [
      $unknown_uuid => [
        'uuid' => $unknown_uuid,
        'download_url' => 'https://test.test/unknown.pdf',
        'filename' => 'unknown.pdf',
        'checksum' => hash('sha256', 'unknown'),
      ],
    ];

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::UnknownFileUuid->format([
      '@uuid' => $unknown_uuid,
    ]));
    $this->plugin->validateFiles($data);
  }

  /**
   * Test validate files with unallowed file url.
   */
  public function testValidateFilesUnallowedFileUrl(): void {
    $data = $this->getPostApiData();
    $file_uuid = array_key_first($data['file']);
    $data['file'][$file_uuid]['download_url'] = 'https://wrong.test/test.pdf';

    // Unallowed file download URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::UnallowedTypeUrl->format([
      '@type' => 'file',
      '@url' => 'https://wrong.test/test.pdf',
    ]));
    $this->plugin->validateFiles($data);
  }

}
