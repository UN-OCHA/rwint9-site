<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchConfidenceScoringSettings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchConfidenceScoringSettings DTO factory and typing.
 */
#[CoversClass(SeriesMatchConfidenceScoringSettings::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchConfidenceScoringSettingsTest extends UnitTestCase {

  /**
   * FromConfigArray maps install-default values to typed properties.
   */
  public function testFromConfigArray(): void {
    $settings = SeriesMatchConfidenceScoringSettings::fromConfigArray(
      SeriesMatchConfidenceScoringSettings::defaultConfig(),
    );

    $this->assertSame(0.40, $settings->clusterShareWeight);
    $this->assertSame(0.25, $settings->clusterScoreWeight);
    $this->assertSame(0.20, $settings->dualSignalRatioWeight);
    $this->assertSame(0.15, $settings->clusterShareDominanceWeight);
    $this->assertSame(0.70, $settings->fieldBlendWeight);
    $this->assertSame(0.30, $settings->titleBlendWeight);
    $this->assertSame(1.0, $settings->fieldProvenanceWeights['all_candidates']);
    $this->assertSame(0.65, $settings->titleSourceScores['ai_generated']);
  }

  /**
   * Missing required series key throws InvalidArgumentException.
   */
  public function testFromConfigArrayMissingSeriesKey(): void {
    $config = SeriesMatchConfidenceScoringSettings::defaultConfig();
    unset($config['series']['cluster_share_weight']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('cluster_share_weight');
    SeriesMatchConfidenceScoringSettings::fromConfigArray($config);
  }

  /**
   * Invalid tagging section throws InvalidArgumentException.
   */
  public function testFromConfigArrayInvalidTaggingSection(): void {
    $config = SeriesMatchConfidenceScoringSettings::defaultConfig();
    $config['tagging'] = 'invalid';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('confidence_scoring.tagging');
    SeriesMatchConfidenceScoringSettings::fromConfigArray($config);
  }

}
