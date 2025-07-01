<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reliefweb token filter.
 */
#[CoversClass(ReliefwebTokenFilter::class)]
#[Group('reliefweb_utility')]
class ReliefwebTokenFilterTest extends UnitTestCase {

  /**
   * Test token callback.
   *
   * @param array $replacements
   *   Token replacements.
   * @param array $expected
   *   Expected output.
   * @param array $options
   *   Callback options.
   */
  #[DataProvider('providerTokenCallback')]
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
   *   Test data.
   */
  public static function providerTokenCallback() {
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
