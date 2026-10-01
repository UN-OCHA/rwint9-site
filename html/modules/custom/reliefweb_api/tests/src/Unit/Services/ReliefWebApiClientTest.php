<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_api\Unit\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the ReliefWeb API client caching behavior.
 */
#[CoversClass(ReliefWebApiClient::class)]
#[Group('reliefweb_api')]
class ReliefWebApiClientTest extends UnitTestCase {

  /**
   * Cache backend mock.
   */
  protected CacheBackendInterface&MockObject $cacheBackend;

  /**
   * HTTP client mock.
   */
  protected ClientInterface&MockObject $httpClient;

  /**
   * API client under test.
   */
  protected ReliefWebApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->cacheBackend = $this->createMock(CacheBackendInterface::class);
    $this->httpClient = $this->createMock(ClientInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) {
      return match ($key) {
        'api_url' => 'https://api.example.com/v1',
        'api_url_external' => 'https://api.example.com/v1',
        'appname' => 'test-app',
        'request_id_prefix' => 'rw',
        'verify_ssl' => TRUE,
        'cache_enabled' => TRUE,
        'cache_lifetime' => 60,
        'cache_namespace' => 'reliefweb:api',
        'timeout' => 5,
        default => NULL,
      };
    });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('reliefweb_api.settings')->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $request_stack = new RequestStack();
    $request_stack->push(Request::create('https://example.com/'));

    $this->apiClient = new ReliefWebApiClient(
      $this->cacheBackend,
      $config_factory,
      $time,
      $this->httpClient,
      $logger_factory,
      $request_stack,
    );
  }

  /**
   * Successful API responses are stored in the API cache bin.
   */
  public function testSuccessfulResponseIsCached(): void {
    $body = '{"data":[{"id":1}],"totalCount":1}';

    $this->cacheBackend->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);

    $this->cacheBackend->expects($this->once())
      ->method('set')
      ->with(
        $this->stringContains('reliefweb:api:queries:reports:'),
        $body,
        1_700_000_060,
        $this->callback(static function (array $tags): bool {
          return in_array('node_list:report', $tags, TRUE)
            && in_array('reliefweb:api:reports', $tags, TRUE);
        }),
      );

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(200, [], $body)));

    $result = $this->apiClient->request('reports', ['limit' => 1]);
    $this->assertSame([
      'data' => [['id' => 1]],
      'totalCount' => 1,
    ], $result);
  }

  /**
   * Non-200 responses are not written to the API cache bin.
   */
  public function testNonSuccessResponseIsNotCached(): void {
    $this->cacheBackend->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);

    $this->cacheBackend->expects($this->never())
      ->method('set');

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(500, [], 'error')));

    $result = $this->apiClient->request('reports', ['limit' => 1]);
    $this->assertNull($result);
  }

  /**
   * Rejected promises (timeouts etc.) are not written to the API cache bin.
   */
  public function testRejectedRequestIsNotCached(): void {
    $this->cacheBackend->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);

    $this->cacheBackend->expects($this->never())
      ->method('set');

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->willReturn(Create::rejectionFor(new \RuntimeException('timeout', 0)));

    $result = $this->apiClient->request('reports', ['limit' => 1]);
    $this->assertNull($result);
  }

  /**
   * Cached successful bodies are returned without calling the HTTP client.
   */
  public function testCachedSuccessfulBodyIsReturned(): void {
    $body = '{"data":[{"id":2}],"totalCount":1}';
    $cache_item = (object) [
      'data' => $body,
    ];

    $this->cacheBackend->expects($this->once())
      ->method('get')
      ->willReturn($cache_item);

    $this->cacheBackend->expects($this->never())
      ->method('set');

    $this->httpClient->expects($this->never())
      ->method('requestAsync');

    $result = $this->apiClient->request('reports', ['limit' => 1]);
    $this->assertSame([
      'data' => [['id' => 2]],
      'totalCount' => 1,
    ], $result);
  }

  /**
   * Successful requests merge resource cache tags into the provided metadata.
   */
  public function testCacheabilityReceivesResourceTagsOnSuccess(): void {
    $body = '{"data":[],"totalCount":0}';
    $this->cacheBackend->method('get')->willReturn(FALSE);
    $this->httpClient->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(200, [], $body)));

    $cacheability = new CacheableMetadata();
    $this->apiClient->request('reports', ['limit' => 1], cacheability: $cacheability);

    $this->assertContains('node_list:report', $cacheability->getCacheTags());
    $this->assertContains('taxonomy_term_list', $cacheability->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
  }

  /**
   * Failed requests set max-age 0 on the provided cacheability metadata.
   */
  public function testCacheabilitySetsMaxAgeZeroOnFailure(): void {
    $this->cacheBackend->method('get')->willReturn(FALSE);
    $this->httpClient->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(500, [], 'error')));

    $cacheability = new CacheableMetadata();
    $this->apiClient->request('reports', ['limit' => 1], cacheability: $cacheability);

    $this->assertContains('node_list:report', $cacheability->getCacheTags());
    $this->assertSame(0, $cacheability->getCacheMaxAge());
  }

  /**
   * Taxonomy resources merge bundle-specific list tags.
   */
  public function testCacheabilityReceivesTaxonomyListTags(): void {
    $body = '{"data":[],"totalCount":0}';
    $this->cacheBackend->method('get')->willReturn(FALSE);
    $this->httpClient->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(200, [], $body)));

    $cacheability = new CacheableMetadata();
    $this->apiClient->request('disasters', ['limit' => 1], cacheability: $cacheability);

    $this->assertContains('taxonomy_term_list:disaster', $cacheability->getCacheTags());
    $this->assertContains('taxonomy_term_list', $cacheability->getCacheTags());
  }

  /**
   * Empty 200 bodies are not written to the API cache bin.
   */
  public function testEmptyBodyIsNotCached(): void {
    $this->cacheBackend->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);

    $this->cacheBackend->expects($this->never())
      ->method('set');

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(200, [], '')));

    $result = $this->apiClient->request('reports', ['limit' => 1]);
    $this->assertNull($result);
  }

  /**
   * Non-JSON 200 bodies are not written to the API cache bin.
   */
  public function testNonJsonBodyIsNotCached(): void {
    $this->cacheBackend->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);

    $this->cacheBackend->expects($this->never())
      ->method('set');

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(200, [], '<html>error</html>')));

    $result = $this->apiClient->request('reports', ['limit' => 1]);
    $this->assertNull($result);
  }

  /**
   * JSON array 200 bodies are not written to the API cache bin.
   */
  public function testJsonArrayBodyIsNotCached(): void {
    $this->cacheBackend->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);

    $this->cacheBackend->expects($this->never())
      ->method('set');

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->willReturn(Create::promiseFor(new Response(200, [], '[{"id":1}]')));

    $result = $this->apiClient->request('reports', ['limit' => 1]);
    $this->assertNull($result);
  }

  /**
   * Request ID is appended to the URL query with the configured prefix.
   */
  public function testRequestIdIsAddedToUrl(): void {
    $body = '{"data":[],"totalCount":0}';
    $this->cacheBackend->method('get')->willReturn(FALSE);

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->with(
        'POST',
        $this->callback(static function (string $url): bool {
          $query = parse_url($url, PHP_URL_QUERY);
          parse_str((string) $query, $parameters);
          return ($parameters['appname'] ?? NULL) === 'test-app'
            && ($parameters['request-id'] ?? NULL) === 'rw.country.maps-infographics';
        }),
        $this->anything(),
      )
      ->willReturn(Create::promiseFor(new Response(200, [], $body)));

    $this->apiClient->request(
      'reports',
      ['limit' => 1],
      request_id: 'country.maps-infographics',
    );
  }

  /**
   * Request ID is omitted from the URL when not provided.
   */
  public function testRequestIdIsOmittedWhenUnset(): void {
    $body = '{"data":[],"totalCount":0}';
    $this->cacheBackend->method('get')->willReturn(FALSE);

    $this->httpClient->expects($this->once())
      ->method('requestAsync')
      ->with(
        'POST',
        $this->callback(static function (string $url): bool {
          $query = parse_url($url, PHP_URL_QUERY);
          parse_str((string) $query, $parameters);
          return ($parameters['appname'] ?? NULL) === 'test-app'
            && !array_key_exists('request-id', $parameters);
        }),
        $this->anything(),
      )
      ->willReturn(Create::promiseFor(new Response(200, [], $body)));

    $this->apiClient->request('reports', ['limit' => 1]);
  }

}
