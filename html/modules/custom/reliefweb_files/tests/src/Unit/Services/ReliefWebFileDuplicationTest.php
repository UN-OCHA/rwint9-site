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
use Drupal\file\Entity\File;
use Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient;
use Drupal\reliefweb_files\Services\ReliefWebFileDuplication;
use Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

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
   * HasFileFingerprint() uses _mget against the fingerprints index.
   */
  public function testHasFileFingerprintUsesMget(): void {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')->willReturn(json_encode([
      'docs' => [
        ['_id' => '42', 'found' => TRUE],
      ],
    ]));
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn($stream);

    $elasticsearch = $this->createMock(ReliefWebElasticsearchClient::class);
    $elasticsearch->method('getBaseIndexName')->willReturn('reliefweb');
    $elasticsearch->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'reliefweb_file_fingerprints/_mget',
        $this->callback(function (array $options): bool {
          return ($options['json']['ids'] ?? NULL) === ['42'];
        }),
      )
      ->willReturn($response);

    $duplication = $this->createDuplicationService($elasticsearch);
    $this->assertTrue($duplication->hasFileFingerprint(42));
  }

  /**
   * MLT-by-id search uses like._id and the MinHash bucket count.
   */
  public function testFindSimilarFileFingerprintsByIdUsesIndexedDocument(): void {
    $search_stream = $this->createMock(StreamInterface::class);
    $search_stream->method('getContents')->willReturn(json_encode([
      'hits' => [
        'hits' => [
          [
            '_id' => '99',
            '_score' => 1.0,
            '_source' => ['id' => 99, 'uuid' => 'abc'],
          ],
        ],
      ],
    ]));
    $search_response = $this->createMock(ResponseInterface::class);
    $search_response->method('getBody')->willReturn($search_stream);

    $explain_stream = $this->createMock(StreamInterface::class);
    // 256 matching token weight lines -> 50% of 512 buckets.
    $explain_stream->method('getContents')->willReturn(
      str_repeat('weight(minhash:x) ', 256)
    );
    $explain_response = $this->createMock(ResponseInterface::class);
    $explain_response->method('getBody')->willReturn($explain_stream);

    $elasticsearch = $this->createMock(ReliefWebElasticsearchClient::class);
    $elasticsearch->method('getBaseIndexName')->willReturn('reliefweb');
    $elasticsearch->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(function (string $method, string $path, array $options) use ($search_response, $explain_response) {
        if ($path === 'reliefweb_file_fingerprints/_search') {
          $mlt = $options['json']['query']['bool']['must'][0]['more_like_this'] ?? [];
          $this->assertSame(
            [
              [
                '_index' => 'reliefweb_file_fingerprints',
                '_id' => '42',
              ],
            ],
            $mlt['like'] ?? NULL,
          );
          $this->assertSame(
            ReliefWebFileDuplicationInterface::MINHASH_BUCKET_COUNT,
            $mlt['max_query_terms'] ?? NULL,
          );
          return $search_response;
        }
        if ($path === 'reliefweb_file_fingerprints/_explain/99') {
          return $explain_response;
        }
        $this->fail('Unexpected Elasticsearch path: ' . $path);
      });

    $duplication = $this->createDuplicationService($elasticsearch);
    $results = $duplication->findSimilarFileFingerprintsById(42, 10, '80%', []);
    $this->assertCount(1, $results);
    $this->assertSame(99, $results[0]['id']);
    $this->assertEqualsWithDelta(0.5, $results[0]['similarity'], 0.0001);
  }

  /**
   * FindSimilarDocumentsForFile indexes when the fingerprint is missing.
   */
  public function testFindSimilarDocumentsForFileIndexesWhenMissing(): void {
    $file = $this->createMock(File::class);
    $file->method('id')->willReturn(7);

    $mget_stream = $this->createMock(StreamInterface::class);
    $mget_stream->method('getContents')->willReturn(json_encode([
      'docs' => [
        ['_id' => '7', 'found' => FALSE],
      ],
    ]));
    $mget_response = $this->createMock(ResponseInterface::class);
    $mget_response->method('getBody')->willReturn($mget_stream);

    $elasticsearch = $this->createMock(ReliefWebElasticsearchClient::class);
    $elasticsearch->method('getBaseIndexName')->willReturn('reliefweb');
    $elasticsearch->expects($this->once())
      ->method('request')
      ->with('POST', 'reliefweb_file_fingerprints/_mget', $this->anything())
      ->willReturn($mget_response);

    $duplication = $this->getMockBuilder(TestableReliefWebFileDuplication::class)
      ->setConstructorArgs([
        $this->createMock(StateInterface::class),
        $this->createFilesSettingsConfigFactory(),
        $elasticsearch,
        $this->createLoggerFactory(),
        $this->createMock(Connection::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(FileSystemInterface::class),
        $this->createMock(AccountInterface::class),
      ])
      ->onlyMethods(['indexFileFingerprint', 'findSimilarFileFingerprintsById', 'mapSimilarFilesToDocuments'])
      ->getMock();

    $duplication->expects($this->once())
      ->method('indexFileFingerprint')
      ->with($file)
      ->willReturn(TRUE);
    $duplication->expects($this->once())
      ->method('findSimilarFileFingerprintsById')
      ->with(7, 20, '80%', [])
      ->willReturn([
        [
          'id' => 99,
          'uuid' => 'x',
          'similarity' => 0.9,
          'similarity_percentage' => '90.0%',
          'score' => 1.0,
        ],
      ]);
    $duplication->expects($this->once())
      ->method('mapSimilarFilesToDocuments')
      ->willReturn([
        [
          'id' => 123,
          'title' => 'Match',
          'url' => 'http://example.com/123',
          'similarity' => 0.9,
          'similarity_percentage' => '90.0%',
          'score' => 1.0,
        ],
      ]);

    $results = $duplication->findSimilarDocumentsForFile($file, 'report');
    $this->assertCount(1, $results);
    $this->assertSame(123, $results[0]['id']);
  }

  /**
   * Create the duplication service with mocked dependencies.
   *
   * @param \Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient $elasticsearch
   *   Elasticsearch client mock.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $config_factory
   *   Optional config factory.
   *
   * @return \Drupal\Tests\reliefweb_files\Unit\Services\TestableReliefWebFileDuplication
   *   Service under test.
   */
  protected function createDuplicationService(
    ReliefWebElasticsearchClient $elasticsearch,
    ?ConfigFactoryInterface $config_factory = NULL,
  ): TestableReliefWebFileDuplication {
    $config_factory ??= $this->createApiSettingsConfigFactory();

    return new TestableReliefWebFileDuplication(
      $this->createMock(StateInterface::class),
      $config_factory,
      $elasticsearch,
      $this->createLoggerFactory(),
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(AccountInterface::class),
    );
  }

  /**
   * Config factory for index create/delete tests (reliefweb_api.settings).
   */
  protected function createApiSettingsConfigFactory(): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['shards', 1],
      ['replicas', 0],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('reliefweb_api.settings')->willReturn($config);
    return $config_factory;
  }

  /**
   * Config factory for duplicate detection lookups.
   */
  protected function createFilesSettingsConfigFactory(): ConfigFactoryInterface {
    $files_config = $this->createMock(ImmutableConfig::class);
    $files_config->method('get')->willReturnMap([
      [
        'duplicate_detection',
        [
          'max_documents' => 10,
          'max_files' => 20,
          'minimum_should_match' => '80%',
          'skip_access_check' => FALSE,
          'max_extract_pages' => 30,
          'max_extract_chars' => 300000,
        ],
      ],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(function (string $name) use ($files_config) {
      if ($name === 'reliefweb_files.settings') {
        return $files_config;
      }
      $api = $this->createMock(ImmutableConfig::class);
      $api->method('get')->willReturn(NULL);
      return $api;
    });
    return $config_factory;
  }

  /**
   * Logger factory mock.
   */
  protected function createLoggerFactory(): LoggerChannelFactoryInterface {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);
    return $logger_factory;
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
