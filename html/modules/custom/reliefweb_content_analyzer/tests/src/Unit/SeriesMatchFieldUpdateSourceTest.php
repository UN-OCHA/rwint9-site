<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchFieldUpdateSource attention mapping.
 */
#[CoversClass(SeriesMatchFieldUpdateSource::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchFieldUpdateSourceTest extends UnitTestCase {

  /**
   * Field update sources map to the expected attention level.
   */
  #[DataProvider('attentionLevelProvider')]
  public function testAttentionLevel(
    SeriesMatchFieldUpdateSource $source,
    SeriesMatchAttentionLevel $expected,
  ): void {
    $this->assertSame($expected, $source->attentionLevel());
  }

  /**
   * Data provider for field update source attention levels.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource, 1: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel}>
   *   Source and expected attention level.
   */
  public static function attentionLevelProvider(): array {
    return [
      'all candidates' => [SeriesMatchFieldUpdateSource::AllCandidates, SeriesMatchAttentionLevel::Ok],
      'merged' => [SeriesMatchFieldUpdateSource::Merged, SeriesMatchAttentionLevel::Info],
      'most recent' => [SeriesMatchFieldUpdateSource::MostRecent, SeriesMatchAttentionLevel::Warning],
      'skipped' => [SeriesMatchFieldUpdateSource::Skipped, SeriesMatchAttentionLevel::Error],
    ];
  }

}
