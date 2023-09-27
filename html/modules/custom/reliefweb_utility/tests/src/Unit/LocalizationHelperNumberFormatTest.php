<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\Tests\reliefweb_utility\Unit\Stub\LocalizationHelperStubNoNumberFormatter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests localization helper number formatting.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper
 */
class LocalizationHelperNumberFormatTest extends UnitTestCase {

  /**
   * The language manager.
   */
  protected $language_manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $sub = $this->prophesize(LanguageInterface::class);
    $sub->getId()->willReturn('fr');
    $this->language_manager = $this->prophesize(LanguageManager::class);
    $this->language_manager->getCurrentLanguage()->willReturn($sub->reveal());
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('language_manager', $this->language_manager->reveal());
  }

  /**
   * Test localization formatNumber.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper::formatNumber
   *
   * @dataProvider providerFormatNumber
   *
   * @param string|null $language
   *   Language code.
   * @param int|float $number
   *   Number to format.
   * @param string $expected
   *   The expected output string.
   */
  public function testFormatNumber($language, $number, $expected) {
    $formatted = LocalizationHelper::formatNumber($number, $language);
    $this->assertEquals($formatted, $expected);
  }

  /**
   * Test localization formatNumber when there is no number formatter.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper::formatNumber
   *
   * @dataProvider providerFormatNumberNoNumberFormatter
   *
   * @param string|null $language
   *   Language code.
   * @param int|float $number
   *   Number to format.
   * @param string $expected
   *   The expected output string.
   */
  public function testFormatNumberNoNumberFormatter($language, $number, $expected) {
    $formatted = LocalizationHelperStubNoNumberFormatter::formatNumber($number, $language);
    $this->assertEquals($formatted, $expected);
  }

  /**
   * Provides data for testFormatNumber.
   *
   * @return array
   *   An array of test data.
   */
  public function providerFormatNumber() {
    return [
      [
        'en',
        123456,
        '123,456',
      ],
      [
        'fr',
        123456,
        // Narrow non breaking space.
        "123\xE2\x80\xAF456",
      ],
      // Returns unformatted number.
      [
        'invalid_language',
        123456,
        '123456',
      ],
      // Defaults to current language (FR).
      [
        NULL,
        123456,
        // Narrow non breaking space.
        "123\xE2\x80\xAF456",
      ],
    ];
  }

  /**
   * Provides data for testFormatNumber.
   *
   * @return array
   *   An array of test data.
   */
  public function providerFormatNumberNoNumberFormatter() {
    $expected = number_format(123456);
    // Defaults to number_format regardless of the language.
    return [
      [
        'en',
        123456,
        $expected,
      ],
      [
        'fr',
        123456,
        $expected,
      ],
      [
        'invalid_language',
        123456,
        $expected,
      ],
      [
        NULL,
        123456,
        $expected,
      ],
    ];
  }

}
