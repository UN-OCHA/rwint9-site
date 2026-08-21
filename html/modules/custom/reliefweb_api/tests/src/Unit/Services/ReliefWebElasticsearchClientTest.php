<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_api\Unit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the ReliefWeb Elasticsearch client.
 */
#[CoversClass(ReliefWebElasticsearchClient::class)]
#[Group('reliefweb_api')]
class ReliefWebElasticsearchClientTest extends UnitTestCase {

  /**
   * Default indexer options use none auth and TLS verification.
   */
  public function testGetIndexerOptionsDefaults(): void {
    $client = $this->createElasticsearchClient([]);
    $this->assertSame([
      'elasticsearch' => 'http://elasticsearch:9200',
      'elasticsearch-auth-type' => 'none',
      'elasticsearch-username' => '',
      'elasticsearch-password' => '',
      'elasticsearch-api-key' => '',
      'elasticsearch-verify-tls' => TRUE,
      'elasticsearch-ca-file' => '',
      'elasticsearch-retry' => 2,
      'base-index-name' => 'rwint',
    ], $client->getIndexerOptions());
  }

  /**
   * Base index name falls back to the database name when unset in config.
   */
  public function testGetBaseIndexNameFallsBackToDatabaseName(): void {
    $client = $this->createElasticsearchClient([], NULL, 'my_database');
    $this->assertSame('my_database', $client->getBaseIndexName());
  }

