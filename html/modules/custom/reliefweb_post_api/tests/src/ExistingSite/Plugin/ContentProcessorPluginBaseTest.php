<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Opis\JsonSchema\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Uid\Uuid;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the content processor plugin manager.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase
 *
 * @group reliefweb_post_api
 */
abstract class ContentProcessorPluginBaseTest extends ExistingSiteBase {

  /**
   * Test providers.
   *
   * @var array
   */
  protected array $providers = [];

  /**
   * Loaded POST API data.
   *
   * @var array
   */
  protected array $postApiData = [];

  /**
   * Content processor plugin manager.
   *
   * @var \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface
   */
  protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager;

  /**
   * The content processor plugin.
   *
   * @var \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface
   */
  protected ContentProcessorPluginInterface $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $provider = \Drupal::entityTypeManager()
      ->getStorage('reliefweb_post_api_provider')
      ->create([
        'id' => 666,
        'name' => 'test-provider',
        'uuid' => $this->getTestProviderUuid('test-provider'),
        'key' => 'test-provider-key',
        'status' => 1,
        'field_source' => [123],
        'field_user' => 2,
        'field_document_url' => ['https://test.test/'],
        'field_file_url' => ['https://test.test/'],
        'field_image_url' => ['https://test.test/'],
      ]);
    $provider->save();
    $this->markEntityForCleanup($provider);
    $this->providers['test-provider'] = $provider;

    $provider_any = \Drupal::entityTypeManager()
      ->getStorage('reliefweb_post_api_provider')
      ->create([
        'id' => 667,
        'name' => 'test-provider-any',
        'uuid' => $this->getTestProviderUuid('test-provider-any'),
        'key' => 'test-provider-any-key',
        'status' => 1,
      ]);
    $provider_any->save();
    $this->markEntityForCleanup($provider_any);
    $this->providers['test-provider-any'] = $provider_any;

    $provider_blocked = \Drupal::entityTypeManager()
      ->getStorage('reliefweb_post_api_provider')
      ->create([
        'id' => 668,
        'name' => 'test-provider-blocked',
        'uuid' => $this->getTestProviderUuid('test-provider-blocked'),
        'key' => 'test-provider-blocked-key',
        'status' => 0,
      ]);
    $provider_blocked->save();
    $this->markEntityForCleanup($provider_blocked);
    $this->providers['test-provider-blocked'] = $provider_blocked;

    $this->contentProcessorPluginManager = \Drupal::service('plugin.manager.reliefweb_post_api.content_processor');
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor(): void {
    $plugin = $this->createDummyPlugin();
    $this->assertInstanceOf(ContentProcessorPluginInterface::class, $plugin);
  }

  /**
   * @covers ::create
   */
  public function testCreate(): void {
    $plugin = $this->plugin::create(\Drupal::getContainer(), [], 'dummy', []);
    $this->assertInstanceOf(ContentProcessorPluginInterface::class, $plugin);
  }

  /**
   * @covers ::getPluginLabel
   */
  abstract public function testGetPluginLabel(): void;

  /**
   * @covers ::getEntityType
   */
  abstract public function testGetEntityType(): void;

  /**
   * @covers ::getBundle
   */
  abstract public function testGetBundle(): void;

  /**
   * @covers ::getLogger
   */
  public function testGetLogger(): void {
    $this->assertInstanceOf(LoggerInterface::class, $this->plugin->getLogger());
  }

  /**
   * @covers ::getSchemaValidator
   */
  public function testGetSchemaValidator(): void {
    $this->assertInstanceOf(Validator::class, $this->plugin->getSchemaValidator());
  }

  /**
   * @covers ::getJsonSchema
   */
  public function testGetJsonSchema(): void {
    // Valid schema.
    $schema = $this->plugin->getJsonSchema();
    $this->assertNotEmpty($schema);
  }

  /**
   * @covers ::getJsonSchema
   */
  public function testGetJsonSchemaInvalid(): void {
    $plugin = $this->createDummyPlugin();

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Missing dummy JSON schema');
    $plugin->getJsonSchema();
  }

  /**
   * @covers ::getProvider
   */
  public function testGetProvider(): void {
    $plugin = $this->createDummyPlugin();

    // Valid provider.
    $uuid = $this->getTestProviderUuid('test-provider');
    $provider = $plugin->getProvider($uuid);
    $this->assertInstanceOf(ProviderInterface::class, $provider);

    // Test getting provider from static cache.
    $provider = $plugin->getProvider($uuid);
    $this->assertInstanceOf(ProviderInterface::class, $provider);
  }

  /**
   * @covers ::getProvider
   */
  public function testGetProviderInvalidUuid(): void {
    $plugin = $this->createDummyPlugin();

    // Invalid provider UUID.
    $uuid = 'invalid';
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Invalid provider UUID.');
    $plugin->getProvider($uuid);
  }

  /**
   * @covers ::getProvider
   */
  public function testGetProviderBlocked(): void {
    $plugin = $this->createDummyPlugin();

    // Blocked provider.
    $uuid = $this->getTestProviderUuid('test-provider-blocked');
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Blocked provider.');
    $plugin->getProvider($uuid);
  }

  /**
   * @covers ::getProvider
   */
  public function testGetProviderUnknown(): void {
    $plugin = $this->createDummyPlugin();

    // Unknown provider.
    $uuid = $this->getTestProviderUuid('test-provider-unknown');
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Invalid provider.');
    $plugin->getProvider($uuid);
  }

