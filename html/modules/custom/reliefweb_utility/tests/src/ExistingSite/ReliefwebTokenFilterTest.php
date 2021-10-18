<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\ExistingSite;

use Drupal\Core\Utility\Token;
use Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter
 * @coversDefaultClass \Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter
 */
class ReliefwebTokenFilterTest extends ExistingSiteBase {

  /**
   * The token service under test.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->token = \Drupal::token();
  }

  /**
   * @covers ::process
   *
   * @dataProvider providerProcess
   *
   * @param string $html
   *   Input HTML.
   * @param string $expected
   *   The expected output string.
   */
  public function testProcess($text, $expected) {
    $test = check_markup($text, 'token_markdown');
    if (!is_string($test)) {
      $test = $test->__toString();
    }

    $this->assertStringContainsString($expected, $test);
  }

  /**
   * Provides data for testProcess.
   *
   * @return array
   *   An array of test data.
   */
  public function providerProcess() {
    return [
      [
        '',
        '',
      ],
      [
        '[disaster-map:WF]',
        'disaster-map-wf',
      ],
      [
        '[node:title]',
        '[node:title]',
      ],
    ];
  }

}
