<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcomePolicyEvaluator;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcomePolicyReasonFormatter;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchWorkflowConfigFixture;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchOutcomePolicyEvaluator.
 */
#[CoversClass(SeriesMatchOutcomePolicyEvaluator::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchOutcomePolicyEvaluatorTest extends UnitTestCase {

  /**
   * Default workflow settings.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings
   *   Default workflow settings.
   */
  private static function settings(): SeriesMatchWorkflowSettings {
    return SeriesMatchWorkflowSettings::fromConfigArray(
      SeriesMatchWorkflowConfigFixture::defaults(),
    );
  }

  /**
   * MostRecent on primary country yields max_low with friendly message.
   */
  public function testFieldMostRecentPrimaryCountry(): void {
    $result = $this->buildResult([
      'field_primary_country' => SeriesMatchFieldUpdateSource::MostRecent,
      'field_theme' => SeriesMatchFieldUpdateSource::AllCandidates,
    ]);
    $policy = (new SeriesMatchOutcomePolicyEvaluator())->evaluate(
      $result,
      self::settings(),
      SeriesMatchOutcomeTier::High,
    );

    $this->assertSame(SeriesMatchOutcomePolicyAction::MaxLow, $policy->action);
    $this->assertContains(
      'field:field_primary_country:most_recent:max_low',
      SeriesMatchOutcomePolicyReasonFormatter::codes($policy->reasons),
    );
    $this->assertContains(
      'Primary country from most recent report',
      SeriesMatchOutcomePolicyReasonFormatter::messages($policy->reasons),
    );
  }

  /**
   * Strictest action wins across field policies.
   */
  public function testStrictestFieldActionWins(): void {
    $result = $this->buildResult([
      'field_theme' => SeriesMatchFieldUpdateSource::MostRecent,
      'field_primary_country' => SeriesMatchFieldUpdateSource::MostRecent,
    ]);
    $policy = (new SeriesMatchOutcomePolicyEvaluator())->evaluate(
      $result,
      self::settings(),
      SeriesMatchOutcomeTier::High,
    );

    $this->assertSame(SeriesMatchOutcomePolicyAction::MaxLow, $policy->action);
  }

  /**
   * Empty body vs series-with-body yields max_medium.
   */
  public function testEmptyBodyWhenSeriesHasBody(): void {
    $result = $this->buildResult([
      'field_theme' => SeriesMatchFieldUpdateSource::AllCandidates,
    ]);
    $policy = (new SeriesMatchOutcomePolicyEvaluator())->evaluate(
      $result,
      self::settings(),
      SeriesMatchOutcomeTier::High,
      new SeriesMatchOutcomePolicyContext(
        entityHasBody: FALSE,
        seriesBodyRatio: 0.8,
      ),
    );

    $this->assertSame(SeriesMatchOutcomePolicyAction::MaxMedium, $policy->action);
    $this->assertContains(
      'global:empty_body_when_series_has_body:max_medium',
      SeriesMatchOutcomePolicyReasonFormatter::codes($policy->reasons),
    );
    $this->assertContains(
      'no body while series usually has body',
      SeriesMatchOutcomePolicyReasonFormatter::messages($policy->reasons),
    );
  }

  /**
   * Title AI skipped yields max_medium.
   */
  public function testTitleAiFailedOrSkipped(): void {
    $result = $this->buildResult(
      ['field_theme' => SeriesMatchFieldUpdateSource::AllCandidates],
      SeriesMatchTitleSource::SkippedNoAttachmentText,
    );
    $policy = (new SeriesMatchOutcomePolicyEvaluator())->evaluate(
      $result,
      self::settings(),
      SeriesMatchOutcomeTier::High,
    );

    $this->assertSame(SeriesMatchOutcomePolicyAction::MaxMedium, $policy->action);
    $this->assertContains(
      'title could not be generated',
      SeriesMatchOutcomePolicyReasonFormatter::messages($policy->reasons),
    );
  }

  /**
   * Low series confidence with mismatch yields skip_match.
   */
  public function testLowSeriesConfidenceWithMismatch(): void {
    $result = new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: ['field_theme' => []],
        updatedFieldSources: [
          'field_theme' => SeriesMatchFieldUpdateSource::AllCandidates,
        ],
        titleSource: SeriesMatchTitleSource::AiGenerated,
      ),
      new SeriesMatchEvidence(
        candidateIds: [1, 2, 3],
        bestClusterShare: 0.4,
        clusterCount: 3,
        seriesBodyRatio: 0.0,
      ),
    );
    $policy = (new SeriesMatchOutcomePolicyEvaluator())->evaluate(
      $result,
      self::settings(),
      SeriesMatchOutcomeTier::Medium,
    );

    $this->assertSame(SeriesMatchOutcomePolicyAction::SkipMatch, $policy->action);
    $this->assertContains(
      'weak series match with conflicting candidates',
      SeriesMatchOutcomePolicyReasonFormatter::messages($policy->reasons),
    );
    $this->assertContainsOnlyInstancesOf(
      SeriesMatchOutcomePolicyReason::class,
      $policy->reasons,
    );
  }

  /**
   * High series tier does not trigger mismatch skip.
   */
  public function testMismatchRuleSkippedForHighSeriesTier(): void {
    $result = new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: ['field_theme' => []],
        updatedFieldSources: [
          'field_theme' => SeriesMatchFieldUpdateSource::AllCandidates,
        ],
        titleSource: SeriesMatchTitleSource::AiGenerated,
      ),
      new SeriesMatchEvidence(
        candidateIds: [1, 2, 3],
        bestClusterShare: 0.4,
        clusterCount: 3,
      ),
    );
    $policy = (new SeriesMatchOutcomePolicyEvaluator())->evaluate(
      $result,
      self::settings(),
      SeriesMatchOutcomeTier::High,
    );

    $this->assertSame(SeriesMatchOutcomePolicyAction::None, $policy->action);
  }

  /**
   * Builds a minimal scorable result with the given field sources.
   *
   * @param array<string, \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource> $sources
   *   Field provenance map.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource $title_source
   *   Title provenance.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   Minimal scorable match result.
   */
  private function buildResult(
    array $sources,
    SeriesMatchTitleSource $title_source = SeriesMatchTitleSource::AiGenerated,
  ): SeriesMatchResult {
    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: array_fill_keys(array_keys($sources), []),
        updatedFieldSources: $sources,
        titleSource: $title_source,
      ),
      new SeriesMatchEvidence(
        candidateIds: [1, 2, 3],
        bestClusterShare: 1.0,
        clusterScore: 1.0,
        clusterCount: 1,
        seriesBodyRatio: 0.0,
      ),
    );
  }

}
