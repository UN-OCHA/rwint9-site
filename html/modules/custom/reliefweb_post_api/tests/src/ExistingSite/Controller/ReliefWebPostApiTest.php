<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Controller;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Site\Settings;
use Drupal\reliefweb_post_api\Controller\ReliefWebPostApi;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb POST API controller.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Controller\ReliefWebPostApi
 *
 * @group reliefweb_post_api
 */
class ReliefWebPostApiTest extends ExistingSiteBase {

  /**
   * Loaded POST API data.
   *
   * @var array
   */
  protected array $postApiData = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Add a test providers.
    $settings = Settings::getAll();
    $settings['reliefweb_post_api.providers']['test-provider'] = [
      'id' => 'test-provider',
      'key' => 'test-provider-key',
      'sources' => [1503],
      'url_pattern' => '#^https://test.test/#',
    ];
    new Settings($settings);
  }

  /**
   * @covers ::__construct
   */
  public function testContructor(): void {
    $this->assertInstanceOf(ReliefWebPostApi::class, $this->createTestController());
  }

  /**
   * @covers ::create
   */
  public function testCreate(): void {
    $controller = ReliefWebPostApi::create(\Drupal::getContainer());
    $this->assertInstanceOf(ReliefWebPostApi::class, $controller);
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentUnknownException(): void {
    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->expects($this->any())
      ->method('getCurrentRequest')
      ->willThrowException(new \Exception('test exception'));

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringContainsString('test exception', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentMethodNotAllowed(): void {
    $request = $this->createMockRequest(methods: [
      'getMethod' => 'GET',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(405, $response->getStatusCode());
    $this->assertStringContainsString('Unsupported method.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentInvalidProvider(): void {
    $request = $this->createMockRequest([
      'X-RW-POST-API-PROVIDER' => 'invalid',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString('Invalid provider.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentInvalidApiKey(): void {
    $request = $this->createMockRequest([
      'X-RW-POST-API-KEY' => 'invalid',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString('Invalid API key.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentInvalidEndpoint(): void {
    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('test');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertStringContainsString('Invalid endpoint.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentInvalidContentFormat(): void {
    $request = $this->createMockRequest(methods: [
      'getContentTypeFormat' => 'invalid',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid content format.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentMissingRequestBody(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => NULL,
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Missing request body.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentInvalidRequestBody(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => ['test'],
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid request body.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentInvalidJsonBody(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => '{json: invalid}',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid JSON body.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentInvalidData(): void {
    $request = $this->createMockRequest(methods: [
      'getContent' => '[]',
    ]);

    $request_stack = $this->createMockRequestStack($request);

    $controller = $this->createTestController([
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('Invalid data', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContentQueueException(): void {
    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->expects($this->any())
      ->method('get')
      ->willThrowException(new \Exception());

    $controller = $this->createTestController([
      'queue' => $queue_factory,
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringContainsString('Internal server error.', $response->getContent());
  }

  /**
   * @covers ::postContent
   */
  public function testPostContent(): void {
    $request = $this->createMockRequest();

    $request_stack = $this->createMockRequestStack($request);

    $queue = $this->createConfiguredMock(QueueInterface::class, [
      'createItem' => TRUE,
    ]);

    $queue_factory = $this->createConfiguredMock(QueueFactory::class, [
      'get' => $queue,
    ]);

    $controller = $this->createTestController([
      'queue' => $queue_factory,
      'request_stack' => $request_stack,
    ]);

    $response = $controller->postContent('report');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('Document queued for processing.', $response->getContent());
  }

  /**
   * Create a mock request.
   *
   * @param array $headers
   *   Headers.
   * @param array $methods
   *   Methods for the mock request.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The mock request.
   */
  protected function createMockRequest(array $headers = [], array $methods = []): Request {
    $headers += [
      'X-RW-POST-API-PROVIDER' => 'test-provider',
      'X-RW-POST-API-KEY' => 'test-provider-key',
    ];

    $header_map = [];
    foreach ($headers as $key => $value) {
      $header_map[] = [$key, '', $value];
    }

    $header_bag = $this->createMock(HeaderBag::class);
    $header_bag->expects($this->any())
      ->method('get')
      ->willReturnMap($header_map);

    $methods += [
      'getMethod' => 'POST',
      'getContentTypeFormat' => 'json',
      'getContent' => $this->getPostApiData(),
    ];

    $request = $this->createConfiguredMock(Request::class, $methods);
    $request->headers = $header_bag;

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
   *
   * @return \Drupal\reliefweb_post_api\Controller\ReliefWebPostApi
   *   The test controller.
   */
  protected function createTestController(array $services = []): ReliefWebPostApi {
    $container = \drupal::getContainer();

    $services = [
      $services['request_stack'] ?? $container->get('request_stack'),
      $services['queue'] ?? $container->get('queue'),
      $services['plugin.manager.reliefweb_post_api.content_processor'] ?? $container->get('plugin.manager.reliefweb_post_api.content_processor'),
      $services['reliefweb_post_api.provider.manager'] ?? $container->get('reliefweb_post_api.provider.manager'),
    ];

    return new ReliefWebPostApi(...$services);
  }

  /**
   * Get some POST API test data.
   *
   * @param string $bundle
   *   The bundle of the data.
   *
   * @return string
   *   The data as a JSON string.
   */
  protected function getPostApiData(string $bundle = 'report'): string {
    if (!isset($this->postApiData[$bundle])) {
      $file = __DIR__ . '/../../../data/data-' . $bundle . '.json';
      $this->postApiData[$bundle] = file_get_contents($file);
    }
    return $this->postApiData[$bundle];
  }

}
