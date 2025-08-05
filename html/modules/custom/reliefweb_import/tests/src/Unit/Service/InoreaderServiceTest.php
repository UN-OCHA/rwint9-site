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
    $result = $method->invokeArgs($this->service, [$html, ['.main']]);
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
    $tags = ['wrapper' => '.main'];
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
    $tags = ['wrapper' => '.main', 'url' => 'relative'];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    $this->assertEquals('https://example.com/relative.pdf', $result);

    // Case 4b: PDF link with wrapper and url filter as array.
    $html = '<div class="main"><a href="/relative.pdf">PDF</a><a href="/other.pdf">PDF2</a></div>';
    $tags = ['wrapper' => '.main', 'url' => ['relative', 'other']];
    $result = $method->invokeArgs($this->service, [$page_url, $html, $tags]);
    // Should match /relative.pdf first.
    $this->assertEquals('https://example.com/relative.pdf', $result);

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
   *
   * @dataProvider rewritePdfLinkProvider
   */
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
        ['source' => '123', 'wrapper' => '.main'],
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
        ['wrapper' => '.main'],
      ],
      // 9. Merge with tag alias (u => url).
      [
        [],
        ['u' => 'abc'],
        ['url' => 'abc'],
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
        ['puppeteer' => 'selector'],
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
        ['html' => '.content'],
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
   *
   * @dataProvider mergeTagsProvider
   */
  public function testMergeTags($tags, $extra_tags, $expected) {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('mergeTags');
    $method->setAccessible(TRUE);

    $result = $method->invokeArgs($this->service, [$tags, $extra_tags]);
    $this->assertEquals($expected, $result);
  }

}
