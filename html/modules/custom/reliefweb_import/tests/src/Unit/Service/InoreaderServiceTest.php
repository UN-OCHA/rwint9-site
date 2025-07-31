<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit\Service;

use Drupal\reliefweb_import\Service\InoreaderService;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionEmptyBody;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\reliefweb_import\Service\InoreaderService
 */
class InoreaderServiceTest extends TestCase {

  /**
   * The Inoreader service instance.
   *
   * @var \Drupal\reliefweb_import\Service\InoreaderService
   */
  protected $service;

  /**
   * Http client mock.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Logger mock.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->httpClient = $this->createMock(ClientInterface::class);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn([]);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new InoreaderService($this->httpClient, $state);
    $this->service->setLogger($this->logger);
  }

  /**
   * Test extractTags method.
   *
   * @dataProvider tagExtractionProvider
   */
  public function testExtractTags($feed_name, $expected) {
    $tags = $this->service->extractTags($feed_name);
    $this->assertEquals($expected, $tags);
  }

  /**
   * Test cases for extractTags method.
   */
  public static function tagExtractionProvider() {
    return [
      [
        '[source:123][pdf:canonical][status:published]',
        [
          'source' => '123',
          'pdf' => 'canonical',
          'status' => 'published',
        ],
      ],
      [
        '[source:456][pdf:summary-link][wrapper:.main][remove:.ads]',
        [
          'source' => '456',
          'pdf' => 'summary-link',
          'wrapper' => '.main',
          'remove' => '.ads',
        ],
      ],
      [
        '[source:789][pdf:page-link][url:reliefweb][fallback:content]',
        [
          'source' => '789',
          'pdf' => 'page-link',
          'url' => 'reliefweb',
          'fallback' => 'content',
        ],
      ],
      [
      // No tags.
        '',
        [],
      ],
    ];
  }

  /**
   * Test parseTags method.
   *
   * @dataProvider parseTagsProvider
   */
  public function testParseTags(array $matches, array $expected) {
    // Use reflection to access the protected method.
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('parseTags');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$matches]);
    $this->assertEquals($expected, $result);
  }

  /**
   * Test cases for parseTags method.
   */
  public static function parseTagsProvider() {
    return [
      [
        ['source:123', 'pdf:canonical', 'status:published'],
        [
          'source' => '123',
          'pdf' => 'canonical',
          'status' => 'published',
        ],
      ],
      [
        ['source:456', 'pdf:summary-link', 'wrapper:.main', 'remove:.ads'],
        [
          'source' => '456',
          'pdf' => 'summary-link',
          'wrapper' => '.main',
          'remove' => '.ads',
        ],
      ],
      [
        ['source:789', 'pdf:page-link', 'url:reliefweb', 'fallback:content'],
        [
          'source' => '789',
          'pdf' => 'page-link',
          'url' => 'reliefweb',
          'fallback' => 'content',
        ],
      ],
      [
        ['wrapper:.main', 'wrapper:.sidebar'],
        [
          'wrapper' => ['.main', '.sidebar'],
        ],
      ],
      [
      // No tags.
        [],
        [],
      ],
    ];
  }

  /**
   * Test extractAndCleanBodyFromContent method.
   *
   * @dataProvider bodyContentProvider
   */
  public function testExtractAndCleanBodyFromContent($document, $tags, $id, $expected) {
    // Use reflection to access the protected method.
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('extractAndCleanBodyFromContent');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$document, $tags, $id]);
    $this->assertEquals($expected, $result);
  }

  /**
   * Test cases for extractAndCleanBodyFromContent.
   */
  public static function bodyContentProvider() {
    return [
      // Basic HTML, no cleaning.
      [
        ['summary' => ['content' => '<p>Hello <b>World</b></p>']],
        [],
        'id1',
        'Hello **World**',
      ],
      // Remove <b> tags.
      [
        ['summary' => ['content' => '<p>Hello <b>World</b></p>']],
        ['remove' => 'b'],
        'id2',
        'Hello',
      ],
      // Clean body (strip tags).
      [
        ['summary' => ['content' => '<div><h1>Title</h1><p>Text</p></div>']],
        ['content' => 'clean'],
        'id3',
        "# Title\n\nText",
      ],
    ];
  }

  /**
   * Test exception.
   */
  public function testExtractAndCleanBodyFromContentThrowsExceptionOnEmptyBody() {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('extractAndCleanBodyFromContent');
    $method->setAccessible(TRUE);

    $document = ['summary' => ['content' => '']];
    $tags = [];
    $id = 'id4';

    $this->expectException(ReliefwebImportExceptionEmptyBody::class);
    $method->invokeArgs($this->service, [$document, $tags, $id]);
  }

  /**
   * Test downloadHtmlPage success with valid HTML.
   */
  public function testDownloadHtmlPageSuccess() {
    $url = 'https://example.com/test.html';
    $html = '<html><body>Test</body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->expects($this->once())
      ->method('request')
      ->with('GET', $url, $this->arrayHasKey('timeout'))
      ->willReturn($response);

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('downloadHtmlPage');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$url, 5]);
    $this->assertEquals($html, $result);
  }

  /**
   * Test downloadHtmlPage with fallback to HTML content.
   */
  public function testDownloadHtmlPageFallbackSuccess() {
    $url = 'https://example.com/test.html';
    $html = '<html><body>Fallback</body></html>';
    $response = new Response(200, [], $html);

    $this->httpClient
      ->expects($this->exactly(1))
      ->method('request')
      ->with('GET', $url, $this->arrayHasKey('timeout'))
      ->willReturn($response);

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('downloadHtmlPage');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$url, 5]);
    $this->assertEquals($html, $result);
  }

  /**
   * Test downloadHtmlPage failure with exception.
   */
  public function testDownloadHtmlPageFailure() {
    $url = 'https://example.com/test.html';

    $this->httpClient
      ->expects($this->exactly(2))
      ->method('request')
      ->will($this->throwException(new \Exception('Network error')));

    $this->logger
      ->expects($this->atLeastOnce())
      ->method('info')
      ->with($this->stringContains('Failure with response code: Network error'));

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('downloadHtmlPage');
    $method->setAccessible(TRUE);

    $this->expectException(\Exception::class);
    $method->invokeArgs($this->service, [$url, 5]);
  }

}
