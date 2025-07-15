<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\Tests\reliefweb_utility\Unit\Stub\LocalizationHelperStubNoNumberFormatter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests localization helper number formatting.
 */
#[CoversClass(LocalizationHelper::class)]
#[Group('reliefweb_utility')]
class LocalizationHelperNumberFormatTest extends UnitTestCase {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $sub = $this->prophesize(LanguageInterface::class);
    $sub->getId()->willReturn('fr');
    $this->languageManager = $this->prophesize(LanguageManager::class);
    $this->languageManager->getCurrentLanguage()->willReturn($sub->reveal());
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('language_manager', $this->languageManager->reveal());
  }

  /**
   * Test localization formatNumber.
   *
   * @param string|null $language
   *   Language code.
   * @param int|float $number
   *   Number to format.
   * @param string $expected
   *   Expected output.
   */
  #[DataProvider('providerFormatNumber')]
  public function testFormatNumber($language, $number, $expected) {
    $formatted = LocalizationHelper::formatNumber($number, $language);
    $this->assertEquals($formatted, $expected);
  }

  /**
   * Test localization formatNumber when there is no number formatter.
   *
   * @param string|null $language
   *   Language code.
   * @param int|float $number
   *   Number to format.
   * @param string $expected
   *   The expected output string.
   */
  #[DataProvider('providerFormatNumberNoNumberFormatter')]
  public function testFormatNumberNoNumberFormatter($language, $number, $expected) {
    $formatted = LocalizationHelperStubNoNumberFormatter::formatNumber($number, $language);
    $this->assertEquals($formatted, $expected);
  }

  /**
   * Provides data for testFormatNumber.
   *
   * @return array
   *   Test data.
   */
  public static function providerFormatNumber() {
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
   *   Test data.
   */
  public static function providerFormatNumberNoNumberFormatter() {
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
