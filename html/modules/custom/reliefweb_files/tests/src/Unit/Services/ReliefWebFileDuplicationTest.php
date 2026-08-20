<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_files\Unit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient;
use Drupal\reliefweb_files\Services\ReliefWebFileDuplication;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests file duplication Elasticsearch requests.
 */
#[CoversClass(ReliefWebFileDuplication::class)]
#[Group('reliefweb_files')]
class ReliefWebFileDuplicationTest extends UnitTestCase {

  /**
   * Creating the fingerprints index calls the Elasticsearch client.
   */
  public function testCreateFileFingerprintsIndexUsesElasticsearchClient(): void {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);

    $elasticsearch = $this->createMock(ReliefWebElasticsearchClient::class);
    $elasticsearch->method('getBaseIndexName')->willReturn('reliefweb');
    $elasticsearch->expects($this->once())
      ->method('request')
      ->with(
        'PUT',
        'reliefweb_file_fingerprints',
        $this->callback(function (array $options): bool {
          $this->assertArrayHasKey('json', $options);
          $this->assertArrayHasKey('settings', $options['json']);
          return TRUE;
        }),
      )
      ->willReturn($response);

    $duplication = $this->createDuplicationService($elasticsearch);
    $this->assertTrue($duplication->createFileFingerprintsIndex(1, 0));
  }

  /**
   * Deleting the fingerprints index calls the Elasticsearch client.
   */
  public function testDeleteFileFingerprintsIndexUsesElasticsearchClient(): void {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);

    $elasticsearch = $this->createMock(ReliefWebElasticsearchClient::class);
    $elasticsearch->method('getBaseIndexName')->willReturn('reliefweb');
    $elasticsearch->expects($this->once())
      ->method('request')
      ->with('DELETE', 'reliefweb_file_fingerprints', [])
      ->willReturn($response);

    $duplication = $this->createDuplicationService($elasticsearch);
    $this->assertTrue($duplication->deleteFileFingerprintsIndex());
  }

  /**
   * Search requests are sent to the fingerprints index path.
   */
  public function testExecuteFileFingerprintsRequestUsesIndexPath(): void {
    $response = $this->createMock(ResponseInterface::class);
    $elasticsearch = $this->createMock(ReliefWebElasticsearchClient::class);
    $elasticsearch->method('getBaseIndexName')->willReturn('reliefweb');
    $elasticsearch->expects($this->once())
      ->method('request')
      ->with('POST', 'reliefweb_file_fingerprints/_search', ['json' => ['query' => []]])
      ->willReturn($response);

    $duplication = $this->createDuplicationService($elasticsearch);
    $this->assertSame($response, $duplication->executeRequest('POST', '_search', [
      'json' => ['query' => []],
    ]));
  }

  /**
   * Create the duplication service with mocked dependencies.
   *
   * @param \Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient $elasticsearch
   *   Elasticsearch client mock.
   *
   * @return \Drupal\Tests\reliefweb_files\Unit\Services\TestableReliefWebFileDuplication
   *   Service under test.
   */
  protected function createDuplicationService(
    ReliefWebElasticsearchClient $elasticsearch,
  ): TestableReliefWebFileDuplication {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['shards', 1],
      ['replicas', 0],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('reliefweb_api.settings')->willReturn($config);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    return new TestableReliefWebFileDuplication(
      $this->createMock(StateInterface::class),
      $config_factory,
      $elasticsearch,
      $logger_factory,
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(AccountInterface::class),
    );
  }

}

/**
 * Testable file duplication service exposing the request helper.
 */
class TestableReliefWebFileDuplication extends ReliefWebFileDuplication {

  /**
   * Expose executeFileFingerprintsRequest() for tests.
   *
   * @param string $method
   *   HTTP method.
   * @param string $endpoint
   *   Index endpoint.
   * @param array $options
   *   Guzzle options.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   Response.
   */
  public function executeRequest(
    string $method,
    string $endpoint,
    array $options = [],
  ): ?ResponseInterface {
    return $this->executeFileFingerprintsRequest($method, $endpoint, $options);
  }

}
