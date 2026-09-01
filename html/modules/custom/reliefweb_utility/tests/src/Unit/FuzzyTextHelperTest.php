<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\FuzzyTextHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests FuzzyTextHelper RapidFuzz-compatible scores.
 */
#[CoversClass(FuzzyTextHelper::class)]
#[Group('reliefweb_utility')]
class FuzzyTextHelperTest extends UnitTestCase {

  /**
   * Identical strings score 100 across ratio helpers.
   */
  public function testIdenticalStringsScoreFull(): void {
    $text = "Mise à jour des arrivées du Soudan";
    $this->assertSame(100.0, FuzzyTextHelper::ratio($text, $text));
    $this->assertSame(100.0, FuzzyTextHelper::partialRatio($text, $text));
    $this->assertSame(100.0, FuzzyTextHelper::tokenSetRatio($text, $text));
    $this->assertSame(100.0, FuzzyTextHelper::fuzzyBestScore($text, $text));
  }

  /**
   * Ratio matches RapidFuzz Indel-normalized fixtures.
   */
  #[DataProvider('ratioProvider')]
  public function testRatio(string $a, string $b, float $expected): void {
    $this->assertEqualsWithDelta($expected, FuzzyTextHelper::ratio($a, $b), 0.01);
  }

  /**
   * Ratio matches RapidFuzz Indel-normalized fixtures.
   *
   * @return array<string, array{string, string, float}>
   *   Cases.
   */
  public static function ratioProvider(): array {
    return [
      'substring window' => [
        'emergency flash update',
        'ate middle east situat',
        45.45454545454546,
      ],
      'unrelated short' => [
        'abc',
        'xyz',
        0.0,
      ],
    ];
  }

  /**
   * Partial ratio finds the best aligned substring.
   */
  #[DataProvider('partialRatioProvider')]
  public function testPartialRatio(string $a, string $b, float $expected): void {
    $this->assertEqualsWithDelta($expected, FuzzyTextHelper::partialRatio($a, $b), 0.01);
  }

  /**
   * Partial ratio finds the best aligned substring.
   *
   * @return array<string, array{string, string, float}>
   *   Cases.
   */
  public static function partialRatioProvider(): array {
    return [
      'embedded short token' => [
        'tchad',
        "situation d'urgence au tchad mise",
        100.0,
      ],
      'full component in longer title' => [
        'emergency flash update',
        'unhcr middle east situation emergency flash update',
        100.0,
      ],
      'divergent titles' => [
        'emergency flash update',
        'update middle east situation',
        45.45454545454546,
      ],
    ];
  }

  /**
   * Token-set ratio fixtures.
   */
  #[DataProvider('tokenSetRatioProvider')]
  public function testTokenSetRatio(string $a, string $b, float $expected): void {
    $this->assertEqualsWithDelta($expected, FuzzyTextHelper::tokenSetRatio($a, $b), 0.01);
  }

  /**
   * Token-set ratio fixtures.
   *
   * @return array<string, array{string, string, float}>
   *   Cases.
   */
  public static function tokenSetRatioProvider(): array {
    return [
      'shared tokens only' => [
        'emergency flash update',
        'update middle east situation',
        48.0,
      ],
      'subset token' => [
        'tchad',
        "situation d'urgence au tchad mise",
        100.0,
      ],
    ];
  }

  /**
   * FuzzyBestScore prefers token_set when partial is inflated by short tails.
   */
  public function testFuzzyBestScorePrefersTokenSetWhenPartialLeadsFar(): void {
    $component = 'abcde';
    $line = 'abXde and lots of other tokens here now';
    $partial = FuzzyTextHelper::partialRatio($component, $line);
    $token_set = FuzzyTextHelper::tokenSetRatio($component, $line);
    $best = FuzzyTextHelper::fuzzyBestScore($component, $line);

    $this->assertGreaterThan($token_set, $partial);
    $this->assertGreaterThan(10.0, $partial - $token_set);
    $this->assertEqualsWithDelta($token_set, $best, 0.01);
  }

  /**
   * Grapheme levenshtein treats café combining forms as equal when possible.
   */
  public function testLevenshteinDistanceUsesGraphemes(): void {
    $this->assertSame(0, FuzzyTextHelper::levenshteinDistance('cafe', 'cafe'));
    $this->assertSame(1, FuzzyTextHelper::levenshteinDistance('cafe', 'café'));
  }

}
