<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchEvidence display helpers.
 */
#[CoversClass(SeriesMatchEvidence::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchEvidenceTest extends UnitTestCase {

  /**
   * Returns NULL when cluster size or lookback months is zero.
   */
  public function testSimilarReportsSummaryNullWhenIncomplete(): void {
    $evidence = new SeriesMatchEvidence(bestClusterSize: 0, lookbackMonths: 10);
    $this->assertNull($evidence->similarReportsSummary());

    $evidence = new SeriesMatchEvidence(bestClusterSize: 30, lookbackMonths: 0);
    $this->assertNull($evidence->similarReportsSummary());
  }

  /**
   * Returns count and months when both values are available.
   */
  public function testSimilarReportsSummaryWhenAvailable(): void {
    $evidence = new SeriesMatchEvidence(bestClusterSize: 30, lookbackMonths: 10);

    $this->assertSame([
      'count' => 30,
      'months' => 10,
    ], $evidence->similarReportsSummary());
  }

}
