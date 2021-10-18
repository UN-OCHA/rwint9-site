<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use DateTime;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\reliefweb_utility\Plugin\Filter\IFrameFilter;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Plugin\Filter\IFrameFilter
 * @coversDefaultClass \Drupal\reliefweb_utility\Plugin\Filter\IFrameFilter
 */
class IFrameFilterTest extends UnitTestCase {

  /**
   * @var \Drupal\reliefweb_utility\Plugin\Filter\IFrameFilter
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $configuration['settings'] = [];
    $this->filter = new IFrameFilter($configuration, 'filter_iframe', [
      'provider' => 'test',
    ]);

    $this->filter
      ->setStringTranslation($this
      ->getStringTranslationStub());
  }

  /**
   * @covers ::convertIframeMarkup
   *
   * @dataProvider providerIframeMarkup
   *
   * @param string $html
   *   Input HTML.
   * @param array $expected
   *   The expected output string.
   */
  public function testIframeMarkup($html, $expected) {
    $this
      ->assertSame($expected, $this->filter
      ->convertIframeMarkup($html));
  }

  /**
   * Provides data for testIframeMarkup.
   *
   * @return array
   *   An array of test data.
   */
  public function providerIframeMarkup() {
    return [
      [
        '[iframe]',
        '',
      ],
      [
        '[iframe](https://example.com)',
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
