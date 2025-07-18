<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Upsert;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\reliefweb_post_api\Controller\ReliefWebPostApi;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_post_api\Queue\ReliefWebPostApiDatabaseQueue;
use Drupal\reliefweb_post_api\Queue\ReliefWebPostApiDatabaseQueueFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb Post API controller.
 */
#[CoversClass(ReliefWebPostApi::class)]
#[Group('reliefweb_post_api')]
class ReliefWebPostApiTest extends ExistingSiteBase {

  /**
   * Test providers.
   *
   * @var array
   */
  protected array $providers;

  /**
   * Loaded Post API data.
   *
   * @var array
   */
  protected array $postApiData = [];

  /**
   * Test constructor.
   */
  public function testContructor(): void {
    $this->assertInstanceOf(ReliefWebPostApi::class, $this->createTestController());
  }

  /**
   * Test create.
   */
  public function testCreate(): void {
    $controller = ReliefWebPostApi::create(\Drupal::getContainer());
    $this->assertInstanceOf(ReliefWebPostApi::class, $controller);
  }

  /**
   * Test post content with unknown exception.
   */
  public function testPostContentUnknownException(): void {
    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->expects($this->any())
      ->method('getCurrentRequest')
      ->willThrowException(new \Exception('test exception'));

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringContainsString('test exception', $response->getContent());
  }

  /**
   * Test post content with missing appname.
   */
  public function testPostContentMissingAppname(): void {
    $request = $this->createMockRequest(methods: [
      'getMethod' => 'GET',
    ]);
    $request->query->remove('appname');

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Missing or invalid appname parameter.', $response->getContent());
  }