  /**
   * @covers ::validate
   */
  public function testValidate(): void {
    $data = $this->getPostApiData();

    // Test valid data.
    $this->plugin->validate(['source' => [123]] + $data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::validate
   */
  public function testValidateInvalidSchema(): void {
    $data = $this->getPostApiData();

    // Test invalid schema.
    $this->expectException(ContentProcessorException::class);
    $this->plugin->validate(['source' => ''] + $data);
  }

  /**
   * @covers ::validate
   */
  public function testValidateInvalidSource(): void {
    $data = $this->getPostApiData();

    // Test invalid source.
    $this->expectException(ContentProcessorException::class);
    $this->plugin->validate(['source' => [456]] + $data);

  }

  /**
   * @covers ::validate
   */
  public function testValidateInvalidUrl(): void {
    $data = $this->getPostApiData();

    // Test invalid URL.
    $this->expectException(ContentProcessorException::class);
    $this->plugin->validate(['url' => ''] + $data);
  }

  /**
   * @covers ::validateSchema
   */
  public function testValidateSchema(): void {
    $data = $this->getPostApiData();

    // Valid data.
    $this->plugin->validateSchema($data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::validateSchema
   */
  public function testValidateSchemaUnicode(): void {
    $bundle = $this->plugin->getBundle();
    $file = __DIR__ . '/../../../data/data-' . $bundle . '-unicode.json';
    $data = json_decode(file_get_contents($file), TRUE);

    // Valid data.
    $this->plugin->validateSchema($data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::validateSchema
   */
  public function testValidateSchemaInvalid(): void {
    $data = $this->getPostApiData();

    // Invalid data.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('must match the type: string');
    $this->plugin->validateSchema(['url' => FALSE] + $data);
  }

  /**
   * @covers ::validateUuid
   */
  public function testValidateUuid(): void {
    $plugin = $this->createDummyPlugin();
    $data = ['url' => 'https://test.test'];
    $data['uuid'] = $plugin->generateUuid($data['url']);

    // Valid data.
    $plugin->validateUuid($data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::validateUuid
   */
  public function testValidateUuidMissingUrl(): void {
    $plugin = $this->createDummyPlugin();
    $data = [];

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Missing document URL');
    $plugin->validateUuid($data);
  }

  /**
   * @covers ::validateUuid
   */
  public function testValidateUuidMissingUuid(): void {
    $plugin = $this->createDummyPlugin();
    $data = ['url' => 'https://test.test'];

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Missing document UUID');
    $plugin->validateUuid($data);
  }

  /**
   * @covers ::validateUuid
   */
  public function testValidateUuidInvalidUuid(): void {
    $plugin = $this->createDummyPlugin();
    $data = ['url' => 'https://test.test'];
    $data['uuid'] = 'abc';

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Invalid document UUID');
    $plugin->validateUuid($data);
  }

  /**
   * @covers ::validateUuid
   */
  public function testValidateUuidMismatchingUuid(): void {
    $plugin = $this->createDummyPlugin();
    $data = ['url' => 'https://test.test'];
    $data['uuid'] = $plugin->generateUuid('test');

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('The UUID does not match the one generated from the URL');
    $plugin->validateUuid($data);
  }

  /**
   * @covers ::validateSources
   */
  public function testValidateSources(): void {
    $data = $this->getPostApiData();

    // Allowed source.
    $this->plugin->validateSources(['source' => [123]] + $data);
    $this->assertTrue(TRUE);

    // Any source allowed.
    $this->plugin->validateSources([
      'provider' => $this->getTestProvider('test-provider-any')->uuid(),
      'source' => ['789'],
    ] + $data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::validateSources
   */
  public function testValidateSourcesMissingSource(): void {
    $data = $this->getPostApiData();

    // Missing source.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Unallowed source(s)');
    $this->plugin->validateSources(['source' => []] + $data);
  }

  /**
   * @covers ::validateSources
   */
  public function testValidateSourcesUnallowedSource(): void {
    $data = $this->getPostApiData();

    // Unallowed source.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Unallowed source(s)');
    $this->plugin->validateSources(['source' => [456]] + $data);
  }

  /**
   * @covers ::validateSources
   */
  public function testValidateSourcesUnallowedExtraSource(): void {
    $data = $this->getPostApiData();

    // Unallowed extra source.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Unallowed source(s)');
    $this->plugin->validateSources(['source' => [123, 456]] + $data);
  }

  /**
   * @covers ::validateUrls
   */
  public function testValidateUrls(): void {
    $data = $this->getPostApiData();

    // Allowed URLs.
    $this->plugin->validateUrls($data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::validateUrls
   */
  public function testValidateUrlsAny(): void {
    $data = [
      'provider' => $this->getTestProviderUuid('test-provider-any'),
      'url' => 'https://test-any.test/anything',
    ];

    // Any URL allowed.
    $this->plugin->validateUrls($data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::validateUrls
   */
  public function testValidateUrlsEmptyDocumentUrl(): void {
    $data = $this->getPostApiData();

    // Empty URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Missing document URL');
    $this->plugin->validateUrls(['url' => ''] + $data);
  }

  /**
   * @covers ::validateUrls
   */
  public function testValidateUrlsUnallowedDocumentUrl(): void {
    $data = $this->getPostApiData();

    // Unallowed URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Unallowed document URL');
    $this->plugin->validateUrls(['url' => 'https://wrong.test/'] + $data);
  }

  /**
   * @covers \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase::validateUrls
   */
  public function testValidateUrlsBase(): void {
    $plugin = $this->createDummyPlugin(use_plugin_class: FALSE);
    $data = [
      'provider' => $this->getTestProviderUuid('test-provider'),
      'url' => 'https://test.test/anything',
    ];

    // Allowed URLs.
    $plugin->validateUrls($data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase::validateUrls
   */
  public function testValidateUrlsBaseAny(): void {
    $plugin = $this->createDummyPlugin(use_plugin_class: FALSE);
    $data = [
      'provider' => $this->getTestProviderUuid('test-provider-any'),
      'url' => 'https://test-any.test/anything',
    ];

    // Allowed URLs.
    $plugin->validateUrls($data);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase::validateUrls
   */
  public function testValidateUrlsBaseEmptyDocumentUrl(): void {
    $plugin = $this->createDummyPlugin(use_plugin_class: FALSE);
    $data = [
      'provider' => $this->getTestProviderUuid('test-provider-any'),
    ];

    // Empty URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Missing document URL');
    $plugin->validateUrls($data);
  }

  /**
   * @covers \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase::validateUrls
   */
  public function testValidateUrlsBaseUnallowedDocumentUrl(): void {
    $plugin = $this->createDummyPlugin(use_plugin_class: FALSE);
    $data = [
      'provider' => $this->getTestProviderUuid('test-provider'),
      'url' => 'https://wrong.test/',
    ];

    // Unallowed URL.
    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage('Unallowed document URL');
    $plugin->validateUrls($data);
  }

  /**
   * @covers ::validateUrl
   */
  public function testValidateUrl(): void {
    $this->assertTrue($this->plugin->validateUrl('https://test.test/test', '#^https://test\.test/#'));
  }

  /**
   * @covers ::sanitizeTerms
   */
  public function testSanitizeTerms(): void {
    $statement = $this->createStatementMock('fetchAllKeyed', [123 => 123]);
    $select = $this->createSelectMock($statement);
    $database = $this->createDatabaseMock($select);

    $plugin = $this->createDummyPlugin(services: [
      'database' => $database,
    ]);

    $terms = $plugin->sanitizeTerms('test', [123]);
    $this->assertSame([123 => 123], $terms);

    $terms = $plugin->sanitizeTerms('test', [456]);
    $this->assertSame([], $terms);

    $terms = $plugin->sanitizeTerms('test', []);
    $this->assertSame([], $terms);
  }

  /**
   * @covers ::sanitizeString
   */
  public function testSanitizeString(): void {
    $this->assertSame('', $this->plugin->sanitizeString(''));
    $this->assertSame('Test something', $this->plugin->sanitizeString('Test   something'));
  }

  /**
   * @covers ::sanitizeText
   */
  public function testSanitizeText(): void {
    $this->assertSame('', $this->plugin->sanitizeText(''));
    $this->assertSame('', $this->plugin->sanitizeText(' '));
    $this->assertSame('', $this->plugin->sanitizeText("\n"));
    $this->assertSame('test something', $this->plugin->sanitizeText('test   something'));
    $this->assertSame('**test**', $this->plugin->sanitizeText('**test**'));
    $this->assertSame('**test**', $this->plugin->sanitizeText('<strong>test</strong>'));
    $this->assertSame('test', $this->plugin->sanitizeText('<small>test</small>'));
  }

  /**
   * @covers ::sanitizeDate
   */
  public function testSanitizeDate(): void {
    $date = '2024-02-01T17:00:00-09:00';
    $this->assertSame('', $this->plugin->sanitizeDate(''));
    $this->assertSame('', $this->plugin->sanitizeDate('invalid'));
    $this->assertSame('2024-02-02', $this->plugin->sanitizeDate($date));
    $this->assertSame('2024-02-02T02:00:00', $this->plugin->sanitizeDate($date, FALSE));
  }

  /**
   * @covers ::sanitizeUrl
   */
  public function testSanitizeUrl(): void {
    $pattern = '#^https://#';
    $this->assertSame('', $this->plugin->sanitizeUrl('', $pattern));
    $this->assertSame('', $this->plugin->sanitizeUrl('test', $pattern));
    $this->assertSame('https://test.test', $this->plugin->sanitizeUrl('https://test.test', $pattern));
  }

  /**
   * @covers ::setField
   */
  public function testSetField(): void {
    $entity = $this->createEntity('node', 'report');

    // Unknown field.
    $this->plugin->setField($entity, 'test', 'test');
    $this->assertTrue(TRUE);

    // NULL.
    $field_name = 'title';
    $this->plugin->setField($entity, $field_name, NULL);
    $this->assertTrue($entity->get($field_name)->isEmpty());

    // Single value.
    $field_name = 'title';
    $this->plugin->setField($entity, $field_name, 'test');
    $this->assertSame('test', $entity->get($field_name)->value);

    // Multiple values.
    $field_name = 'field_country';
    $this->plugin->setField($entity, $field_name, [123, 456]);
    $this->assertSame(123, $entity->get($field_name)->get(0)->target_id);
    $this->assertSame(456, $entity->get($field_name)->get(1)->target_id);
  }

  /**
   * @covers ::setStringField
   */
  public function testSetStringField(): void {
    $entity = $this->createEntity('node', 'report');

    $field_name = 'title';
    $this->plugin->setStringField($entity, $field_name, 'test');
    $this->assertSame('test', $entity->get($field_name)->value);
  }

  /**
   * @covers ::setTextField
   */
  public function testSetTextField(): void {
    $entity = $this->createEntity('node', 'report');
    $field_name = 'body';

    $this->plugin->setTextField($entity, $field_name, 'test');
    $this->assertSame('test', $entity->get($field_name)->value);
    $this->assertSame(NULL, $entity->get($field_name)->first()->format);

    $this->plugin->setTextField($entity, $field_name, '<h1>test</h1>', 3);
    $this->assertSame('### test', $entity->get($field_name)->value);
    $this->assertSame(NULL, $entity->get($field_name)->first()->format);

    $this->plugin->setTextField($entity, $field_name, '<h1>test</h1>', 3, 'markdown');
    $this->assertSame('### test', $entity->get($field_name)->value);
    $this->assertSame('markdown', $entity->get($field_name)->first()->format);
  }

  /**
   * @covers ::setDateField
   */
  public function testSetDateField(): void {
    $entity = $this->createEntity('node', 'report');
    $date = '2024-02-01T17:00:00-09:00';

    $field_name = 'field_original_publication_date';
    $this->plugin->setDateField($entity, $field_name, $date);
    $this->assertSame('2024-02-02', $entity->get($field_name)->value);

    $field_name = 'field_embargo_date';
    $this->plugin->setDateField($entity, $field_name, $date, FALSE);
    $this->assertSame('2024-02-02T02:00:00', $entity->get($field_name)->value);
  }

  /**
   * @covers ::setTermField
   */
  public function testSetTermField(): void {
    $statement = $this->createStatementMock('fetchAllKeyed', [123 => 123]);
    $select = $this->createSelectMock($statement);
    $database = $this->createDatabaseMock($select);

    $plugin = $this->createDummyPlugin(services: [
      'database' => $database,
    ]);

    $entity = $this->createEntity('node', 'report');

    $field_name = 'field_country';
    $plugin->setTermField($entity, $field_name, 'country', [123]);
    $this->assertSame(123, $entity->get($field_name)->first()->target_id);
  }

  /**
   * @covers ::setUrlField
   */
  public function testSetUrlField(): void {
    $entity = $this->createEntity('node', 'report');

    $field_name = 'field_origin_notes';
    $this->plugin->setUrlField($entity, $field_name, 'https://test.test', '#^https://#');
    $this->assertSame('https://test.test', $entity->get($field_name)->value);
  }

  /**
   * @covers ::setReliefWebFileField
   */
  public function testSetReliefWebField(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);
    $http_client = $this->createMock(Client::class);

    $plugin = $this->createDummyPlugin(services: [
      'entity.repository' => $entity_repository,
      'http_client' => $http_client,
    ]);

    $entity = $this->createEntity('node', 'report');
    $entity->uuid = $plugin->generateUuid('test-node');
    $item_definition = $entity->field_file->getItemDefinition();

    $data1 = [
      'url' => 'https://test.test/test1.pdf',
      'checksum' => hash('sha256', 'test1'),
      'description' => 'test file1',
    ];

    $data2 = [
      'url' => 'https://test.test/test2.pdf',
      'checksum' => hash('sha256', 'test2'),
      'description' => 'test file2',
    ];

    $uuid1 = $plugin->generateUuid($data1['url'], $entity->uuid());
    $uuid2 = $plugin->generateUuid($data2['url'], $entity->uuid());

    $file_uuid1 = $plugin->generateUuid($uuid1 . $data1['checksum'], $entity->uuid());
    $file_uuid2 = $plugin->generateUuid($uuid2 . $data1['checksum'], $entity->uuid());

    // No file URI on purpose to skip the ReliefWebFile::getFilePageCount() and
    // prevent a warning because the file doesn't exist.
    $file1 = $this->createEntity('file', 'file');
    $file1->uuid = $file_uuid1;
    $file1->setFilename('test1.pdf');
    $file1->setMimeType('application/pdf');
    $file1->setSize(4);

    $item1 = ReliefWebFile::createInstance($item_definition);
    $item1->setValue([
      'uuid' => $uuid1,
      'revision_id' => 0,
      'file_uuid' => $file1->uuid(),
      'file_name' => $file1->getFilename(),
      'file_mime' => $file1->getMimeType(),
      'file_size' => $file1->getSize(),
      'page_count' => 1,
      'description' => 'item1',
    ]);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['file', $file_uuid1, $file1],
        ['file', $file_uuid2, NULL],
      ]);

    $http_client->expects($this->any())
      ->method('get')
      ->willThrowException(new \Exception('test'));

    // Test existing file is removed if no file is provided.
    $entity->field_file->setValue([$item1->getValue()]);
    $plugin->setReliefWebFileField($entity, 'field_file', []);
    $this->assertTrue($entity->field_file->isEmpty());

    // Test existing file is removed if no valid file is provided.
    $entity->field_file->setValue([$item1->getValue()]);
    $plugin->setReliefWebFileField($entity, 'field_file', [
      ['url' => 'missing-checksum-test'],
    ]);
    $this->assertTrue($entity->field_file->isEmpty());

    // Test existing file is removed if no file is provided.
    $entity->field_file->setValue([$item1->getValue()]);
    $plugin->setReliefWebFileField($entity, 'field_file', [$data1]);
    $this->assertSame($data1['description'], $entity->field_file->first()->description);

    // Test new file.
    $entity->field_file->setValue(NULL);
    $plugin->setReliefWebFileField($entity, 'field_file', [$data1]);
    $this->assertSame($data1['description'], $entity->field_file->first()->description);

    // Test new file that cannot be retrieved.
    $entity->field_file->setValue(NULL);
    $plugin->setReliefWebFileField($entity, 'field_file', [$data2]);
    $this->assertTrue($entity->field_file->isEmpty());

  }

  /**
   * @covers ::setReliefWebFileField
   */
  public function testSetReliefWebFieldUnknownField(): void {
    $plugin = $this->createDummyPlugin();

    $entity = $this->createEntity('node', 'report');

    // Unknown field, nothing happens.
    $plugin->setReliefWebFileField($entity, 'unknown_field', []);
    $this->assertTrue($entity->field_image->isEmpty());
  }

  /**
   * @covers ::setReliefWebFileField
   */
  public function testSetReliefWebFieldNoFiles(): void {
    $plugin = $this->createDummyPlugin();

    $entity = $this->createEntity('node', 'report');

    // Unknown field, nothing happens.
    $plugin->setReliefWebFileField($entity, 'field_file', []);
    $this->assertTrue($entity->field_image->isEmpty());
  }

  /**
   * @covers ::setImageField
   */
  public function testSetImageField(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_storage = $this->createMock(EntityStorageInterface::class);
    $http_client = $this->createMock(Client::class);

    $plugin = $this->createDummyPlugin(services: [
      'entity_type.manager' => $entity_type_manager,
      'entity.repository' => $entity_repository,
      'http_client' => $http_client,
    ]);

    $entity = $this->createEntity('node', 'report');
    $entity->uuid = $plugin->generateUuid('test-node');

    $data1 = [
      'url' => 'https://test.test/test1.png',
      'checksum' => hash('sha256', 'test1'),
      'description' => 'test image1',
    ];

    $data2 = [
      'url' => 'https://test.test/test2.png',
      'checksum' => hash('sha256', 'test2'),
      'description' => 'test image2',
    ];

    $data3 = [
      'url' => 'https://test.test/test3.png',
      'checksum' => hash('sha256', 'test3'),
      'description' => 'test image3',
    ];

    $data4 = [
      'url' => 'https://test.test/test4.png',
      'checksum' => hash('sha256', 'test4'),
      'description' => 'test image4',
    ];

    $media_uuid1 = $plugin->generateUuid($data1['checksum'] . $data1['url'], $entity->uuid());
    $media_uuid2 = $plugin->generateUuid($data2['checksum'] . $data2['url'], $entity->uuid());
    $media_uuid3 = $plugin->generateUuid($data3['checksum'] . $data3['url'], $entity->uuid());
    $media_uuid4 = $plugin->generateUuid($data4['checksum'] . $data4['url'], $entity->uuid());

    $media1 = $this->createEntity('media', 'image_report');
    $media1->mid = 12;
    $media1->uuid = $media_uuid1;

    $media2 = $this->createEntity('media', 'image_report');
    $media2->mid = 34;
    $media2->uuid = $media_uuid2;

    $media3 = $this->createEntity('media', 'image_report');
    $media3->mid = 56;
    $media3->uuid = $media_uuid3;

    $media4 = $this->createEntity('media', 'image_report');
    $media4->mid = 78;
    $media4->uuid = $media_uuid4;

    $file1 = $this->createEntity('file', 'file');
    $file1->uuid = $plugin->generateUuid($media_uuid1, $media_uuid1);
    $file1->setFilename('test1.png');
    $file1->setMimeType('image/png');
    $file1->setFileUri('public://test1.png');
    $file1->setSize(4);

    $file2 = $this->createEntity('file', 'file');
    $file2->uuid = $plugin->generateUuid($media_uuid2, $media_uuid2);
    $file2->setFilename('test2.png');
    $file2->setMimeType('image/png');
    $file2->setFileUri('public://test2.png');
    $file2->setSize(4);

    $file3 = $this->createEntity('file', 'file');
    $file3->uuid = $plugin->generateUuid($media_uuid3, $media_uuid3);
    $file3->setFilename('test3.png');
    $file3->setMimeType('image/png');
    $file3->setFileUri('public://test3.png');
    $file3->setSize(4);

    $map = [
      $media1->uuid() => $media1,
      $media2->uuid() => $media2,
      $media3->uuid() => $media3,
      $media4->uuid() => $media4,
      $file1->uuid() => $file1,
      $file2->uuid() => $file2,
      $file3->uuid() => $file3,
    ];

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['media', $media_uuid1, NULL],
        ['media', $media_uuid2, $media2],
        ['media', $media_uuid3, $media3],
        ['media', $media_uuid4, $media4],
        ['file', $file1->uuid(), $file1],
        ['file', $file2->uuid(), $file2],
        ['file', $file3->uuid(), $file3],
      ]);

    $entity_type_manager->expects($this->any())
      ->method('getStorage')
      ->willReturn($entity_storage);

    $entity_storage->expects($this->any())
      ->method('create')
      ->willReturnCallback(function (array $values) use ($map): ?MediaInterface {
        return $map[$values['uuid']] ?? NULL;
      });

    $http_client->expects($this->any())
      ->method('get')
      ->willThrowException(new \Exception('test'));

    // Test new media.
    $plugin->setImageField($entity, 'field_image', $data1);
    $this->assertSame($media_uuid1, $entity->field_image->first()->entity->uuid());
    $this->assertSame('test image1', $entity->field_image->first()->entity->field_description->value);

    // Test different media.
    $entity->field_image->setValue($media1);
    $plugin->setImageField($entity, 'field_image', $data2);
    $this->assertSame($media_uuid2, $entity->field_image->first()->entity->uuid());
    $this->assertSame('test image2', $entity->field_image->first()->entity->field_description->value);

    // Test existing media.
    $entity->field_image->setValue($media3);
    $plugin->setImageField($entity, 'field_image', $data3);
    $this->assertSame($media_uuid3, $entity->field_image->first()->entity->uuid());
    $this->assertSame('test image3', $entity->field_image->first()->entity->field_description->value);

    // Test file creation failure.
    $plugin->setImageField($entity, 'field_image', $data4);
    $this->assertTrue($entity->field_image->isEmpty());
  }

  /**
   * @covers ::setImageField
   */
  public function testSetImageFieldUnknownField(): void {
    $plugin = $this->createDummyPlugin();

    $entity = $this->createEntity('node', 'report');

    // Unknown field, nothing happens.
    $plugin->setImageField($entity, 'unknown_field', [
      'url' => 'test',
      'checksum' => 'test',
    ]);
    $this->assertTrue($entity->field_image->isEmpty());
  }

  /**
   * @covers ::setImageField
   */
  public function testSetImageFieldMissingImageProperties(): void {
    $plugin = $this->createDummyPlugin();

    $entity = $this->createEntity('node', 'report');

    // Missing checksum or URL nothing happens.
    $plugin->setImageField($entity, 'field_image', []);
    $this->assertTrue($entity->field_image->isEmpty());
  }

  /**
   * @covers ::createImageMedia
   */
  public function testCreateImageMedia(): void {
    $file = $this->createConfiguredMock(File::class, [
      'uuid' => 'da5b8893-d6ca-5c1c-9a9c-91f40a2a3649',
      'getFilename' => 'test.png',
      'getMimeType' => 'image/png',
      'getFileUri' => 'public://test.png',
      'getSize' => 4,
      'setTemporary' => TRUE,
      'save' => TRUE,
    ]);

    $entity_repository = $this->createConfiguredMock(EntityRepositoryInterface::class, [
      'loadEntityByUuid' => $file,
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'entity.repository' => $entity_repository,
    ]);

    $media = $plugin->createImageMedia(
      bundle: 'image_report',
      uuid: 'bda0e2da-4229-53aa-9206-db72dfdac519',
      url: 'https://test.test/test.png',
      checksum: hash('sha256', 'test'),
      mimetype: 'image/png',
      max_size: '8B',
      alt: 'test image',
    );
    $this->assertInstanceOf(MediaInterface::class, $media);
    $this->assertSame('test image', $media->get('field_media_image')->first()->alt);
  }

  /**
   * @covers ::createReliefWebFileFieldItem
   */
  public function testCreateReliefWebFileFieldItem(): void {
    $file = $this->createConfiguredMock(File::class, [
      'uuid' => 'da5b8893-d6ca-5c1c-9a9c-91f40a2a3649',
      'getFilename' => 'test.pdf',
      'getMimeType' => 'application/pdf',
      'getSize' => 4,
      'setTemporary' => TRUE,
      'save' => TRUE,
    ]);

    $file_system = $this->createConfiguredMock(FileSystemInterface::class, [
      'dirname' => 'test',
      'prepareDirectory' => TRUE,
      'saveData' => TRUE,
      'unlink' => TRUE,
    ]);

    $entity_repository = $this->createConfiguredMock(EntityRepositoryInterface::class, [
      'loadEntityByUuid' => $file,
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file_system' => $file_system,
      'entity.repository' => $entity_repository,
    ]);

    $entity = $this->createEntity('node', 'report');
    $definition = $entity->get('field_file')->getItemDefinition();

    $item = $plugin->createReliefWebFileFieldItem(
      definition: $definition,
      uuid: 'bda0e2da-4229-53aa-9206-db72dfdac519',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', 'test'),
      mimetype: 'application/pdf',
      max_size: '8B',
    );
    $this->assertInstanceOf(ReliefWebFile::class, $item);
  }

  /**
   * @covers ::createReliefWebFileFieldItem
   */
  public function testCreateReliefWebFileFieldItemExceptionValidation(): void {
    $file = $this->createConfiguredMock(File::class, [
      // This will cause a validation error on the `file_uuid` property of
      // the ReliefWebFile field item.
      'uuid' => NULL,
      'getFilename' => str_pad('test.pdf', 300, "a", \STR_PAD_LEFT),
      'getMimeType' => 'application/pdf',
      'getSize' => 4,
      'setTemporary' => TRUE,
      'save' => TRUE,
    ]);

    $file_system = $this->createConfiguredMock(FileSystemInterface::class, [
      'dirname' => 'test',
      'prepareDirectory' => TRUE,
      'saveData' => TRUE,
      'unlink' => TRUE,
    ]);

    $entity_repository = $this->createConfiguredMock(EntityRepositoryInterface::class, [
      'loadEntityByUuid' => $file,
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file_system' => $file_system,
      'entity.repository' => $entity_repository,
    ]);

    $entity = $this->createEntity('node', 'report');
    $definition = $entity->get('field_file')->getItemDefinition();

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid field item data');

    $plugin->createReliefWebFileFieldItem(
      definition: $definition,
      uuid: 'bda0e2da-4229-53aa-9206-db72dfdac519',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', 'test'),
      mimetype: 'application/pdf',
      max_size: '8B',
    );
  }

  /**
   * @covers ::createFile
   */
  public function testCreateFile(): void {
    $file_system = $this->createConfiguredMock(FileSystemInterface::class, [
      'dirname' => 'test',
      'prepareDirectory' => TRUE,
      'saveData' => TRUE,
      'unlink' => TRUE,
    ]);

    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'application/pdf',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file_system' => $file_system,
      'http_client' => $client,
    ]);

    $this->assertInstanceOf(FileInterface::class, $plugin->createFile(
      uuid: $plugin->generateUuid('test'),
      uri: 'public://test.pdf',
      name: 'test.pdf',
      mimetype: 'application/pdf',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', 'test'),
      max_size: '8B',
      validators: []
    ));
  }

  /**
   * @covers ::createFile
   */
  public function testCreateFileExisting(): void {
    $entity_repository = $this->createConfiguredMock(EntityRepositoryInterface::class, [
      'loadEntityByUuid' => $this->createMock(FileInterface::class),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'entity.repository' => $entity_repository,
    ]);

    $this->assertInstanceOf(FileInterface::class, $plugin->createFile(
      uuid: $plugin->generateUuid('test'),
      uri: 'public://test.pdf',
      name: 'test.pdf',
      mimetype: 'application/pdf',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', 'test'),
      max_size: '8B',
      validators: []
    ));
  }

  /**
   * @covers ::createFile
   */
  public function testCreateFileEmptyContent(): void {
    $file_system = $this->createConfiguredMock(FileSystemInterface::class, [
      'dirname' => 'test',
      'prepareDirectory' => FALSE,
      'saveData' => TRUE,
      'unlink' => TRUE,
    ]);

    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'application/pdf',
      ], ''),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file_system' => $file_system,
      'http_client' => $client,
    ]);

    $this->assertNull($plugin->createFile(
      uuid: $plugin->generateUuid('test'),
      uri: 'public://test.pdf',
      name: 'test.pdf',
      mimetype: 'application/pdf',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', ''),
      max_size: '8B',
      validators: []
    ));
  }

  /**
   * @covers ::createFile
   */
  public function testCreateFileExceptionCreateDirectory(): void {
    $file_system = $this->createConfiguredMock(FileSystemInterface::class, [
      'dirname' => 'test',
      'prepareDirectory' => FALSE,
      'saveData' => TRUE,
      'unlink' => TRUE,
    ]);

    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'application/pdf',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file_system' => $file_system,
      'http_client' => $client,
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to create the destination directory');

    $plugin->createFile(
      uuid: $plugin->generateUuid('test'),
      uri: 'public://test.pdf',
      name: 'test.pdf',
      mimetype: 'application/pdf',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', 'test'),
      max_size: '8B',
      validators: []
    );
  }

  /**
   * @covers ::createFile
   */
  public function testCreateFileExceptionSaveData(): void {
    $file_system = $this->createConfiguredMock(FileSystemInterface::class, [
      'dirname' => 'test',
      'prepareDirectory' => TRUE,
      'saveData' => FALSE,
      'unlink' => TRUE,
    ]);

    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'application/pdf',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file_system' => $file_system,
      'http_client' => $client,
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to copy the file');

    $plugin->createFile(
      uuid: $plugin->generateUuid('test'),
      uri: 'public://test.pdf',
      name: 'test.pdf',
      mimetype: 'application/pdf',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', 'test'),
      max_size: '8B',
      validators: []
    );
  }

  /**
   * @covers ::createFile
   */
  public function testCreateFileExceptionValidation(): void {
    $file_system = $this->createConfiguredMock(FileSystemInterface::class, [
      'dirname' => 'test',
      'prepareDirectory' => TRUE,
      'saveData' => TRUE,
      'unlink' => TRUE,
    ]);

    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'application/pdf',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file_system' => $file_system,
      'http_client' => $client,
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid file');

    $plugin->createFile(
      uuid: $plugin->generateUuid('test'),
      uri: 'public://test.pdf',
      name: str_pad('test.pdf', 300, "a", \STR_PAD_LEFT),
      mimetype: 'application/pdf',
      url: 'https://test.test/test.pdf',
      checksum: hash('sha256', 'test'),
      max_size: '8B',
      validators: ['FileNameLength' => []]
    );
  }

  /**
   * @covers ::getRemoteFileContent
   */
  public function testGetRemoteFileContent(): void {
    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'application/pdf',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'http_client' => $client,
    ]);

    $uri = 'https://test.test/test.pdf';
    $checksum = hash('sha256', 'test');
    $mimetype = 'application/pdf';
    $max_size = '8B';

    $content = $plugin->getRemoteFileContent($uri, $checksum, $mimetype, $max_size);
    $this->assertSame('test', $content);
  }

  /**
   * @covers ::getRemoteFileContent
   */
  public function testGetRemoteFileContentInvalidMimetype(): void {
    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'test',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'http_client' => $client,
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('File type is not "application/pdf".');
    $plugin->getRemoteFileContent('https://test.test/test.pdf', 'test', 'application/pdf', '8B');
  }

  /**
   * @covers ::getRemoteFileContent
   */
  public function testGetRemoteFileContentTooLargeHeader(): void {
    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 16,
        'Content-Type' => 'application/pdf',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'http_client' => $client,
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('File is too large.');
    $plugin->getRemoteFileContent('https://test.test/test.pdf', 'test', 'application/pdf', '8B');
  }

  /**
   * @covers ::getRemoteFileContent
   */
  public function testGetRemoteFileContentTooLargeContent(): void {
    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Type' => 'application/pdf',
      ], 'testtest'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'http_client' => $client,
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('File is too large.');
    $plugin->getRemoteFileContent('https://test.test/test.pdf', 'test', 'application/pdf', '4B');
  }

  /**
   * @covers ::getRemoteFileContent
   */
  public function testGetRemoteFileContentInvalidChecksum(): void {
    $client = $this->createHttpClientMock([
      new Response(200, [
        'Content-Length' => 4,
        'Content-Type' => 'application/pdf',
      ], 'test'),
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'http_client' => $client,
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid file checksum.');
    $plugin->getRemoteFileContent('https://test.test/test.pdf', 'test', 'application/pdf', '8B');
  }

  /**
   * @covers ::validateFile
   */
  public function testValidateFile(): void {
    $file = $this->createEntity('file', 'file');
    $file->setFileUri('public:://test.pdf');
    $file->setFileName('test.pdf');

    $validators = [];
    $this->assertSame([], $this->plugin->validateFile($file, $validators));

    $validators = ['FileNameLength' => []];
    $this->assertSame([], $this->plugin->validateFile($file, $validators));

    $file->setFileName(str_pad($file->getFileName(), 300, "a", \STR_PAD_LEFT));
    $this->assertStringContainsString('name exceeds', (string) $this->plugin->validateFile($file, $validators)[0]);
  }

  /**
   * @covers ::generateUuid
   */
  public function testGenerateUuid(): void {
    $uuid = 'c1cd5878-f50e-5b94-b8ed-029bd92ab1af';
    $this->assertSame($uuid, $this->plugin->generateUuid('https://test.test'));
  }

  /**
   * @covers ::guessFileMimeType
   */
  public function testGuessFileMimeType(): void {
    $uri = 'public://test.pdf';
    $this->assertSame('application/pdf', $this->plugin->guessFileMimeType($uri));
    $this->assertSame('application/pdf', $this->plugin->guessFileMimeType($uri, ['application/pdf']));
  }

  /**
   * @covers ::guessFileMimeType
   */
  public function testGuessFileMimeTypeUnknown(): void {
    $mime_type_guesser = $this->createConfiguredMock(MimeTypeGuesserInterface::class, [
      'guessMimeType' => NULL,
    ]);

    $plugin = $this->createDummyPlugin(services: [
      'file.mime_type.guesser' => $mime_type_guesser,
    ]);

    $uri = 'public://test.dummy';
    $this->expectException(ContentProcessorException::class);
    $plugin->guessFileMimeType($uri);
  }

  /**
   * @covers ::guessFileMimeType
   */
  public function testGuessFileMimeTypeUnallowed(): void {
    $uri = 'public://test.pdf';
    $this->expectException(ContentProcessorException::class);
    $this->plugin->guessFileMimeType($uri, ['image/png']);
  }

  /**
   * @covers ::getDefaultLangcode
   */
  public function testGetDefaultLangcode(): void {
    $this->assertSame(\Drupal::languageManager()->getDefaultLanguage()->getId(), $this->plugin->getDefaultLangcode());
  }

  /**
   * Create a mock of a select query statement.
   *
   * @param string $method
   *   The method to call on the statement object.
   * @param mixed $value
   *   The value to be returned.
   *
   * @return \Drupal\Core\Database\StatementInterface
   *   The statement mock.
   */
  protected function createStatementMock(string $method, mixed $value): StatementInterface {
    $statement = $this->createMock(StatementInterface::class);

    $statement->expects($this->any())
      ->method($method)
      ->willReturn($value);

    return $statement;
  }

  /**
   * Create a mock of a select query.
   *
   * @param \Drupal\Core\Database\StatementInterface $statement
   *   The statement to return when executing the query.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select query.
   */
  protected function createSelectMock(StatementInterface $statement): SelectInterface {
    $select = $this->createMock(SelectInterface::class);

    $select->expects($this->any())
      ->method('fields')
      ->will($this->returnSelf());

    $select->expects($this->any())
      ->method('condition')
      ->will($this->returnSelf());

    $select->expects($this->any())
      ->method('execute')
      ->willReturn($statement);

    return $select;
  }

  /**
   * Create a mock of a database connection.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $select
   *   The select query to return when calling select().
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection.
   */
  protected function createDatabaseMock(SelectInterface $select): Connection {
    $connection = $this->createMock(Connection::class);

    $connection->expects($this->any())
      ->method('select')
      ->willReturn($select);

    return $connection;
  }

  /**
   * Create a mock of an HTTP client.
   *
   * @param array $responses
   *   List of responses.
   *
   * @return \GuzzleHttp\ClientInterface
   *   The client.
   */
  protected function createHttpClientMock(array $responses): ClientInterface {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    return new Client(['handler' => $handlerStack]);
  }

  /**
   * Create an entity.
   *
   * @param string|null $entity_type_id
   *   The entity type ID. Defaults to the one handled by the content processor
   *   plugin.
   * @param string|null $bundle
   *   The entity bundle. Defaults to the one handled by the content processor
   *   plugin.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity.
   */
  protected function createEntity(?string $entity_type_id = NULL, ?string $bundle = NULL): ContentEntityInterface {
    $entity_type_id = $entity_type_id ?? $this->plugin->getEntityType();
    $bundle = $bundle ?? $this->plugin->getBundle();

    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);

    $class = $storage->getEntityClass($bundle);

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged
    return eval("return new class([], '$entity_type_id', '$bundle') extends $class {
      public function save() { return NULL; }
    };");
  }

  /**
   * Create a dummy content processor plugin.
   *
   * @param array $definition
   *   Plugin definition.
   * @param array $services
   *   Service overrides.
   * @param bool $use_plugin_class
   *   Whether to use the same class the `$this->plugin` or use an anymous
   *   class.
   *
   * @return \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface
   *   The dummy plugin.
   */
  protected function createDummyPlugin(array $definition = [], array $services = [], bool $use_plugin_class = TRUE): ContentProcessorPluginInterface {
    $container = \drupal::getContainer();

    $definition += [
      'id' => 'reliefweb_post_api.content_processor.dummy',
      'label' => new TranslatableMarkup('Dummy content processor'),
      'entityType' => 'dummy',
      'bundle' => 'dummy',
      'resource' => 'dummies',
    ];

    $services = [
      $services['entity_type.manager'] ?? $container->get('entity_type.manager'),
      $services['entity.repository'] ?? $container->get('entity.repository'),
      $services['database'] ?? $container->get('database'),
      $services['logger.factory'] ?? $container->get('logger.factory'),
      $services['extension.path.resolver'] ?? $container->get('extension.path.resolver'),
      $services['http_client'] ?? $container->get('http_client'),
      $services['file_system'] ?? $container->get('file_system'),
      $services['file.validator'] ?? $container->get('file.validator'),
      $services['file.mime_type.guesser'] ?? $container->get('file.mime_type.guesser'),
      $services['language_manager'] ?? $container->get('language_manager'),
    ];

    if ($use_plugin_class) {
      return new ($this->plugin::class)([], $definition['id'], $definition, ...$services);
    }
    else {
      return new class([], $definition['id'], $definition, ...$services) extends ContentProcessorPluginBase {

        /**
         * {@inheritdoc}
         */
        public function process(array $data): ?ContentEntityInterface {
          return NULL;
        }

      };
    }
  }

  /**
   * Get some POST API test data.
   *
   * @param string $bundle
   *   The bundle of the data.
   * @param string $type
   *   The type of data: raw (string) or decoded (array).
   *
   * @return string|array
   *   The data as a JSON string.
   */
  protected function getPostApiData(string $bundle = 'report', string $type = 'decoded'): string|array {
    if (!isset($this->postApiData[$bundle])) {
      $file = __DIR__ . '/../../../data/data-' . $bundle . '.json';
      $raw = file_get_contents($file);
      $decoded = json_decode($raw, TRUE);
      $decoded['provider'] = $this->getTestProvider()->uuid();
      $decoded['bundle'] = $bundle;
      $this->postApiData[$bundle] = [
        'raw' => $raw,
        'decoded' => $decoded,
      ];
    }
    return $this->postApiData[$bundle][$type];
  }

  /**
   * Get the UUID of test data.
   *
   * @param string $bundle
   *   The bundle of the data.
   *
   * @return string
   *   UUID.
   */
  protected function getTestUuid(string $bundle = 'report'): string {
    return $this->getPostApiData('report', 'decoded')['uuid'];
  }

  /**
   * Get a provider based on its name.
   *
   * @param string $name
   *   The provider name.
   *
   * @return \Drupal\reliefweb_post_api\Entity\ProviderInterface
   *   The provider.
   */
  protected function getTestProvider(string $name = 'test-provider'): ProviderInterface {
    return $this->providers[$name];
  }

  /**
   * Get the UUID of a provider based on its name.
   *
   * @param string $name
   *   The provider name.
   *
   * @return string
   *   The provider UUID.
   */
  protected function getTestProviderUuid(string $name): string {
    return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), $name)->toRfc4122();
  }

  /**
   * Get the content for test file.
   *
   * @param string $file_name
   *   File name.
   *
   * @return string
   *   Content of the file.
   */
  protected function getFileContent(string $file_name): string {
    $file = __DIR__ . '/../../../data/data-' . $file_name;
    return file_get_contents($file);
  }

}
