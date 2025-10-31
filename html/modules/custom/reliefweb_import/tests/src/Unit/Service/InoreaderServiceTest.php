<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit\Service;

use Drupal\reliefweb_import\Service\InoreaderService;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionEmptyBody;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test inoreader service.
 */
#[CoversClass(InoreaderService::class)]
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
    $state->method('get')->willReturn([
      'state' => [
        'w' => '.main-content',
        'remove' => ['.ads', '.sponsored', '.ads'],
        'f' => 'content',
      ],
    ]);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new InoreaderService($this->httpClient, $state);
    $this->service->setLogger($this->logger);
  }

  /**
   * Test extractTags method.
   */
  #[DataProvider('tagExtractionProvider')]
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
        '[source:state][source:456][pdf:canonical][s:published][wrapper:.sidebar]',
        [
          'source' => 'state',
          'pdf' => 'canonical',
          'status' => 'published',
          'wrapper' => ['.sidebar', '.main-content'],
          'remove' => ['.ads', '.sponsored'],
          'fallback' => 'content',
        ],
      ],
      [
        '[source:123][source:456][pdf:canonical][status:published]',
        [
          'source' => '123',
          'pdf' => 'canonical',
          'status' => 'published',
        ],
      ],
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
          'wrapper' => ['.main'],
          'remove' => ['.ads'],
        ],
      ],
      [
        '[source:789][pdf:page-link][url:reliefweb][fallback:content]',
        [
          'source' => '789',
          'pdf' => 'page-link',
          'url' => ['reliefweb'],
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
   */
  #[DataProvider('parseTagsProvider')]
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
          'wrapper' => ['.main'],
          'remove' => ['.ads'],
        ],
      ],
      [
        ['source:789', 'pdf:page-link', 'url:reliefweb', 'fallback:content'],
        [
          'source' => '789',
          'pdf' => 'page-link',
          'url' => ['reliefweb'],
          'fallback' => 'content',
        ],
      ],
      [
        ['source:789', 'pdf:page-link', 'u:reliefweb', 'f:content'],
        [
          'source' => '789',
          'pdf' => 'page-link',
          'url' => ['reliefweb'],
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
      [
        ['keyonly:'],
        [
          'keyonly' => '',
        ],
      ],
      [
        [':valueonly'],
        [
          '' => 'valueonly',
        ],
      ],
      [
        ['key:val:with:colons'],
        [
          'key' => 'val:with:colons',
        ],
      ],
      [
        [' spaced : value '],
        [
          'spaced' => 'value',
        ],
      ],
      [
        ['duplicate:one', 'duplicate:two'],
        [
          'duplicate' => 'one',
        ],
      ],
      [
        ['numkey1:123', 'numkey2:456'],
        [
          'numkey1' => '123',
          'numkey2' => '456',
        ],
      ],
      [
        ['specialchars:!@#$%^&*'],
        [
          'specialchars' => '!@#$%^&*',
        ],
      ],
      [
        ['arraykey:val1', 'arraykey:val2', 'arraykey:val3'],
        [
          'arraykey' => 'val1',
        ],
      ],
    ];
  }

  /**
   * Test extractAndCleanBodyFromContent method.
   */
  #[DataProvider('bodyContentProvider')]
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
   * Test extractPdfUrl method.
   */
  public function testExtractPdfUrl() {
    // Use reflection to access the protected method.
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('extractPdfUrl');
    $method->setAccessible(TRUE);

    $html = '<a href="https://example.com/path/to/file.pdf">Link</a>';
    $tag = 'a';
    $attribute = 'href';
    $expected = 'https://example.com/path/to/file.pdf';
    $result = $method->invokeArgs($this->service, [$html, $tag, $attribute]);
    $this->assertEquals($expected, $result);
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

  /**
   * Test extractPartFromHtml method.
   */
  public function testExtractPartFromHtml() {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('extractPartFromHtml');
    $method->setAccessible(TRUE);

    $html = '<div><section><p class="main">Hello World</p></section><p>Other</p></div>';

    // Test extracting by class selector.
    $result = $method->invokeArgs($this->service, [$html, '.main']);
    $this->assertStringContainsString('Hello World', $result);

    // Test extracting by tag selector.
    $result = $method->invokeArgs($this->service, [$html, 'section']);
    $this->assertStringContainsString('<section><p class="main">Hello World</p></section>', $result);

    // Test extracting with empty selector (should return full HTML).
    $result = $method->invokeArgs($this->service, [$html, '']);
    $this->assertEquals($html, $result);

    // Test extracting with array selector.
    $result = $method->invokeArgs($this->service, [$html, '.main']);
    $this->assertStringContainsString('Hello World', $result);

    // Test extracting with non-existing selector (should return NULL).
    $result = $method->invokeArgs($this->service, [$html, '.notfound']);
    $this->assertNull($result);

    // Test extracting with empty HTML (should return NULL).
    $result = $method->invokeArgs($this->service, ['', '.main']);
    $this->assertNull($result);
  }

  /**
   * Test removeHtmlElements method.
   */
  public function testRemoveHtmlElements() {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('removeHtmlElements');
    $method->setAccessible(TRUE);

    // Test case with HTML elements present in the body content.
    $htmlContent = '<p>This is a paragraph of text.</p><span>This is another element.</span>';
    $removeSelector = 'span';
    $result = $method->invokeArgs($this->service, [$htmlContent, $removeSelector]);
    $expectedResult = '<p>This is a paragraph of text.</p>';

    // Assert that the result matches the expected output.
    $this->assertEquals($expectedResult, $result);

    // Test case with no HTML elements in the body content.
    $htmlContent = '';
    $removeSelector = 'span';
    $result = $method->invokeArgs($this->service, [$htmlContent, $removeSelector]);
    $expectedResult = '';

    // Assert that the result matches the expected output.
    $this->assertEquals($expectedResult, $result);

    // Test case with a single HTML element in the body content.
    $htmlContent = '<p>This is a paragraph of text.</p>';
    $removeSelector = 'span';
    $result = $method->invokeArgs($this->service, [$htmlContent, $removeSelector]);
    $expectedResult = '<p>This is a paragraph of text.</p>';

    // Assert that the result matches the expected output.
    $this->assertEquals($expectedResult, $result);

    // Test case with multiple HTML elements in the body content.
    $htmlContent = '<div><p>This is a paragraph of text.</p><span>This is another element.</span></div>';
    $removeSelector = 'span';
    $result = $method->invokeArgs($this->service, [$htmlContent, $removeSelector]);
    $expectedResult = '<div><p>This is a paragraph of text.</p></div>';

    // Assert that the result matches the expected output.
    $this->assertEquals($expectedResult, $result);

    // Test case with no HTML elements in the body.
    $htmlContent = '';
    $removeSelector = '';
    $result = $method->invokeArgs($this->service, [$htmlContent, $removeSelector]);
    $expectedResult = '';

    // Assert that the result matches the expected output.
    $this->assertEquals($expectedResult, $result);

    // Test case with HTML element in removeSelector array.
    $htmlContent = '<div><p>This is a paragraph of text.</p><span>This is another element.</span></div>';
    $removeSelector = 'span';
    $result = $method->invokeArgs($this->service, [$htmlContent, $removeSelector]);
    $expectedResult = '<div><p>This is a paragraph of text.</p></div>';

    // Assert that the result matches the expected output.
    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Test tryToExtractPdfFromHtml method.
   */
  public function testTryToExtractPdfFromHtml() {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('tryToExtractPdfFromHtml');
    $method->setAccessible(TRUE);

    $page_url = 'https://example.com/page.html';

    // Case 1: Simple PDF link, no wrapper, no url filter.
    $html = '<div><a href="https://example.com/file.pdf">PDF</a></div>';
    $tags = [];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('https://example.com/file.pdf', $result);

    // Case 2: PDF link inside wrapper.
    $html = '<div class="main"><a href="https://example.com/wrapped.pdf">PDF</a></div>';
    $tags = ['wrapper' => ['.main']];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('https://example.com/wrapped.pdf', $result);

    // Case 3: PDF link with url filter (contains).
    $html = '<div><a href="https://example.com/file-abc.pdf">PDF</a><a href="https://example.com/file-def.pdf">PDF2</a></div>';
    $tags = ['url' => 'def'];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('https://example.com/file-def.pdf', $result);

    // Case 3b: PDF link with url filter as array (multiple contains).
    $html = '<div><a href="https://example.com/file-abc.pdf">PDF</a><a href="https://example.com/file-def.pdf">PDF2</a><a href="https://example.com/file-xyz.pdf">PDF3</a></div>';
    $tags = ['url' => ['def', 'xyz']];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    // Should match the first found: file-def.pdf.
    $this->assertEquals('https://example.com/file-def.pdf', $result);

    // Case 4: PDF link with wrapper and url filter.
    $html = '<div class="main"><a href="/relative.pdf">PDF</a></div>';
    $tags = ['wrapper' => ['.main'], 'url' => 'relative'];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('/relative.pdf', $result);

    // Case 4b: PDF link with wrapper and url filter as array.
    $html = '<div class="main"><a href="/relative.pdf">PDF</a><a href="/other.pdf">PDF2</a></div>';
    $tags = ['wrapper' => ['.main'], 'url' => ['relative', 'other']];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    // Should match /relative.pdf first.
    $this->assertEquals('/relative.pdf', $result);

    // Case 5: No PDF link found, but link to txt file.
    $html = '<div><a href="https://example.com/file.txt">TXT</a></div>';
    $tags = [];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('https://example.com/file.txt', $result);

    // Case 6: Empty HTML.
    $html = '';
    $tags = [];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('', $result);

    // Case 7: Multiple wrappers, first match wins.
    $html = '<div class="main"><a href="https://example.com/first.pdf">PDF1</a></div><div class="sidebar"><a href="https://example.com/second.pdf">PDF2</a></div>';
    $tags = ['wrapper' => ['.main', '.sidebar']];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('https://example.com/first.pdf', $result);

    // Case 8: url filter does not match any link.
    $html = '<div><a href="https://example.com/file-abc.pdf">PDF</a><a href="https://example.com/file-def.pdf">PDF2</a></div>';
    $tags = ['url' => 'notfound'];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('', $result);

    // Case 9: url filter as array, none match.
    $html = '<div><a href="https://example.com/file-abc.pdf">PDF</a><a href="https://example.com/file-def.pdf">PDF2</a></div>';
    $tags = ['url' => ['notfound1', 'notfound2']];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('', $result);
  }

  /**
   * Data provider for testRewritePdfLink.
   */
  public static function rewritePdfLinkProvider() {
    return [
      // No replace tag, PDF unchanged.
      [
        'https://example.com/file.pdf',
        [],
        'https://example.com/file.pdf',
      ],
      // Single replace: replace 'file.pdf' with 'newfile.pdf'.
      [
        'https://example.com/file.pdf',
        [
          'replace' => 'file.pdf:newfile.pdf',
        ],
        'https://example.com/newfile.pdf',
      ],
      // Multiple replaces: first match wins.
      [
        'https://example.com/file.pdf',
        [
          'replace' => [
            'file.pdf:first.pdf',
            'file.pdf:second.pdf',
          ],
        ],
        'https://example.com/first.pdf',
      ],
      // Replace tag is not an array, should ignore.
      [
        'https://example.com/file.pdf',
        ['replace' => 'not-an-array'],
        'https://example.com/file.pdf',
      ],
      // Empty PDF, should return empty.
      [
        '',
        ['replace' => ['file.pdf:newfile.pdf']],
        '',
      ],
    ];
  }

  /**
   * Test rewritePdfLink method.
   */
  #[DataProvider('rewritePdfLinkProvider')]
  public function testRewritePdfLink($pdf, $tags, $expected) {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('rewritePdfLink');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$pdf, $tags]);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testMergeTags.
   */
  public static function mergeTagsProvider() {
    return [
      // 1. Simple merge, no overlap.
      [
        ['source' => '123'],
        ['wrapper' => '.main'],
        ['source' => '123', 'wrapper' => ['.main']],
      ],
      // 2. Overwrite single-value tag.
      [
        ['source' => '123', 'status' => 'draft'],
        ['status' => 'published'],
        ['source' => '123', 'status' => 'published'],
      ],
      // 3. Merge multi-value tag (wrapper).
      [
        ['wrapper' => '.main'],
        ['wrapper' => '.sidebar'],
        ['wrapper' => ['.main', '.sidebar']],
      ],
      // 4. Merge multi-value tag (url) with array.
      [
        ['url' => ['abc']],
        ['url' => ['def']],
        ['url' => ['abc', 'def']],
      ],
      // 5. Merge multi-value tag (url) with string.
      [
        ['url' => 'abc'],
        ['url' => 'def'],
        ['url' => ['abc', 'def']],
      ],
      // 6. Merge multi-value tag (wrapper) with array and string.
      [
        ['wrapper' => ['.main']],
        ['wrapper' => '.sidebar'],
        ['wrapper' => ['.main', '.sidebar']],
      ],
      // 7. Merge multi-value tag (wrapper) with duplicate values.
      [
        ['wrapper' => ['.main']],
        ['wrapper' => ['.main', '.sidebar']],
        ['wrapper' => ['.main', '.sidebar']],
      ],
      // 8. Merge with tag alias (w => wrapper).
      [
        [],
        ['w' => '.main'],
        ['wrapper' => ['.main']],
      ],
      // 9. Merge with tag alias (u => url).
      [
        [],
        ['u' => 'abc'],
        ['url' => ['abc']],
      ],
      // 10. Merge with tag alias (r => replace).
      [
        [],
        ['r' => 'foo:bar'],
        ['replace' => 'foo:bar'],
      ],
      // 11. Merge with tag alias (t => timeout).
      [
        [],
        ['t' => '30'],
        ['timeout' => '30'],
      ],
      // 12. Merge with tag alias (s => status).
      [
        [],
        ['s' => 'published'],
        ['status' => 'published'],
      ],
      // 13. Merge with tag alias (f => fallback).
      [
        [],
        ['f' => 'content'],
        ['fallback' => 'content'],
      ],
      // 14. Merge with tag alias (p => puppeteer).
      [
        [],
        ['p' => 'selector'],
        ['puppeteer' => ['selector']],
      ],
      // 15. Merge with tag alias (pa => puppeteer-attrib).
      [
        [],
        ['pa' => 'href'],
        ['puppeteer-attrib' => 'href'],
      ],
      // 16. Merge with tag alias (pb => puppeteer-blob).
      [
        [],
        ['pb' => '1'],
        ['puppeteer-blob' => '1'],
      ],
      // 17. Merge with tag alias (h => html).
      [
        [],
        ['h' => '.content'],
        ['html' => ['.content']],
      ],
      // 18. Merge with tag alias (d => delay).
      [
        [],
        ['d' => '1000'],
        ['delay' => '1000'],
      ],
      // 19. Merge multi-value tag with both arrays.
      [
        ['wrapper' => ['.main']],
        ['wrapper' => ['.sidebar', '.footer']],
        ['wrapper' => ['.main', '.sidebar', '.footer']],
      ],
      // 20. Merge multi-value tag with array and duplicate string.
      [
        ['url' => ['abc', 'def']],
        ['url' => 'def'],
        ['url' => ['abc', 'def']],
      ],
    ];
  }

  /**
   * Test mergeTags method.
   */
  #[DataProvider('mergeTagsProvider')]
  public function testMergeTags($tags, $extra_tags, $expected) {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('mergeTags');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$tags, $extra_tags]);
    $this->assertEquals($expected, $result);
  }

  /**
   * Test processDocumentData method.
   */
  public function testProcessDocumentData() {
    $service = $this->service;

    // Mock dependencies.
    $service->setLogger($this->logger);
    $service->setSettings(['fetch_timeout' => 5]);

    // Minimal valid document with [source:123] tag.
    $document = [
      'id' => 'doc-1',
      'title' => 'Test Title',
      'published' => 1720000000,
      'canonical' => [
        ['href' => 'https://example.com/test.pdf'],
      ],
      'origin' => [
        'title' => '[source:123] [pdf:canonical]',
      ],
      'summary' => [
        'content' => '<p>Body content</p>',
      ],
    ];

    // Patch sanitizeText and DateHelper::format for deterministic output.
    $service = $this->getMockBuilder(get_class($service))
      ->onlyMethods(['sanitizeText'])
      ->setConstructorArgs([$this->httpClient, $this->createMock(StateInterface::class)])
      ->getMock();
    $service->setLogger($this->logger);
    $service->setSettings(['fetch_timeout' => 5]);
    $service->method('sanitizeText')->willReturnCallback(function ($text) {
      return $text;
    });

    $result = $service->processDocumentData($document);

    $this->assertEquals('Test Title', $result['title']);
    $this->assertEquals('', $result['body']);
    $this->assertEquals('https://example.com/test.pdf', $result['origin']);
    $this->assertEquals('2024-07-03T19:46:40+10:00', $result['published']);
    $this->assertEquals([123], $result['source']);
    $this->assertEquals([267], $result['language']);
    $this->assertEquals([254], $result['country']);
    $this->assertEquals([8], $result['format']);
    $this->assertEquals('https://example.com/test.pdf', $result['file_data']['pdf']);
    $this->assertTrue($result['_has_pdf']);
    $this->assertEquals(['source' => '123', 'pdf' => 'canonical'], $result['_tags']);
    $this->assertArrayHasKey('published', $result);
    $this->assertArrayHasKey('_screenshot', $result);
    $this->assertArrayHasKey('_log', $result);

    // Test fallback to URL for empty title.
    $document['title'] = '';
    $result = $service->processDocumentData($document);
    $this->assertEquals('https://example.com/test.pdf', $result['title']);

    // Test title truncation.
    $document['title'] = str_repeat('A', 300);
    $result = $service->processDocumentData($document);
    $this->assertTrue(strlen($result['title']) <= 255);

    // Test short title fallback.
    $document['title'] = 'Short';
    $result = $service->processDocumentData($document);
    $this->assertEquals('https://example.com/test.pdf', $result['title']);
  }

  /**
   * Data provider for testExtractPdfUrlUsingSelectors.
   */
  public static function extractPdfUrlUsingSelectorsProvider() {
    return [
      // 1. Simple <a> tag with href.
      [
        '<div><a href="https://example.com/file.pdf">PDF</a></div>',
        ['a'],
        'https://example.com/file.pdf',
      ],
      // 2. <iframe> tag with src.
      [
        '<iframe src="https://example.com/doc.pdf"></iframe>',
        ['iframe|src'],
        'https://example.com/doc.pdf',
      ],
      // 3. <a> tag with custom attribute.
      [
        '<a data-link="https://example.com/custom.pdf">PDF</a>',
        ['a|data-link'],
        'https://example.com/custom.pdf',
      ],
      // 4. Selector does not match anything.
      [
        '<div><a href="https://example.com/file.pdf">PDF</a></div>',
        ['iframe'],
        NULL,
      ],
      // 5. Multiple selectors, first match wins.
      [
        '<div><a href="https://example.com/file1.pdf">PDF1</a><a href="https://example.com/file2.pdf">PDF2</a></div>',
        ['a', 'a'],
        'https://example.com/file1.pdf',
      ],
      // 6. Multiple selectors, second matches.
      [
        '<div><span class="extra"></span><a href="https://example.com/file2.pdf">PDF2</a></div>',
        ['span|data', 'a'],
        'https://example.com/file2.pdf',
      ],
      // 7. Empty HTML.
      [
        '',
        ['a'],
        NULL,
      ],
      // 8. Empty selectors.
      [
        '<a href="https://example.com/file.pdf">PDF</a>',
        [],
        NULL,
      ],
      // 9. Selector with attribute override, but attribute missing.
      [
        '<a href="https://example.com/file.pdf">PDF</a>',
        ['a|data-link'],
        NULL,
      ],
      // 10. Multiple selectors, none match.
      [
        '<div><span>Nothing</span></div>',
        ['iframe', 'object'],
        NULL,
      ],
    ];
  }

  /**
   * Test extractPdfUrlUsingSelectors method.
   */
  #[DataProvider('extractPdfUrlUsingSelectorsProvider')]
  public function testExtractPdfUrlUsingSelectors($html, $selectors, $expected) {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('extractPdfUrlUsingSelectors');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$html, $selectors]);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testMakePdfLinkAbsolute.
   */
  public static function makePdfLinkAbsoluteProvider() {
    return [
      // Absolute PDF URL, should remain unchanged.
      [
        'https://example.com/file.pdf',
        'https://example.com/page.html',
        'https://example.com/file.pdf',
      ],
      // Relative PDF URL, should be made absolute using page_url.
      [
        '/file.pdf',
        'https://example.com/page.html',
        'https://example.com/file.pdf',
      ],
      // Relative PDF URL, page_url with http scheme.
      [
        '/docs/file.pdf',
        'https://example.org/some/page',
        'https://example.org/docs/file.pdf',
      ],
      // PDF URL missing leading slash.
      [
        'docs/file.pdf',
        'https://example.com/page.html',
        'https://example.com/docs/file.pdf',
      ],
      // Empty PDF URL, should return empty string.
      [
        '',
        'https://example.com/page.html',
        '',
      ],
      // PDF URL is already absolute with http.
      [
        'http://example.com/file.pdf',
        'https://example.com/page.html',
        'http://example.com/file.pdf',
      ],
      // PDF URL is already absolute with https.
      [
        'https://example.com/file.pdf',
        'http://example.com/page.html',
        'https://example.com/file.pdf',
      ],
      // PDF URL is relative, page_url has port.
      [
        '/file.pdf',
        'https://example.com:8080/page.html',
        'https://example.com/file.pdf',
      ],
      // PDF URL is relative, page_url is just domain.
      [
        '/file.pdf',
        'https://example.com',
        'https://example.com/file.pdf',
      ],
    ];
  }

  /**
   * Test makePdfLinkAbsolute method.
   */
  #[DataProvider('makePdfLinkAbsoluteProvider')]
  public function testMakePdfLinkAbsolute($pdf, $page_url, $expected) {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('makePdfLinkAbsolute');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$pdf, $page_url]);
    $this->assertEquals($expected, $result);
  }

  /**
   * Test isMultiValueTag method.
   */
  public function testIsMultiValueTag() {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isMultiValueTag');
    $method->setAccessible(TRUE);
    $this->assertTrue($method->invokeArgs($this->service, ['wrapper']));
    $this->assertFalse($method->invokeArgs($this->service, ['status']));
  }

  /**
   * Test fixLegacyPuppeteer2Tag method.
   */
  public function testFixLegacyPuppeteer2Tag() {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('fixLegacyPuppeteer2Tag');
    $method->setAccessible(TRUE);
    $tags = ['puppeteer' => 'foo', 'puppeteer2' => 'bar'];
    $result = $method->invokeArgs($this->service, [$tags]);
    $this->assertEquals('foo|bar', $result['puppeteer']);
  }

}
