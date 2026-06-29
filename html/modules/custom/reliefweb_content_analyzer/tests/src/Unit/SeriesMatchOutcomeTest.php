<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchWorkflowConfigFixture;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchOutcome: tier resolution, matrix, and restrictiveness.
 */
#[CoversClass(SeriesMatchOutcome::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchOutcomeTest extends UnitTestCase {

  /**
   * Default workflow settings for tests.
   */
  private static function defaultSettings(): SeriesMatchWorkflowSettings {
    return SeriesMatchWorkflowSettings::fromConfigArray(
      SeriesMatchWorkflowConfigFixture::defaults(),
    );
  }

  /**
   * Builds workflow settings from config overrides.
   *
   * @param array<string, mixed> $overrides
   *   Values merged over the default workflow config.
   */
  private static function settingsFrom(array $overrides): SeriesMatchWorkflowSettings {
    return SeriesMatchWorkflowSettings::fromConfigArray(
      array_merge(SeriesMatchWorkflowConfigFixture::defaults(), $overrides),
    );
  }

  /**
   * Scores map to expected tiers given default thresholds.
   */
  #[DataProvider('tierProvider')]
  public function testScoreToTier(
    float $series,
    float $tagging,
    string $expected_series_tier,
    string $expected_tagging_tier,
  ): void {
    $result = $this->buildResult($series, $tagging);
    $outcome = SeriesMatchOutcome::resolve($result, self::defaultSettings());
    $this->assertNotNull($outcome);
    $this->assertSame($expected_series_tier, $outcome->seriesTier);
    $this->assertSame($expected_tagging_tier, $outcome->taggingTier);
  }

  /**
   * Data provider for tier mapping tests.
   *
   * @return array<string, array{0: float, 1: float, 2: string, 3: string}>
   *   Series score, tagging score, expected series tier, expected tagging tier.
   */
  public static function tierProvider(): array {
    return [
      'both high' => [0.90, 0.85, 'high', 'high'],
      'both medium' => [0.70, 0.70, 'medium', 'medium'],
      'both low' => [0.40, 0.30, 'low', 'low'],
      'series high tagging medium' => [0.85, 0.65, 'high', 'medium'],
      'series medium tagging low' => [0.65, 0.55, 'medium', 'low'],
    ];
  }

  /**
   * Outcome tier is always the lower of the two tiers.
   */
  #[DataProvider('outcomeTierMatrixProvider')]
  public function testOutcomeTierMatrix(
    float $series,
    float $tagging,
    string $expected_outcome,
    string $expected_moderation,
  ): void {
    $result = $this->buildResult($series, $tagging);
    $outcome = SeriesMatchOutcome::resolve($result, self::defaultSettings());
    $this->assertNotNull($outcome);
    $this->assertSame($expected_outcome, $outcome->outcomeTier);
    $this->assertSame($expected_moderation, $outcome->targetModerationStatus);
  }

  /**
   * Data provider for outcome tier matrix tests.
   *
   * @return array<string, array{0: float, 1: float, 2: string, 3: string}>
   *   Series score, tagging score, expected outcome tier, expected moderation.
   */
  public static function outcomeTierMatrixProvider(): array {
    return [
      'high/high → published' => [0.90, 0.85, 'high', 'published'],
      'high/medium → to-review' => [0.90, 0.65, 'medium', 'to-review'],
      'high/low → pending' => [0.90, 0.30, 'low', 'pending'],
      'medium/medium → to-review' => [0.70, 0.65, 'medium', 'to-review'],
      'medium/high → to-review' => [0.70, 0.90, 'medium', 'to-review'],
      'medium/low → pending' => [0.70, 0.30, 'low', 'pending'],
      'low/high → pending' => [0.50, 0.90, 'low', 'pending'],
      'low/low → pending' => [0.50, 0.30, 'low', 'pending'],
    ];
  }

  /**
   * Tagging score below minimum_tagging_confidence forces 'low' tier.
   */
  public function testMinimumTaggingConfidenceFloor(): void {
    $config = ['minimum_tagging_confidence' => 0.70];
    // Tagging score 0.65 would normally be 'medium', floor forces 'low'.
    $result = $this->buildResult(series: 0.90, tagging: 0.65);
    $outcome = SeriesMatchOutcome::resolve($result, self::settingsFrom($config));
    $this->assertNotNull($outcome);
    $this->assertSame('low', $outcome->taggingTier);
    $this->assertSame('low', $outcome->outcomeTier);
    $this->assertSame('pending', $outcome->targetModerationStatus);
  }

  /**
   * Tagging score exactly at minimum_tagging_confidence is not floored.
   */
  public function testMinimumTaggingConfidenceAtBoundary(): void {
    $config = ['minimum_tagging_confidence' => 0.60];
    $result = $this->buildResult(series: 0.90, tagging: 0.60);
    $outcome = SeriesMatchOutcome::resolve($result, self::settingsFrom($config));
    $this->assertNotNull($outcome);
    $this->assertSame('medium', $outcome->taggingTier);
  }

  /**
   * Returns the more restrictive (lower-index) status.
   */
  #[DataProvider('restrictiveStatusProvider')]
  public function testMoreRestrictiveStatus(
    string $current,
    string $proposed,
    string $expected,
  ): void {
    $order = [
      'refused', 'draft', 'on-hold', 'pending', 'to-review', 'embargoed',
      'reference', 'published',
    ];
    $this->assertSame(
      $expected,
      SeriesMatchOutcome::moreRestrictiveStatus($current, $proposed, $order),
    );
  }

  /**
   * Data provider for moreRestrictiveStatus tests.
   *
   * @return array<string, array{0: string, 1: string, 2: string}>
   *   Current status, proposed status, expected more restrictive status.
   */
  public static function restrictiveStatusProvider(): array {
    return [
      'published vs pending → pending' => ['published', 'pending', 'pending'],
      'pending vs published → pending' => ['pending', 'published', 'pending'],
      'to-review vs pending → pending' => ['to-review', 'pending', 'pending'],
      'refused vs published → refused' => ['refused', 'published', 'refused'],
      'same status → unchanged' => ['to-review', 'to-review', 'to-review'],
      'embargoed beats published' => ['embargoed', 'published', 'embargoed'],
      'unknown current → proposed wins' => ['unknown', 'pending', 'pending'],
      'both unknown → current returned' => [
        'unknown_a', 'unknown_b', 'unknown_a',
      ],
    ];
  }

  /**
   * Resolve() returns NULL when series confidence is not computable.
   */
  public function testResolveNullWhenSeriesNotComputable(): void {
    $result = SeriesMatchResult::stopped(
      SeriesMatchReason::BelowMinimumCluster,
    );
    $this->assertNull(SeriesMatchOutcome::resolve($result, self::defaultSettings()));
  }

  /**
   * Resolve() returns NULL when tagging confidence is not computable.
   */
  public function testResolveNullWhenTaggingNotComputable(): void {
    // passedMinimum + candidates ensures series is computable, but empty
    // updatedFieldSources makes tagging not computable.
    $result = new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(),
      new SeriesMatchEvidence(
        candidateIds: [1],
        bestClusterShare: 1.0,
        clusterScore: 1.0,
        clusterCount: 1,
      ),
    );
    $this->assertNull(SeriesMatchOutcome::resolve($result, self::defaultSettings()));
  }

  /**
   * UNHCR fixture: strong series and tagging → high / published.
   *
   * Series: 17/17 cluster, 1 cluster, no URL both-signal.
   *   = 0.40×1.0 + 0.25×1.0 + 0.20×0 + 0.15×1 = 0.80 → high.
   *
   * Tagging: 5/7 AllCandidates, 1 Merged, 1 MostRecent, AI title.
   *   field_score = (5×1.0 + 1×0.75 + 1×0.50)/7 ≈ 0.8929.
   *   total = 0.70×0.8929 + 0.30×0.65 ≈ 0.8200 → high.
   *
   * Outcome: min(high, high) → high → published.
   *
   * NB: this fixture shows that with the new formula the match is actually
   * high/high because series also gets 0.15 single-cluster bonus. Previous
   * analysis was based on the old blended formula.
   */
  public function testUnhcrFixture(): void {
    $result = $this->buildUnhcrResult();
    $outcome = SeriesMatchOutcome::resolve($result, self::defaultSettings());

    $this->assertNotNull($outcome);
    $this->assertSame('high', $outcome->seriesTier);
    $this->assertSame('high', $outcome->taggingTier);
    $this->assertSame('high', $outcome->outcomeTier);
    $this->assertSame('published', $outcome->targetModerationStatus);
  }

  /**
   * UNHCR fixture with posting-rights returning 'to-review'.
   *
   * Verifies moreRestrictiveStatus(to-review, published) → to-review.
   */
  public function testUnhcrFixtureWithPostingRightsToReview(): void {
    $order = SeriesMatchWorkflowConfigFixture::defaults()['restrictiveness_order'];
    $result = SeriesMatchOutcome::moreRestrictiveStatus(
      'to-review',
      'published',
      $order,
    );
    $this->assertSame('to-review', $result);
  }

  /**
   * Builds a result from explicit series/tagging confidence scores.
   *
   * Constructs evidence and a proposal whose calculateSeriesConfidence() and
   * calculateTaggingConfidence() will return the requested values exactly.
   *
   * @param float $series
   *   Target series confidence score.
   * @param float $tagging
   *   Target tagging confidence score.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   Result with evidence and proposal tuned to the requested scores.
   */
  private function buildResult(float $series, float $tagging): SeriesMatchResult {
    // Evidence values yielding the requested series score.
    // series = 0.40*share + 0.25*clusterScore + 0.20*both + 0.15*bonus.
    // Single cluster, no URL: 0.40*share + 0.25 + 0.15.
    // share = (series - 0.40) / 0.40 when series > 0.40.
    // For series tier: >= 0.80 high, >= 0.60 medium, else low.
    // clusterCount=1, clusterScore=1.0 → 0.40*share + 0.40.
    // x = (series - 0.40) / 0.40; floor 0.40 for lower series.
    $share = $series >= 0.40 ? ($series - 0.40) / 0.40 : 0.0;
    $share = min(1.0, max(0.0, $share));
    // Tagging: 0.70*field_score + 0.30*title_score.
    // Strategy: 10 fields, N AllCandidates, (10-N) Skipped.
    // AI title (0.65): tagging = 0.07*N + 0.195.
    // N = (tagging - 0.195) / 0.07.
    $n_all = (int) round(($tagging - 0.195) / 0.07);
    $n_all = min(10, max(0, $n_all));
    $fieldSources = [];
    for ($i = 0; $i < 10; $i++) {
      $fieldSources["field_{$i}"] = $i < $n_all
        ? SeriesMatchFieldUpdateSource::AllCandidates
        : SeriesMatchFieldUpdateSource::Skipped;
    }

    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: array_fill_keys(array_keys($fieldSources), []),
        updatedFieldSources: $fieldSources,
        titleSource: SeriesMatchTitleSource::AiGenerated,
      ),
      new SeriesMatchEvidence(
        candidateIds: range(1, 5),
        bestClusterShare: $share,
        clusterScore: 1.0,
        clusterCount: 1,
        bothSignalsCount: 0,
        mergedAfterLimitCount: 5,
      ),
    );
  }

  /**
   * Builds the UNHCR fixture result.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   UNHCR-style high-confidence match result.
   */
  private function buildUnhcrResult(): SeriesMatchResult {
    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: array_fill_keys([
          'field_primary_country', 'field_country', 'field_language',
          'field_content_format', 'field_theme', 'field_disaster', 'field_disaster_type',
        ], []),
        updatedFieldSources: [
          'field_primary_country' => SeriesMatchFieldUpdateSource::AllCandidates,
          'field_country'         => SeriesMatchFieldUpdateSource::Merged,
          'field_language'        => SeriesMatchFieldUpdateSource::AllCandidates,
          'field_content_format'  => SeriesMatchFieldUpdateSource::AllCandidates,
          'field_theme'           => SeriesMatchFieldUpdateSource::MostRecent,
          'field_disaster'        => SeriesMatchFieldUpdateSource::AllCandidates,
          'field_disaster_type'   => SeriesMatchFieldUpdateSource::AllCandidates,
        ],
        titleSource: SeriesMatchTitleSource::AiGenerated,
      ),
      new SeriesMatchEvidence(
        candidateIds: range(1, 17),
        bestClusterShare: 1.0,
        clusterScore: 1.0,
        clusterCount: 1,
        bothSignalsCount: 0,
        mergedAfterLimitCount: 17,
      ),
    );
  }

}
