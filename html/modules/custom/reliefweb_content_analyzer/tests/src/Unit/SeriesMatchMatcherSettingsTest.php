<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchMatcherSettings;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchMatcherConfigFixture;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchMatcherSettings DTO factory and typing.
 */
#[CoversClass(SeriesMatchMatcherSettings::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchMatcherSettingsTest extends UnitTestCase {

  /**
   * FromConfigArray maps install-default values to typed properties.
   */
  public function testFromConfigArray(): void {
    $settings = SeriesMatchMatcherSettings::fromConfigArray(
      SeriesMatchMatcherConfigFixture::defaults(),
    );

    $this->assertSame(30, $settings->seriesCandidateLimit);
    $this->assertSame(18, $settings->seriesCandidateDateRangeMonths);
    $this->assertSame([10, 8, 6, 4], $settings->patternTokenCounts);
    $this->assertSame(
      'field_original_publication_date',
      $settings->recencyFieldName,
    );
    $this->assertContains(
      'field_primary_country',
      $settings->reportEntityFieldNamesToCopy,
    );
  }

  /**
   * Missing required key throws InvalidArgumentException.
   */
  public function testFromConfigArrayMissingKey(): void {
    $config = SeriesMatchMatcherConfigFixture::defaults();
    unset($config['series_candidate_limit']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('series_candidate_limit');
    SeriesMatchMatcherSettings::fromConfigArray($config);
  }

  /**
   * Non-array pattern_token_counts throws InvalidArgumentException.
   */
  public function testFromConfigArrayInvalidPatternTokenCounts(): void {
    $config = SeriesMatchMatcherConfigFixture::defaults();
    $config['pattern_token_counts'] = '10, 8';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('pattern_token_counts');
    SeriesMatchMatcherSettings::fromConfigArray($config);
  }

}
