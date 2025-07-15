<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests UrlHelper.
 */
#[CoversClass(UrlHelper::class)]
#[Group('reliefweb_utility')]
class UrlHelperTest extends UnitTestCase {

  /**
   * Random helper.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $random;

  /**
   * Provides test data for testGetImageUriFromUrl().
   *
   * @return array
   *   Test data.
   */
  public static function providerTestGetImageUriFromUrl() {
    return [
      ['', FALSE, ''],
      ['', TRUE, ''],
      ['not a url', FALSE, './2a/61/2a61385e-f3f3-3be6-b22d-2a11ed6cfcc9'],
      ['not a url', TRUE, './2a/61/2a61385e-f3f3-3be6-b22d-2a11ed6cfcc9'],
      [
        'styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png',
        FALSE,
        'styles/m/public/resources-pdf-previews/f9/cf/f9cf81e8-f6a1-3fa0-978c-1a7f12ed6399.png',
      ],
      [
        'styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png',
        TRUE,
        'styles/m/public/resources-pdf-previews/f9/cf/f9cf81e8-f6a1-3fa0-978c-1a7f12ed6399.png',
      ],
      [
        '/styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png',
        FALSE,
        '/resources-pdf-previews/2b/c6/2bc6c16d-2b7a-3722-bfb7-3c0e8d953544.png',
      ],
      [
        '/styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png',
        TRUE,
        '/resources-pdf-previews/2b/c6/2bc6c16d-2b7a-3722-bfb7-3c0e8d953544.png',
      ],
      [
        'public://styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png',
        FALSE,
        '/m/public/resources-pdf-previews/88/ff/88ff96de-6f1c-328c-bee7-270b87008fe5.png',
      ],
      [
        'public://styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png',
        TRUE,
        '/m/public/resources-pdf-previews/88/ff/88ff96de-6f1c-328c-bee7-270b87008fe5.png',
      ],
      [
        'resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        FALSE,
        'resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        'resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        TRUE,
        'resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        'styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        FALSE,
        'styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        'styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        TRUE,
        'styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        '/sites/default/files/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        FALSE,
        'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        '/sites/default/files/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        TRUE,
        'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        '/sites/default/files/styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        FALSE,
        'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        '/sites/default/files/styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        FALSE,
        'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
      [
        '/sites/default/files/styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
        TRUE,
        'public://styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png',
      ],
    ];
  }

  /**
   * Tests get image URI from URL.
   *
   * @param string $uri
   *   Legacy URI.
   * @param bool $preserve_style
   *   Whether to keep the style in the uri or not.
   * @param string $expected
   *   The expected query string.
   */
  #[DataProvider('providerTestGetImageUriFromUrl')]
  public function testGetImageUriFromUrl($uri, $preserve_style, $expected) {
    $this->assertEquals(UrlHelper::getImageUriFromUrl($uri, $preserve_style), $expected);
  }

  /**
   * Tests stripParametersAndFragment method.
   */
  public function testStripParametersAndFragment() {
    $testCases = [
      // URL with query and fragment.
      ['http://example.com/path?query=123#fragment', 'http://example.com/path'],
      // URL with credentials, port, query, and fragment.
      [
        'https://user:pass@example.com:8080/path/to/page?foo=bar#section',
        'https://user:pass@example.com:8080/path/to/page',
      ],
      // URL without path, query, or fragment.
      ['http://example.com', 'http://example.com'],
      // URL with only query.
      ['http://example.com?query=123', 'http://example.com'],
      // URL with only fragment.
      ['http://example.com#fragment', 'http://example.com'],
      // URL with just a path.
      ['http://example.com/path', 'http://example.com/path'],
      // FTP URL with credentials and query.
      ['ftp://user@example.com/path?param=value', 'ftp://user@example.com/path'],
      // Invalid URL. This method is not a URL validator to this is expected.
      ['not a url', 'not a url'],
      // Empty string.
      ['', ''],
    ];

    foreach ($testCases as [$input, $expected]) {
      $this->assertEquals(
        $expected,
        UrlHelper::stripParametersAndFragment($input),
        "Failed asserting that '$input' is stripped correctly."
      );
    }
  }

  /**
   * Data provider for testGetFilenameFromContentDisposition().
   *
   * @return array
   *   Test cases: [header, expected filename].
   */
  public static function providerTestGetFilenameFromContentDisposition() {
    return [
      // Basic quoted filename (double quotes).
      ['attachment; filename="example.txt"', 'example.txt'],
      // Basic quoted filename (single quotes).
      ["attachment; filename='example.txt'", 'example.txt'],
      // Basic unquoted filename.
      ['attachment; filename=example.txt', 'example.txt'],
      // RFC 5987 encoded filename* with UTF-8 (unquoted).
      ["attachment; filename*=UTF-8''ex%C3%A9mple.txt", 'exémple.txt'],
      // RFC 5987 encoded filename* with UTF-8 (double quotes).
      ['attachment; filename*="UTF-8\'\'ex%C3%A9mple.txt"', 'exémple.txt'],
      // RFC 5987 encoded filename* with UTF-8 (single quotes).
      ["attachment; filename*='UTF-8\'\'ex%C3%A9mple.txt'", 'exémple.txt'],
      // RFC 5987 encoded filename* with ISO-8859-1 (unquoted).
      ["attachment; filename*=ISO-8859-1''ex%E9mple.txt", 'exémple.txt'],
      // RFC 5987 encoded filename* with ISO-8859-1 (double quotes).
      ['attachment; filename*="ISO-8859-1\'\'ex%E9mple.txt"', 'exémple.txt'],
      // RFC 5987 encoded filename* with ISO-8859-1 (single quotes).
      ["attachment; filename*='ISO-8859-1\'\'ex%E9mple.txt'", 'exémple.txt'],
      // Filename* with language (unquoted).
      ["attachment; filename*=UTF-8'en'%E2%82%AC%20rates.txt", '€ rates.txt'],
      // Filename* with language (double quotes).
      ['attachment; filename*="UTF-8\'en\'%E2%82%AC%20rates.txt"', '€ rates.txt'],
      // Filename* with language (single quotes).
      ["attachment; filename*='UTF-8\'en\'%E2%82%AC%20rates.txt'", '€ rates.txt'],
      // Filename* with path (should return basename only).
      ["attachment; filename*=UTF-8''%2Ftmp%2Fevil.txt", 'evil.txt'],
      // Filename* with path and quotes.
      ['attachment; filename*="UTF-8\'\'%2Ftmp%2Fevil.txt"', 'evil.txt'],
      ["attachment; filename*='UTF-8\'\'%2Ftmp%2Fevil.txt'", 'evil.txt'],
      // Filename with path (should return basename only).
      ['attachment; filename="/tmp/evil.txt"', 'evil.txt'],
      ["attachment; filename='/tmp/evil.txt'", 'evil.txt'],
      // Filename with semicolon (quoted).
      ['attachment; filename="weird;name.txt"', 'weird;name.txt'],
      // Filename with percent encoding (unquoted).
      ['attachment; filename=ex%20ample.txt', 'ex ample.txt'],
      // Filename with percent encoding (quoted).
      ['attachment; filename="ex%20ample.txt"', 'ex ample.txt'],
      // Multiple parameters, filename* first.
      ["attachment; filename*=UTF-8''file%C3%A9.txt; filename=\"fallback.txt\"", 'fileé.txt'],
      // Multiple parameters, filename* after filename.
      ["attachment; filename=\"fallback.txt\"; filename*=UTF-8''file%C3%A9.txt", 'fileé.txt'],
      // Only disposition, no filename.
      ['attachment', ''],
      // Empty header.
      ['', ''],
      // Random header.
      ['inline; foo=bar', ''],
      // Filename* with both quotes (non-standard, should handle).
      ['attachment; filename*=\'UTF-8\'\'singlequote.txt\'', 'singlequote.txt'],
      ['attachment; filename*="UTF-8\'\'doublequote.txt"', 'doublequote.txt'],
      // Filename with both quotes (non-standard, should handle).
      ["attachment; filename='singlequote.txt'", 'singlequote.txt'],
      ['attachment; filename="doublequote.txt"', 'doublequote.txt'],
    ];
  }

  /**
   * Tests getFilenameFromContentDisposition().
   *
   * @param string $header
   *   The Content-Disposition header value.
   * @param string $expected
   *   The expected filename.
   */
  #[DataProvider('providerTestGetFilenameFromContentDisposition')]
  public function testGetFilenameFromContentDisposition($header, $expected) {
    $this->assertSame(
      $expected,
      UrlHelper::getFilenameFromContentDisposition($header)
    );
  }

}
