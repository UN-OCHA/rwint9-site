<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcomePolicyReasonFormatter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchOutcomePolicyReasonFormatter labels.
 */
#[CoversClass(SeriesMatchOutcomePolicyReasonFormatter::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchOutcomePolicyReasonFormatterTest extends UnitTestCase {

  /**
   * Field provenance messages use friendly labels without action codes.
   */
  public function testForFieldMessages(): void {
    $reason = SeriesMatchOutcomePolicyReasonFormatter::forField(
      'field_country',
      SeriesMatchFieldUpdateSource::Merged,
      SeriesMatchOutcomePolicyAction::MaxMedium,
    );

    $this->assertSame('field:field_country:merged:max_medium', $reason->code);
    $this->assertSame('Country merged from series', $reason->message);
  }

  /**
   * Custom field labels override the static fallback map.
   */
  public function testForFieldCustomLabel(): void {
    $reason = SeriesMatchOutcomePolicyReasonFormatter::forField(
      'field_theme',
      SeriesMatchFieldUpdateSource::MostRecent,
      SeriesMatchOutcomePolicyAction::MaxMedium,
      'Themes',
    );

    $this->assertSame('Themes from most recent report', $reason->message);
  }

  /**
   * Global rules map to short editor-facing phrases.
   */
  public function testForGlobalMessages(): void {
    $reason = SeriesMatchOutcomePolicyReasonFormatter::forGlobal(
      'empty_body_when_series_has_body',
      SeriesMatchOutcomePolicyAction::MaxMedium,
    );

    $this->assertSame(
      'global:empty_body_when_series_has_body:max_medium',
      $reason->code,
    );
    $this->assertSame('no body while series usually has body', $reason->message);
  }

}
