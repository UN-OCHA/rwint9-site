<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\Tests\UnitTestCase;

/**
 * Tests UrlHelper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\UrlHelper
 * @coversDefaultClass \Drupal\reliefweb_utility\Helpers\UrlHelper
 */
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
   */
  public function providerTestGetImageUriFromUrl() {
    return [
      ['', FALSE, ''],
      ['', TRUE, ''],
      ['not a url', FALSE, './2a/61/2a61385e-f3f3-3be6-b22d-2a11ed6cfcc9'],
      ['not a url', TRUE, './2a/61/2a61385e-f3f3-3be6-b22d-2a11ed6cfcc9'],
      ['styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png', FALSE, 'styles/m/public/resources-pdf-previews/f9/cf/f9cf81e8-f6a1-3fa0-978c-1a7f12ed6399.png'],
      ['styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png', TRUE, 'styles/m/public/resources-pdf-previews/f9/cf/f9cf81e8-f6a1-3fa0-978c-1a7f12ed6399.png'],
      ['/styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png', FALSE, '/resources-pdf-previews/2b/c6/2bc6c16d-2b7a-3722-bfb7-3c0e8d953544.png'],
      ['/styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png', TRUE, '/resources-pdf-previews/2b/c6/2bc6c16d-2b7a-3722-bfb7-3c0e8d953544.png'],
      ['public://styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png', FALSE, '/m/public/resources-pdf-previews/88/ff/88ff96de-6f1c-328c-bee7-270b87008fe5.png'],
      ['public://styles/m/public/resources-pdf-previews/1586365-UNOSAT_Assessment_Report_FL20210928THA_LowerNortheastern_Thailand_11102021.png', TRUE, '/m/public/resources-pdf-previews/88/ff/88ff96de-6f1c-328c-bee7-270b87008fe5.png'],
      ['resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', FALSE, 'resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', TRUE, 'resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', FALSE, 'styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', TRUE, 'styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['/sites/default/files/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', FALSE, 'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['/sites/default/files/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', TRUE, 'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['/sites/default/files/styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', FALSE, 'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['/sites/default/files/styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', TRUE, 'public://styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['/sites/default/files/styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', FALSE, 'public://resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
      ['/sites/default/files/styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png', TRUE, 'public://styles/m/public/resources-pdf-previews/65/41/6541ac55-afbb-30d2-9545-c2322a37b080.png'],
    ];
  }

  /**
   * Tests get image URI from URL.
   *
   * @dataProvider providerTestGetImageUriFromUrl
   * @covers ::getImageUriFromUrl
   *
   * @param string $uri
   *   Legacy URI.
   * @param boolean $preserve_style
   *   Whether to keep the style in the uri or not.
   * @param string $expected
   *   The expected query string.
   */
  public function testGetImageUriFromUrl($uri, $preserve_style, $expected) {
    $this->assertEquals(UrlHelper::getImageUriFromUrl($uri, $preserve_style), $expected);
  }

}
