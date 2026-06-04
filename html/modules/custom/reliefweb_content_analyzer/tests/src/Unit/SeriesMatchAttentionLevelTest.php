<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchAttentionLevel indicators and labels.
 */
#[CoversClass(SeriesMatchAttentionLevel::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchAttentionLevelTest extends UnitTestCase {

  /**
   * Each attention level exposes a distinct emoji indicator.
   */
  #[DataProvider('indicatorProvider')]
  public function testIndicator(SeriesMatchAttentionLevel $level, string $expected): void {
    $this->assertSame($expected, $level->indicator());
  }

  /**
   * Data provider for attention level indicators.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel, 1: string}>
   *   Level and expected emoji.
   */
  public static function indicatorProvider(): array {
    return [
      'ok' => [SeriesMatchAttentionLevel::Ok, '✅'],
      'info' => [SeriesMatchAttentionLevel::Info, 'ℹ️'],
      'warning' => [SeriesMatchAttentionLevel::Warning, '⚠️'],
      'error' => [SeriesMatchAttentionLevel::Error, '❌'],
    ];
  }

  /**
   * Each attention level exposes a non-empty label.
   */
  #[DataProvider('labelProvider')]
  public function testLabel(SeriesMatchAttentionLevel $level, string $expected): void {
    $this->assertSame($expected, $level->label());
  }

  /**
   * Data provider for attention level labels.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel, 1: string}>
   *   Level and expected label.
   */
  public static function labelProvider(): array {
    return [
      'ok' => [SeriesMatchAttentionLevel::Ok, 'High confidence'],
      'info' => [SeriesMatchAttentionLevel::Info, 'Review suggested'],
      'warning' => [SeriesMatchAttentionLevel::Warning, 'Weaker source'],
      'error' => [SeriesMatchAttentionLevel::Error, 'Not applied / failed'],
    ];
  }

}
