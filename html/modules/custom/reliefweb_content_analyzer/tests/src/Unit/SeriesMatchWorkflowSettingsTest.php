<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchWorkflowConfigFixture;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchWorkflowSettings DTO factory and typing.
 */
#[CoversClass(SeriesMatchWorkflowSettings::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchWorkflowSettingsTest extends UnitTestCase {

  /**
   * FromConfigArray maps install-default values to typed properties.
   */
  public function testFromConfigArray(): void {
    $settings = SeriesMatchWorkflowSettings::fromConfigArray(
      SeriesMatchWorkflowConfigFixture::defaults(),
    );

    $this->assertTrue($settings->automationEnabledFormCreated);
    $this->assertSame(0.65, $settings->minimumSeriesConfidence);
    $this->assertSame(['high' => 0.80, 'medium' => 0.60], $settings->seriesConfidenceTiers);
    $this->assertSame('published', $settings->moderationByOutcomeTier['high']);
    $this->assertSame(['refused'], $settings->skipSeriesMatchModerationStatuses);
  }

  /**
   * Missing required key throws InvalidArgumentException.
   */
  public function testFromConfigArrayMissingKey(): void {
    $config = SeriesMatchWorkflowConfigFixture::defaults();
    unset($config['minimum_series_confidence']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('minimum_series_confidence');
    SeriesMatchWorkflowSettings::fromConfigArray($config);
  }

  /**
   * Invalid series_confidence_tiers throws InvalidArgumentException.
   */
  public function testFromConfigArrayInvalidTierMap(): void {
    $config = SeriesMatchWorkflowConfigFixture::defaults();
    $config['series_confidence_tiers'] = 'invalid';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('series_confidence_tiers');
    SeriesMatchWorkflowSettings::fromConfigArray($config);
  }

}
