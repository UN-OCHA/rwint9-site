<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Entity;

use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\reliefweb_post_api\Entity\Provider;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use GuzzleHttp\Client;
use Symfony\Component\ErrorHandler\BufferingLogger;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the provider entity.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Entity\Provider
 *
 * @group reliefweb_post_api
 */
class ProviderTest extends ExistingSiteBase {

  /**
   * Provider data.
   *
   * @var array
   */
  protected array $data = [
    'name' => 'test-provider',
    'uuid' => '7603a5e4-d168-4509-8979-f3c89c16f1f0',
    'key' => 'test-provider-key',
    'resource' => 'reports',
    'field_document_url' => ['https://test.test/', 'https://test1.test/'],
    'field_file_url' => ['https://test.test/'],
    'field_image_url' => ['https://test.test/'],
    'field_notify' => ['test@test.test'],
    'field_source' => [1503],
    'field_user' => 12,
    'field_resource_status' => 'pending',
    'field_webhook_url' => 'https://test.test',
    'field_quota' => 50,
    'field_rate_limit' => 60,
  ];

  /**
   * @covers ::baseFieldDefinitions
   */
  public function testBaseFieldDefinitions(): void {
    $entity_type = \Drupal::entityTypeManager()->getDefinition('reliefweb_post_api_provider');

    $definitions = Provider::baseFieldDefinitions($entity_type);
    $this->assertArrayHasKey('resource', $definitions);
  }

  /**
   * @covers ::getUrlPattern
   */
  public function testGetUrlPattern(): void {
    $data = $this->data;

    $parts = array_map('preg_quote', $data['field_document_url']);
    $pattern = '#^(' . implode('|', $parts) . ')#';
    $default = '#^https://.+#';

    // Normal data.
    $provider = $this->createProvider($data);
    $this->assertEquals($pattern, $provider->getUrlPattern('document'));

    // Unexisting type.
    $this->assertEquals($default, $provider->getUrlPattern('test'));

    // No data.
    unset($data['field_document_url']);
    $provider = $this->createProvider($data);
    $this->assertEquals($default, $provider->getUrlPattern('document'));

    // Empty base URLs.
    $data['field_document_url'] = [''];
    $provider = $this->createProvider($data);
    $this->assertEquals($default, $provider->getUrlPattern('document'));

    // Missing field.
    $provider = $this->createNoFieldProvider();
    $this->assertFalse($provider->hasField('field_document_url'));
    $this->assertEquals($default, $provider->getUrlPattern('document'));
  }

  /**
   * @covers ::getEmailsToNotify
   */
  public function testGetEmailsToNotify(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);
    $this->assertEquals($this->data['field_notify'], $provider->getEmailsToNotify());

    $data['field_notify'] = [];
    $provider = $this->createProvider($data);
    $this->assertEquals([], $provider->getEmailsToNotify());

    unset($data['field_notify']);
    $provider = $this->createProvider($data);
    $this->assertEquals([], $provider->getEmailsToNotify());