  /**
   * Test post content with method not allowed.
   */
  public function testPostContentMethodNotAllowed(): void {
    $request = $this->createMockRequest(methods: [
      'getMethod' => 'GET',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(405, $response->getStatusCode());
    $this->assertStringContainsString('Unsupported method.', $response->getContent());
  }

  /**
   * Test post content with invalid provider.
   */
  public function testPostContentInvalidProvider(): void {
    $request = $this->createMockRequest([
      'X-RW-POST-API-PROVIDER' => 'invalid',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString('Invalid provider.', $response->getContent());
  }

  /**
   * Test post content with invalid api key.
   */
  public function testPostContentInvalidApiKey(): void {
    $request = $this->createMockRequest([
      'X-RW-POST-API-KEY' => 'invalid',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString('Invalid API key.', $response->getContent());
  }

  /**
   * Test post content with invalid endpoint resource.
   */
  public function testPostContentInvalidEndpointResource(): void {
    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('test*test', $this->getTestUuid());
    $this->assertSame(404, $response->getStatusCode());
    $this->assertStringContainsString('Invalid endpoint resource.', $response->getContent());
  }

  /**
   * Test post content with invalid endpoint UUID.
   */
  public function testPostContentInvalidEndpointUuid(): void {
    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', 'test');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertStringContainsString('Invalid endpoint UUID.', $response->getContent());
  }

  /**
   * Test post content with unknown endpoint.
   */
  public function testPostContentUnknownEndpoint(): void {
    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('test', $this->getTestUuid());
    $this->assertSame(404, $response->getStatusCode());
    $this->assertStringContainsString('Unknown endpoint.', $response->getContent());
  }

  /**
   * Test check rate limits with no quota.
   */
  public function testCheckRateLimitsNoQuota(): void {
    $provider = $this->createConfiguredMock(ProviderInterface::class, [
      'id' => 123,
      'getQuota' => 0,
      'getRateLimit' => 0,
    ]);

    $controller = $this->createTestController();

    $this->expectException(AccessDeniedHttpException::class);
    $controller->checkRateLimits($provider);
  }

  /**
   * Test check rate limits with invalid timestamp.
   */
  public function testCheckRateLimitsInvalidTimestamp(): void {
    $provider = $this->createConfiguredMock(ProviderInterface::class, [
      'id' => 123,
      'getQuota' => 1,
      'getRateLimit' => 1,
    ]);

    $time = $this->createConfiguredMock(TimeInterface::class, [
      'getRequestTime' => 'wrong_timestamp',
    ]);

    $controller = $this->createTestController(services: [
      'datetime.time' => $time,
    ]);

    $this->expectException(HttpException::class);
    $this->expectExceptionMessage('Internal server error.');
    $controller->checkRateLimits($provider);
  }

  /**
   * Test check rate limits with limit exceeded.
   */
  public function testCheckRateLimitsRateLimitExceeded(): void {
    $provider = $this->createConfiguredMock(ProviderInterface::class, [
      'id' => 123,
      'getQuota' => 1,
      'getRateLimit' => 60,
    ]);

    $now = strtotime('2024-02-02T02:02:02+00:00');

    $rate_limit_info = [
      'provider_id' => 123,
      'request_count' => 0,
      'last_request_time' => $now - 1,
    ];

    $controller = $this->createTestController(rate_limit_info: $rate_limit_info, now: $now);

    $this->expectException(TooManyRequestsHttpException::class);
    $this->expectExceptionMessage('Not enough time ellapsed since last request.');
    $controller->checkRateLimits($provider);
  }

  /**
   * Test check rate limits with daily quota exceeded.
   */
  public function testCheckRateLimitsDailyQuotaExceeded(): void {
    $provider = $this->createConfiguredMock(ProviderInterface::class, [
      'id' => 123,
      'getQuota' => 1,
      'getRateLimit' => 60,
    ]);

    $now = strtotime('2024-02-02T02:02:02+00:00');

    $rate_limit_info = [
      'provider_id' => 123,
      'request_count' => 2,
      'last_request_time' => $now - 120,
    ];

    $controller = $this->createTestController(rate_limit_info: $rate_limit_info, now: $now);

    $this->expectException(TooManyRequestsHttpException::class);
    $this->expectExceptionMessage('Daily quota exceeded.');
    $controller->checkRateLimits($provider);
  }

  /**
   * Test check rate limits.
   */
  public function testCheckRateLimits(): void {
    $provider = $this->createConfiguredMock(ProviderInterface::class, [
      'id' => 123,
      'getQuota' => 1,
      'getRateLimit' => 60,
    ]);

    $now = strtotime('2024-02-02T02:02:02+00:00');

    $rate_limit_info = [
      'provider_id' => 123,
      'request_count' => 0,
      'last_request_time' => $now - 120,
    ];

    $controller = $this->createTestController(rate_limit_info: $rate_limit_info, now: $now);

    $controller->checkRateLimits($provider);
    $this->assertTrue(TRUE);
  }

  /**
   * Test post content with unprocessable data.
   */
  public function testPostContentUnprocessable(): void {
    $plugin = $this->createConfiguredMock(ContentProcessorPluginInterface::class, [
      'getProvider' => $this->getTestProvider(),
      'isProcessable' => FALSE,
    ]);

    $plugin_manager = $this->createConfiguredMock(ContentProcessorPluginManagerInterface::class, [
      'getPluginByResource' => $plugin,
    ]);

    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController(services: [
      'request_stack' => $request_stack,
      'plugin.manager.reliefweb_post_api.content_processor' => $plugin_manager,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(422, $response->getStatusCode());
    $this->assertStringContainsString('Unprocessable submission.', $response->getContent());
  }

  /**
   * Test post content with invalid content format.
   */
  public function testPostContentInvalidContentFormat(): void {
    $request = $this->createMockRequest(methods: [
      'getContentTypeFormat' => 'invalid',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid content format.', $response->getContent());
  }

  /**
   * Test post content with missing request body.
   */
  public function testPostContentMissingRequestBody(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => NULL,
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Missing request body.', $response->getContent());
  }

  /**
   * Test post content with invalid request body.
   */
  public function testPostContentInvalidRequestBody(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => ['test'],
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid request body.', $response->getContent());
  }

  /**
   * Test post content with invalid json body.
   */
  public function testPostContentInvalidJsonBody(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => '{json: invalid}',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid JSON body.', $response->getContent());
  }

  /**
   * Test post content with invalid data.
   */
  public function testPostContentInvalidData(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => '[]',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid data', $response->getContent());
  }

  /**
   * Test post content with document uuid mismatch.
   */
  public function testPostContentDocumentUuidMismatch(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => '{"uuid": "78c21042-4fcd-11ef-8393-325096b39f47"}',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Document UUID mistmatch.', $response->getContent());
  }

  /**
   * Test post content with queue exception.
   */
  public function testPostContentQueueException(): void {
    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $queue_factory = $this->createMock(ReliefWebPostApiDatabaseQueueFactory::class);
    $queue_factory->expects($this->any())
      ->method('get')
      ->willThrowException(new \Exception());

    $controller = $this->createTestController([
      'reliefweb_post_api.queue.database' => $queue_factory,
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringContainsString('Internal server error.', $response->getContent());
  }

  /**
   * Test post content.
   */
  public function testPostContent(): void {
    $request = $this->createMockRequest(api_key: 'test-provider-key');

    $request_stack = $this->createMockRequestStack($request);

    $queue = $this->createConfiguredMock(ReliefWebPostApiDatabaseQueue::class, [
      'createItem' => TRUE,
    ]);

    $queue_factory = $this->createConfiguredMock(ReliefWebPostApiDatabaseQueueFactory::class, [
      'get' => $queue,
    ]);

    $controller = $this->createTestController([
      'reliefweb_post_api.queue.database' => $queue_factory,
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(202, $response->getStatusCode());
    $this->assertStringContainsString('Document queued for processing.', $response->getContent());
  }

  /**
   * Test post content with trusted user.
   */
  public function testPostContentTrustedUser(): void {
    $request = $this->createMockRequest(api_key: 'test-trusted-user-api-key');

    $request_stack = $this->createMockRequestStack($request);

    $queue = $this->createConfiguredMock(ReliefWebPostApiDatabaseQueue::class, [
      'createItem' => TRUE,
    ]);

    $queue_factory = $this->createConfiguredMock(ReliefWebPostApiDatabaseQueueFactory::class, [
      'get' => $queue,
    ]);

    $controller = $this->createTestController([
      'reliefweb_post_api.queue.database' => $queue_factory,
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('reports', $this->getTestUuid());
    $this->assertSame(202, $response->getStatusCode());
    $this->assertStringContainsString('Document queued for processing.', $response->getContent());
  }

  /**
   * Test get json schema.
   */
  public function testGetJsonSchema(): void {
    $controller = $this->createTestController();

    $response = $controller->getJsonSchema('@fgh%');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid schema file name.', $response->getContent());

    $response = $controller->getJsonSchema('test.json');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertStringContainsString('Unknown schema file.', $response->getContent());

    $response = $controller->getJsonSchema('report.json');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('uuid', $response->getContent());
    $this->assertTrue(json_validate($response->getContent()));
  }

  /**
   * Test get json schema with empty schema.
   */
  public function testGetJsonSchemaEmpty(): void {
    $path_resolver = $this->createConfiguredMock(ExtensionPathResolver::class, [
      'getPath' => __DIR__ . '/../../../data/',
    ]);

    $controller = $this->createTestController([
      'extension.path.resolver' => $path_resolver,
    ]);

    $response = $controller->getJsonSchema('empty.json');
    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringContainsString('Internal server error.', $response->getContent());
  }

  /**
   * Create a mock request.
   *
   * @param array $headers
   *   Headers.
   * @param array $methods
   *   Methods for the mock request.
   * @param string $api_key
   *   API key.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The mock request.
   */
  protected function createMockRequest(array $headers = [], array $methods = [], string $api_key = 'test-provider-key'): Request {
    $headers += [
      'X-RW-POST-API-PROVIDER' => $this->getTestProvider('test-provider')->uuid(),
      'X-RW-POST-API-KEY' => $api_key,
    ];

    $methods += [
      'getMethod' => 'PUT',
      'getContentTypeFormat' => 'json',
      'getContent' => $this->getPostApiData(),
    ];

    $request = $this->createConfiguredMock(Request::class, $methods);
    $request->headers = new HeaderBag($headers);
    $request->query = new InputBag(['appname' => 'test']);

    return $request;
  }

  /**
   * Create a mock request stack.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RequestStack
   *   The mock request stack.
   */
  protected function createMockRequestStack(Request $request): RequestStack {
    return $this->createConfiguredMock(RequestStack::class, [
      'getCurrentRequest' => $request,
    ]);
  }

  /**
   * Create a test controller.
   *
   * @param array $services
   *   List of services.
   * @param array $rate_limit_info
   *   The rate limit info as returned from the database.
   * @param int|null $now
   *   Current unix time.
   *
   * @return \Drupal\reliefweb_post_api\Controller\ReliefWebPostApi
   *   The test controller.
   */
  protected function createTestController(array $services = [], array $rate_limit_info = [], ?int $now = NULL): ReliefWebPostApi {
    $container = \drupal::getContainer();

    $now = $now ?? time();

    if (!isset($services['database'])) {
      $rate_limit_info += [
        'provider_id' => 123,
        'request_count' => 0,
        'last_request_time' => $now - 120,
      ];

      $statement = $this->createConfiguredMock(StatementInterface::class, [
        'fetchAssoc' => $rate_limit_info,
      ]);

      $select = $this->createMock(SelectInterface::class);
      $select->method('fields')->willReturnSelf();
      $select->method('condition')->willReturnSelf();
      $select->method('execute')->willReturn($statement);

      $upsert = $this->createMock(Upsert::class);
      $upsert->method('fields')->willReturnSelf();
      $upsert->method('key')->willReturnSelf();
      $upsert->method('values')->willReturnSelf();
      $upsert->method('execute')->willReturn(1);

      $services['database'] = $this->createConfiguredMock(Connection::class, [
        'select' => $select,
        'upsert' => $upsert,
      ]);
    }

    if (!isset($services['datetime.time'])) {
      $services['datetime.time'] = $this->createConfiguredMock(TimeInterface::class, [
        'getRequestTime' => $now,
      ]);
    }

    $services = [
      $services['request_stack'] ?? $container->get('request_stack'),
      $services['reliefweb_post_api.queue.database'] ?? $container->get('reliefweb_post_api.queue.database'),
      $services['extension.path.resolver'] ?? $container->get('extension.path.resolver'),
      $services['database'] ?? $container->get('database'),
      $services['datetime.time'] ?? $container->get('datetime.time'),
      $services['plugin.manager.reliefweb_post_api.content_processor'] ?? $container->get('plugin.manager.reliefweb_post_api.content_processor'),
    ];

    return new ReliefWebPostApi(...$services);
  }

  /**
   * Get some Post API test data.
   *
   * @param string $bundle
   *   The bundle of the data.
   * @param string $type
   *   The type of data: raw (string) or decoded (array).
   *
   * @return string|array
   *   The data as a JSON string.
   */
  protected function getPostApiData(string $bundle = 'report', string $type = 'raw'): string|array {
    if (!isset($this->postApiData[$bundle])) {
      $file = __DIR__ . '/../../../data/data-' . $bundle . '.json';
      $data = file_get_contents($file);
      $this->postApiData[$bundle] = [
        'raw' => $data,
        'decoded' => json_decode($data, TRUE),
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
   * Get a test provider.
   *
   * @param string $name
   *   Provider name.
   *
   * @return Drupal\reliefweb_post_api\Entity\ProviderInterface|null
   *   Provider entity.
   */
  protected function getTestProvider(string $name = 'test-provider'): ?ProviderInterface {
    if (!isset($this->providers)) {
      // Trusted user.
      $user = $this->createUser(values: [
        'field_api_key' => 'test-trusted-user-api-key',
      ]);

      /** @var \Drupal\reliefweb_post_api\EntityProviderInterface $provider */
      $provider = \Drupal::entityTypeManager()
        ->getStorage('reliefweb_post_api_provider')
        ->create([
          'id' => 666,
          'name' => 'test-provider',
          'uuid' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'test-provider')->toRfc4122(),
          'key' => 'test-provider-key',
          'status' => 1,
          'field_source' => [1503],
          'field_user' => 2,
          'field_document_url' => ['https://test.test/'],
          'field_file_url' => ['https://test.test/'],
          'field_image_url' => ['https://test.test/'],
          'field_quota' => 2,
          'field_rate_limit' => 2,
          'field_trusted_users' => [$user->id()],
        ]);
      $provider->save();
      $this->markEntityForCleanup($provider);
      $this->providers[$name] = $provider;
    }
    return $this->providers[$name] ?? NULL;
  }

}
