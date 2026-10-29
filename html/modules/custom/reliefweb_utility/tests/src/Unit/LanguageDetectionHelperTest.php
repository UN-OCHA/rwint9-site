<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\LanguageDetectionHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests language detection helper.
 */
#[CoversClass(LanguageDetectionHelper::class)]
#[Group('reliefweb_utility')]
class LanguageDetectionHelperTest extends UnitTestCase {

  /**
   * Test hasEnoughText for representative samples.
   *
   * @param string $text
   *   Input text.
   * @param bool $expected
   *   Whether the character threshold should be met.
   */
  #[DataProvider('providerHasEnoughText')]
  public function testHasEnoughText(string $text, bool $expected): void {
    $this->assertSame(
      $expected,
      LanguageDetectionHelper::hasEnoughText($text, 200),
    );
  }

  /**
   * Data provider for testHasEnoughText.
   *
   * @return array<string, array{0: string, 1: bool}>
   *   Test cases.
   */
  public static function providerHasEnoughText(): array {
    return [
      'short labels' => [
        'Legend North South km',
        FALSE,
      ],
      'long narrative' => [
        str_repeat('This situation report summarizes humanitarian needs across affected districts. ', 4),
        TRUE,
      ],
      'empty text' => [
        '',
        FALSE,
      ],
    ];
  }

  /**
   * Test buildSampleFromSpans keeps long body lines and drops map labels.
   */
  public function testBuildSampleFromSpansSelectsBodyLines(): void {
    $pages = [
      [
        [
          'text' => 'A F G H A N I S T A N',
          'x' => 10.0,
          'y' => 20.0,
          'w' => 200.0,
          'h' => 14.0,
          'size' => 18.0,
        ],
        [
          'text' => 'Legend',
          'x' => 10.0,
          'y' => 40.0,
          'w' => 40.0,
          'h' => 10.0,
          'size' => 9.0,
        ],
        [
          'text' => 'Afghanistan continues to face worsening acute food insecurity and malnutrition across many provinces.',
          'x' => 40.0,
          'y' => 120.0,
          'w' => 400.0,
          'h' => 12.0,
          'size' => 11.0,
        ],
        [
          'text' => 'The current analysis reports the highest acute malnutrition levels ever recorded for this period.',
          'x' => 40.0,
          'y' => 136.0,
          'w' => 400.0,
          'h' => 12.0,
          'size' => 11.0,
        ],
      ],
    ];

    $sample = LanguageDetectionHelper::buildSampleFromSpans($pages, 40, 200);

    $this->assertStringContainsString('Afghanistan continues to face', $sample);
    $this->assertStringContainsString('highest acute malnutrition', $sample);
    $this->assertStringNotContainsString('Legend', $sample);
    $this->assertStringNotContainsString('A F G H A N I S T A N', $sample);
    $result = LanguageDetectionHelper::detect($sample);
    $this->assertSame('en', $result->language);
    $this->assertTrue($result->isReliable(0.6));
  }

  /**
   * Test buildSampleFromSpans returns empty when only short lines exist.
   */
  public function testBuildSampleFromSpansShortOnly(): void {
    $pages = [
      [
        ['text' => 'North', 'x' => 1.0, 'y' => 1.0, 'w' => 20.0, 'h' => 10.0, 'size' => 10.0],
        ['text' => 'South', 'x' => 1.0, 'y' => 20.0, 'w' => 20.0, 'h' => 10.0, 'size' => 10.0],
        ['text' => 'km', 'x' => 1.0, 'y' => 40.0, 'w' => 10.0, 'h' => 10.0, 'size' => 10.0],
      ],
    ];

    $sample = LanguageDetectionHelper::buildSampleFromSpans($pages, 40, 200);
    $this->assertSame('', $sample);
    $this->assertFalse(LanguageDetectionHelper::hasEnoughText($sample, 200));
  }

  /**
   * Test buildSampleFromText keeps long lines and concatenates blocks.
   */
  public function testBuildSampleFromTextLongLines(): void {
    $first = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $second = 'Local partners continue supporting vulnerable communities during the winter season while recovery plans address shelter and livelihood gaps.';
    $text = "{$first}\n\nA F G H A N I S T A N\n\n{$second}";

    $sample = LanguageDetectionHelper::buildSampleFromText($text, 40, 200);

    $this->assertStringContainsString('This situation report summarizes', $sample);
    $this->assertStringContainsString('Local partners continue supporting', $sample);
    $this->assertStringNotContainsString('A F G H A N I S T A N', $sample);
    $result = LanguageDetectionHelper::detect($sample);
    $this->assertSame('en', $result->language);
    $this->assertTrue($result->isReliable(0.6));
  }

  /**
   * Test buildSampleFromText returns cleaned text when no long lines qualify.
   */
  public function testBuildSampleFromTextSparseFallback(): void {
    $text = 'Legend North South km';
    $this->assertSame($text, LanguageDetectionHelper::buildSampleFromText($text, 40, 200));
  }

  /**
   * Test cleanText removes replacement characters and collapses whitespace.
   */
  public function testCleanText(): void {
    $cleaned = LanguageDetectionHelper::cleanText("Hello\x00\u{FFFD}  world\n\n\n\nnext");
    $this->assertSame("Hello world\n\nnext", $cleaned);
  }

  /**
   * Test detect() returns English for clear English prose.
   */
  public function testDetectEnglish(): void {
    $text = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead. Additional context describes recovery plans for shelter water sanitation and livelihoods.';
    $result = LanguageDetectionHelper::detect($text);
    $this->assertSame('en', $result->language);
    $this->assertTrue($result->isReliable(0.6));
  }

  /**
   * Test detect() keeps the raw language when isReliable fails a strict ratio.
   */
  public function testDetectReturnsRawLanguageWhenUnreliable(): void {
    // Medium ngram DB is more confident than small; use a stricter ratio than
    // ELD's historic 0.75 default so the gate is still observable.
    $text = 'Afghanistan continues to face worsening acute food insecurity and malnutrition. The current analysis reports the highest acute malnutrition levels ever recorded for the';
    $result = LanguageDetectionHelper::detect($text);

    $this->assertSame('en', $result->language);
    $this->assertFalse($result->isReliable(0.95));
    $this->assertTrue($result->isReliable(0.6));
  }

  /**
   * Test detect() on empty text yields und.
   */
  public function testDetectEmptyText(): void {
    $result = LanguageDetectionHelper::detect('');
    $this->assertSame('und', $result->language);
    $this->assertFalse($result->isReliable(0.6));
  }

  /**
   * Invalid Eld database size falls back to the default (medium).
   */
  public function testDetectInvalidDatabaseFallsBackToDefault(): void {
    $text = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $with_invalid = LanguageDetectionHelper::detect($text, 'not-a-size');
    $with_default = LanguageDetectionHelper::detect($text, LanguageDetectionHelper::DEFAULT_DATABASE);
    $this->assertSame($with_default->language, $with_invalid->language);
    $this->assertContains('medium', LanguageDetectionHelper::databaseSizes());
  }

}