    // Missing field.
    $provider = $this->createNoFieldProvider();
    $this->assertFalse($provider->hasField('field_notify'));
    $this->assertEquals([], $provider->getEmailsToNotify());
  }

  /**
   * @covers ::getAllowedSources
   */
  public function testGetAllowedSources(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);
    $this->assertEquals($this->data['field_source'], array_values($provider->getAllowedSources()));

    $data['field_source'] = [];
    $provider = $this->createProvider($data);
    $this->assertEquals([], $provider->getAllowedSources());

    unset($data['field_source']);
    $provider = $this->createProvider($data);
    $this->assertEquals([], $provider->getAllowedSources());

    // Missing field.
    $provider = $this->createNoFieldProvider();
    $this->assertFalse($provider->hasField('field_source'));
    $this->assertEquals([], $provider->getAllowedSources());
  }

  /**
   * @covers ::getUserId
   */
  public function testGetUserId(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);
    $this->assertEquals($this->data['field_user'], $provider->getUserId());

    $data['field_user'] = [];
    $provider = $this->createProvider($data);
    $this->assertEquals(2, $provider->getUserId());

    unset($data['field_user']);
    $provider = $this->createProvider($data);
    $this->assertEquals(2, $provider->getUserId());

    // Missing field.
    $provider = $this->createNoFieldProvider();
    $this->assertFalse($provider->hasField('field_user'));
    $this->assertEquals(2, $provider->getUserId());
  }

  /**
   * @covers ::getDefaultResourceStatus
   */
  public function testGetDefaultResourceStatus(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);
    $this->assertEquals($this->data['field_resource_status'], $provider->getDefaultResourceStatus());

    $data['field_resource_status'] = [];
    $provider = $this->createProvider($data);
    $this->assertEquals('draft', $provider->getDefaultResourceStatus());

    // The default value is "pending" when the field is initialized.
    unset($data['field_resource_status']);
    $provider = $this->createProvider($data);
    $this->assertEquals('pending', $provider->getDefaultResourceStatus());

    // Missing field.
    $provider = $this->createNoFieldProvider();
    $this->assertFalse($provider->hasField('field_resource_status'));
    $this->assertEquals('draft', $provider->getDefaultResourceStatus());
  }

  /**
   * @covers ::getQuota
   */
  public function testGetQuota(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);
    $this->assertEquals($this->data['field_quota'], $provider->getQuota());

    $data['field_quota'] = [];
    $provider = $this->createProvider($data);
    $this->assertEquals(0, $provider->getQuota());

    // Default field value (set in the UI).
    unset($data['field_quota']);
    $provider = $this->createProvider($data);
    $this->assertEquals(50, $provider->getQuota());

    // Missing field.
    $provider = $this->createNoFieldProvider();
    $this->assertFalse($provider->hasField('field_quota'));
    $this->assertEquals(0, $provider->getQuota());
  }

  /**
   * @covers ::getRateLimit
   */
  public function testRateLimit(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);
    $this->assertEquals($this->data['field_rate_limit'], $provider->getRateLimit());

    $data['field_rate_limit'] = [];
    $provider = $this->createProvider($data);
    $this->assertEquals(0, $provider->getRateLimit());

    // Default field value (set in the UI).
    unset($data['field_rate_limit']);
    $provider = $this->createProvider($data);
    $this->assertEquals(60, $provider->getRateLimit());

    // Missing field.
    $provider = $this->createNoFieldProvider();
    $this->assertFalse($provider->hasField('field_rate_limit'));
    $this->assertEquals(0, $provider->getRateLimit());
  }

  /**
   * @covers ::validateKey
   */
  public function testValidateKey(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);
    // The value is only hashed when saved.
    $provider->key->first()->preSave();
    $this->assertTrue($provider->validateKey($this->data['key']));
    $this->assertFalse($provider->validateKey('wrong'));
    $this->assertFalse($provider->validateKey(''));

    // No key.
    unset($data['key']);
    $provider = $this->createProvider($data);
    $this->assertFalse($provider->validateKey($this->data['key']));
  }

  /**
   * @covers ::findTrustedUserIdFromApiKey
   */
  public function testFindTrustedUserIdFromApiKey(): void {
    $data = $this->data;

    // Test empty trusted users field.
    $provider = $this->createProvider($data);
    $this->assertNull($provider->findTrustedUserIdFromApiKey('test-trusted-user-api-key'));

    // Add a trusted user without an api key.
    $user_without_api_key = $this->createUser();
    $provider->field_trusted_users->setValue([$user_without_api_key->id()]);

    // Test trusted user without API key.
    $this->assertNull($provider->findTrustedUserIdFromApiKey('test-trusted-user-api-key'));

    // Add a trusted user with an API key.
    $user_with_api_key = $this->createUser(values: ['field_api_key' => 'test-trusted-user-api-key']);
    $provider->field_trusted_users->setValue([$user_with_api_key->id()]);

    // Test no user found for the key.
    $this->assertNull($provider->findTrustedUserIdFromApiKey('test-trusted-user-api-key-other'));

    // Test user found for the key.
    $this->assertEquals($provider->findTrustedUserIdFromApiKey('test-trusted-user-api-key'), $user_with_api_key->id());
  }

  /**
   * @covers ::notifyProvider
   */
  public function testNotifyProvider(): void {
    $data = $this->data;

    $provider = $this->createProvider($data);

    $entity = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'report',
      'uuid' => '5fa67cc5-8c2a-4c05-aab2-bb61368ec3fb',
      'field_post_api_provider' => $provider,
    ]);

    // Mock services.
    $logger = new BufferingLogger();
    $logger_factory = $this->createConfiguredMock(LoggerChannelFactory::class, [
      'get' => $logger,
    ]);
    $client = $this->createMock(Client::class);

    $container = \Drupal::getContainer();
    $container->set('logger.factory', $logger_factory);
    $container->set('http_client', $client);

    Provider::notifyProvider($entity);
    $message = strtr('Request sent to @url for provider @provider.', [
      '@url' => $provider->field_webhook_url->uri . '/' . $entity->uuid(),
      '@provider' => $provider->uuid(),
    ]);
    $this->assertSame([
      ['info', $message, []],
    ], $logger->cleanLogs());

    $client->expects($this->any())
      ->method('get')
      ->willThrowException(new \Exception('error'));
    Provider::notifyProvider($entity);
    $message = strtr('Request to @url for provider @provider failed: @error', [
      '@url' => $provider->field_webhook_url->uri . '/' . $entity->uuid(),
      '@provider' => $provider->uuid(),
      '@error' => 'error',
    ]);
    $this->assertSame([
      ['notice', $message, []],
    ], $logger->cleanLogs());
  }

  /**
   * Create a provider.
   *
   * @param array $data
   *   Provider data.
   *
   * @return \Drupal\reliefweb_post_api\Entity\ProviderInterface
   *   Provider.
   */
  protected function createProvider(array $data): ?ProviderInterface {
    return \Drupal::entityTypeManager()
      ->getStorage('reliefweb_post_api_provider')
      ->create($data);
  }

  /**
   * Create a wrapped provider to test missing fields.
   *
   * @return \Drupal\reliefweb_post_api\Entity\ProviderInterface
   *   Provider.
   */
  protected function createNoFieldProvider(): ?ProviderInterface {
    $entity_type = $bundle = 'reliefweb_post_api_provider';

    return new class($this->data, $entity_type, $bundle) extends Provider {

      /**
       * {@inheritdoc}
       */
      public function hasField($field_name) {
        return FALSE;
      }

    };
  }

}
