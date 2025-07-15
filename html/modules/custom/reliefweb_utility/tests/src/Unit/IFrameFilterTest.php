<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Plugin\Filter\IFrameFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests iframe filter.
 */
#[CoversClass(IFrameFilter::class)]
#[Group('reliefweb_utility')]
class IFrameFilterTest extends UnitTestCase {

  /**
   * Iframe filter.
   *
   * @var \Drupal\reliefweb_utility\Plugin\Filter\IFrameFilter
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $configuration['settings'] = [];
    $this->filter = new IFrameFilter($configuration, 'filter_iframe', [
      'provider' => 'test',
    ]);

    $this->filter->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Test iframe markup.
   *
   * @param string $html
   *   Input HTML.
   * @param array $expected
   *   Expected output.
   */
  #[DataProvider('providerIframeMarkup')]
  public function testIframeMarkup($html, $expected) {
    $this->assertSame($expected, $this->filter->process($html, 'en')->__toString());
  }

  /**
   * Provides data for testIframeMarkup.
   *
   * @return array
   *   Test data.
   */
  public static function providerIframeMarkup() {
    return [
      [
        '[iframe]',
        '',
      ],
      [
        '[iframe]()',
        '',
      ],
      [
        '[iframe](  )',
        '',
      ],
      [
        '[iframe](https://example.com)',
        '<iframe width="1000" height="500" title="iframe" src="https://example.com" frameborder="0" allowfullscreen></iframe>',
      ],
      [
        '[iframe](  https://example.com   )',
        '<iframe width="1000" height="500" title="iframe" src="https://example.com" frameborder="0" allowfullscreen></iframe>',
      ],
      [
        '[iframe A long title](https://example.com)',
        '<iframe width="1000" height="500" title="A long title" src="https://example.com" frameborder="0" allowfullscreen></iframe>',
      ],
      [
        '[iframe:800 Test](https://example.com)',
        '<iframe width="800" height="400" title="Test" src="https://example.com" frameborder="0" allowfullscreen></iframe>',
      ],
      [
        '[iframe:700x350 Test](https://example.com)',
        '<iframe width="700" height="350" title="Test" src="https://example.com" frameborder="0" allowfullscreen></iframe>',
      ],
    ];
  }

}