  /**
   * From config maps authentication, TLS and index settings.
   */
  public function testGetIndexerOptionsMapsAuthAndTls(): void {
    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443/',
      'elasticsearch_auth_type' => 'ApiKey',
      'elasticsearch_api_key' => 'token-value',
      'elasticsearch_verify_tls' => FALSE,
      'elasticsearch_ca_file' => '/path/to/ca.pem',
      'elasticsearch_retry' => 4,
      'base_index_name' => 'rwint',
    ]);
    $this->assertSame('https://search.example.com:443', $client->getBaseUrl());
    $this->assertSame('rwint', $client->getBaseIndexName());
    $options = $client->getIndexerOptions();
    $this->assertSame('apikey', $options['elasticsearch-auth-type']);
    $this->assertSame('token-value', $options['elasticsearch-api-key']);
    $this->assertFalse($options['elasticsearch-verify-tls']);
    $this->assertSame('/path/to/ca.pem', $options['elasticsearch-ca-file']);
    $this->assertSame(4, $options['elasticsearch-retry']);
  }

  /**
   * Boolean false for elasticsearch_verify_tls is not flipped to TRUE.
   */
  public function testVerifyTlsFalseIsNotFlipped(): void {
    $client = $this->createElasticsearchClient([
      'elasticsearch_verify_tls' => FALSE,
    ]);
    $this->assertFalse($client->getIndexerOptions()['elasticsearch-verify-tls']);
  }

  /**
   * Zero elasticsearch_retry is preserved (no retries after first request).
   */
  public function testGetIndexerOptionsPreservesZeroRetry(): void {
    $client = $this->createElasticsearchClient([
      'elasticsearch_retry' => 0,
    ]);
    $this->assertSame(0, $client->getIndexerOptions()['elasticsearch-retry']);
  }

  /**
   * Request paths are prefixed with the cluster base URL.
   */
  public function testRequestPrefixesPathWithBaseUrl(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://search.example.com:443/reliefweb_file_fingerprints/_search',
        $this->callback(function (array $options): bool {
          $this->assertTrue($options['verify']);
          $this->assertSame(['query' => 'test'], $options['json']);
          $this->assertArrayNotHasKey('auth', $options);
          return TRUE;
        }),
      )
      ->willReturn($this->createMock(ResponseInterface::class));

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
    ], $http_client);
    $client->request('POST', 'reliefweb_file_fingerprints/_search', [
      'json' => ['query' => 'test'],
    ]);
  }

  /**
   * Basic authentication is passed as Guzzle auth.
   */
  public function testRequestAppliesBasicAuth(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://search.example.com:443/index',
        $this->callback(function (array $options): bool {
          $this->assertSame(['alice', 'secret'], $options['auth']);
          $this->assertTrue($options['verify']);
          return TRUE;
        }),
      )
      ->willReturn($this->createMock(ResponseInterface::class));

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
      'elasticsearch_auth_type' => 'basic',
      'elasticsearch_username' => 'alice',
      'elasticsearch_password' => 'secret',
    ], $http_client);
    $client->request('GET', 'index');
  }

  /**
   * ApiKey authentication is passed as an Authorization header.
   */
  public function testRequestAppliesApiKeyAuth(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://search.example.com:443/index',
        $this->callback(function (array $options): bool {
          $this->assertSame('ApiKey test-token', $options['headers']['Authorization']);
          return TRUE;
        }),
      )
      ->willReturn($this->createMock(ResponseInterface::class));

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
      'elasticsearch_auth_type' => 'apikey',
      'elasticsearch_api_key' => 'test-token',
    ], $http_client);
    $client->request('GET', 'index');
  }

  /**
   * TLS verification can be disabled.
   */
  public function testRequestDisablesTlsVerification(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://search.example.com:443/index',
        $this->callback(function (array $options): bool {
          $this->assertFalse($options['verify']);
          return TRUE;
        }),
      )
      ->willReturn($this->createMock(ResponseInterface::class));

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
      'elasticsearch_verify_tls' => FALSE,
      'elasticsearch_ca_file' => '/path/to/ca.pem',
    ], $http_client);
    $client->request('GET', 'index');
  }

  /**
   * A custom CA file is used when verification is enabled.
   */
  public function testRequestUsesCustomCaFile(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://search.example.com:443/index',
        $this->callback(function (array $options): bool {
          $this->assertSame('/path/to/ca.pem', $options['verify']);
          return TRUE;
        }),
      )
      ->willReturn($this->createMock(ResponseInterface::class));

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
      'elasticsearch_ca_file' => '/path/to/ca.pem',
    ], $http_client);
    $client->request('GET', 'index');
  }

  /**
   * Basic auth requires credentials.
   */
  public function testRequestThrowsWhenBasicAuthCredentialsMissing(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Missing Elasticsearch basic authentication credentials.');
    $client = $this->createElasticsearchClient([
      'elasticsearch_auth_type' => 'basic',
      'elasticsearch_username' => 'alice',
    ]);
    $client->request('GET', 'index');
  }

  /**
   * ApiKey auth requires a key.
   */
  public function testRequestThrowsWhenApiKeyMissing(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Missing Elasticsearch api key authentication credentials.');
    $client = $this->createElasticsearchClient([
      'elasticsearch_auth_type' => 'apikey',
    ]);
    $client->request('GET', 'index');
  }

  /**
   * An unknown auth type is rejected.
   */
  public function testRequestThrowsWhenAuthTypeUnknown(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid Elasticsearch authentication type');
    $client = $this->createElasticsearchClient([
      'elasticsearch_auth_type' => 'digest',
    ]);
    $client->request('GET', 'index');
  }

  /**
   * HTTP 429 is retried with square backoff until success.
   */
  public function testRequestRetriesOn429ThenSucceeds(): void {
    $request = new Request('GET', 'https://search.example.com:443/index');
    $too_many = new Response(429);
    $ok = new Response(200);

    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->exactly(3))
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        $this->throwException(new ClientException('Too Many Requests', $request, $too_many)),
        $this->throwException(new ClientException('Too Many Requests', $request, $too_many)),
        $ok,
      );

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
      'elasticsearch_retry' => 2,
    ], $http_client, 'rwint', TRUE);

    $this->assertSame($ok, $client->request('GET', 'index'));
    $this->assertSame([0, 1, 4], $client->getSleepCalls());
  }

  /**
   * HTTP 429 is not retried when elasticsearch_retry is 0.
   */
  public function testRequestDoesNotRetryWhenRetryIsZero(): void {
    $request = new Request('GET', 'https://search.example.com:443/index');
    $too_many = new Response(429);
    $exception = new ClientException('Too Many Requests', $request, $too_many);

    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
      'elasticsearch_retry' => 0,
    ], $http_client, 'rwint', TRUE);

    $this->expectException(ClientException::class);
    try {
      $client->request('GET', 'index');
    }
    finally {
      $this->assertSame([0], $client->getSleepCalls());
    }
  }

  /**
   * Non-429 errors are not retried.
   */
  public function testRequestDoesNotRetryNon429Errors(): void {
    $request = new Request('GET', 'https://search.example.com:443/index');
    $exception = new ClientException('Not Found', $request, new Response(404));

    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $client = $this->createElasticsearchClient([
      'elasticsearch' => 'https://search.example.com:443',
      'elasticsearch_retry' => 2,
    ], $http_client, 'rwint', TRUE);

    $this->expectException(ClientException::class);
    try {
      $client->request('GET', 'index');
    }
    finally {
      $this->assertSame([0], $client->getSleepCalls());
    }
  }

  /**
   * Create a client with mocked config and HTTP client.
   *
   * @param array<string, mixed> $config
   *   Config values keyed by reliefweb_api.settings name.
   * @param \GuzzleHttp\ClientInterface|null $http_client
   *   Optional HTTP client mock.
   * @param string $database_name
   *   Default database name used when base_index_name is unset.
   * @param bool $track_sleep
   *   TRUE to use a client that records sleep() calls without waiting.
   *
   * @return \Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient|\Drupal\Tests\reliefweb_api\Unit\Services\TestableReliefWebElasticsearchClient
   *   Client under test.
   */
  protected function createElasticsearchClient(
    array $config,
    ?ClientInterface $http_client = NULL,
    string $database_name = 'rwint',
    bool $track_sleep = FALSE,
  ): ReliefWebElasticsearchClient {
    $immutable_config = $this->createMock(ImmutableConfig::class);
    $immutable_config->method('get')->willReturnCallback(function (string $key) use ($config) {
      return $config[$key] ?? NULL;
    });
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('reliefweb_api.settings')->willReturn($immutable_config);
    $database = $this->createMock(Connection::class);
    $database->method('getConnectionOptions')->willReturn(['database' => $database_name]);
    $class = $track_sleep ? TestableReliefWebElasticsearchClient::class : ReliefWebElasticsearchClient::class;
    return new $class(
      $http_client ?? $this->createMock(ClientInterface::class),
      $config_factory,
      $database,
    );
  }

}
