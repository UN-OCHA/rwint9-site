<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter;
use Drupal\Tests\UnitTestCase;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter
 * @coversDefaultClass \Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter
 */
class ReliefwebTokenFilterTest extends UnitTestCase {

  /**
   * @covers ::tokenCallback
   *
   * @dataProvider providerTokenCallback
   *
   * @param string $html
   *   Input HTML.
   * @param array $expected
   *   The expected output string.
   */
  public function testTokenCallback($replacements, $expected, $options) {
    $data = [];
    $bubbleable_metadata = [];

    ReliefwebTokenFilter::tokenCallback($replacements, $data, $options, $bubbleable_metadata);
    $this->assertSame($expected, $replacements);
  }

  /**
   * Provides data for testTokenCallback.
   *
   * @return array
   *   An array of test data.
   */
  public function providerTokenCallback() {
    return [
      [
        [],
        [],
        [],
      ],
      [
        [
          '[disaster-map:WF]' => 'BE',
        ],
        [
          '[disaster-map:WF]' => 'BE',
        ],
        [],
      ],
      [
        [
          '[node:title]' => '[node:title]',
        ],
        [
          '[node:title]' => '[node:title]',
        ],
        [],
      ],
      [
        [],
        [],
        ['clear' => FALSE],
      ],
      [
        [
          '[disaster-map:WF]' => 'BE',
        ],
        [
          '[disaster-map:WF]' => 'BE',
        ],
        ['clear' => FALSE],
      ],
      [
        [
          '[node:title]' => '[node:title]',
        ],
        [
          '[node:title]' => '[node:title]',
        ],
        ['clear' => FALSE],
      ],
      [
        [],
        [],
        ['clear' => TRUE],
      ],
      [
        [
          '[disaster-map:WF]' => 'BE',
        ],
        [
          '[disaster-map:WF]' => 'BE',
        ],
        ['clear' => TRUE],
      ],
      [
        [
          '[node:title]' => '[node:title]',
        ],
        [
          '[node:title]' => '',
        ],
        ['clear' => TRUE],
      ],
      [
        [
          '[disaster-map:WF]' => 'BE',
          '[node:title]' => '[node:title]',
        ],
        [
          '[disaster-map:WF]' => 'BE',
          '[node:title]' => '',
        ],
        ['clear' => TRUE],
      ],
    ];
  }

}
