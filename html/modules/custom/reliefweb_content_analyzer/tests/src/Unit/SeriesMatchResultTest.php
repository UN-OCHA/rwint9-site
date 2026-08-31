<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchConfidenceScoringSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchResult scoring methods.
 */
#[CoversClass(SeriesMatchResult::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchResultTest extends UnitTestCase {

  /**
   * Default confidence scoring settings for tests.
   */
  private function defaultScoring(): SeriesMatchConfidenceScoringSettings {
    return SeriesMatchConfidenceScoringSettings::fromConfigArray(
      SeriesMatchConfidenceScoringSettings::defaultConfig(),
    );
  }

  /**
   * Returns NULL when passedMinimum is FALSE.
   */
  public function testSeriesConfidenceNullWhenNotPassedMinimum(): void {
    $result = SeriesMatchResult::stopped(
      SeriesMatchReason::BelowMinimumCluster,
    );
    $this->assertNull($result->calculateSeriesConfidence($this->defaultScoring()));
  }

  /**
   * Returns NULL when candidate list is empty despite passedMinimum flag.
   */
  public function testSeriesConfidenceNullWhenNoCandidates(): void {
    $result = new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(),
      new SeriesMatchEvidence(
        candidateIds: [],
        bestClusterShare: 1.0,
        clusterScore: 1.0,
        clusterCount: 1,
      ),
    );
    $this->assertNull($result->calculateSeriesConfidence($this->defaultScoring()));
  }

  /**
   * Perfect cluster share, no dual signal.
   *
   * Expected: 0.40×1 + 0.25×1 + 0 + 0.15×1 = 0.80.
   */
  public function testSeriesConfidencePerfectClusterNoUrl(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 1.0,
      clusterScore: 1.0,
      clusterCount: 1,
      bothSignalsCount: 0,
      mergedAfterLimitCount: 17,
    );
    $scoring = $this->defaultScoring();
    $this->assertEqualsWithDelta(0.80, $result->calculateSeriesConfidence($scoring), 0.0001);
  }

  /**
   * Perfect cluster share, full dual signal.
   *
   * Expected: 0.40×1 + 0.25×1 + 0.20×1 + 0.15×1 = 1.00.
   */
  public function testSeriesConfidencePerfect(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 1.0,
      clusterScore: 1.0,
      clusterCount: 1,
      bothSignalsCount: 17,
      mergedAfterLimitCount: 17,
    );
    $scoring = $this->defaultScoring();
    $this->assertEqualsWithDelta(1.0, $result->calculateSeriesConfidence($scoring), 0.0001);
  }

  /**
   * Dominant cluster with a singleton outlier still clears the apply minimum.
   *
   * Chad-like 29+1 split: 0.55×0.967 + 0.25×0.985 = 0.77785 → 0.7779.
   */
  public function testSeriesConfidenceDominantSplitClearsMinimum(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 0.967,
      clusterScore: 0.985,
      clusterCount: 2,
      bothSignalsCount: 0,
      mergedAfterLimitCount: 30,
    );
    $scoring = $this->defaultScoring();
    $expected = round(0.55 * 0.967 + 0.25 * 0.985, 4);
    $this->assertEqualsWithDelta($expected, $result->calculateSeriesConfidence($scoring), 0.0001);
    $this->assertGreaterThanOrEqual(0.65, $result->calculateSeriesConfidence($scoring));
  }

  /**
   * Competing weak-blob share stays well below the apply minimum.
   *
   * Strong core vs weak blob: 0.55×0.5 + 0.25×1.0 = 0.525.
   */
  public function testSeriesConfidenceCompetingWeakBlobStaysBelowMinimum(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 0.5,
      clusterScore: 1.0,
      clusterCount: 2,
      bothSignalsCount: 0,
      mergedAfterLimitCount: 24,
    );
    $scoring = $this->defaultScoring();
    $this->assertEqualsWithDelta(0.525, $result->calculateSeriesConfidence($scoring), 0.0001);
    $this->assertLessThan(0.65, $result->calculateSeriesConfidence($scoring));
  }

  /**
   * Returns NULL when no field sources exist (empty proposal).
   */
  public function testTaggingConfidenceNullWhenNoSources(): void {
    $result = new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(),
      new SeriesMatchEvidence(candidateIds: [1]),
    );
    $this->assertNull($result->calculateTaggingConfidence($this->defaultScoring()));
  }

  /**
   * All-candidates fields and kept-original title → maximum score (1.0).
   */
  public function testTaggingConfidenceMaxAllCandidatesKeptTitle(): void {
    $result = $this->buildResultWithTagging(
      fieldSources: array_fill_keys(
        ['field_a', 'field_b', 'field_c'],
        SeriesMatchFieldUpdateSource::AllCandidates,
      ),
      titleSource: SeriesMatchTitleSource::KeptOriginalPatternMatch,
    );
    $scoring = $this->defaultScoring();
    $this->assertEqualsWithDelta(1.0, $result->calculateTaggingConfidence($scoring), 0.0001);
  }

  /**
   * UNHCR fixture: 5/7 AllCandidates, 1 Merged, 1 MostRecent, AI title.
   *
   * Field score: (5×1.0 + 1×0.75 + 1×0.50) / 7 = 6.25/7 ≈ 0.8929
   * Title score: 0.65 (AI)
   * Total: 0.70 × 0.8929 + 0.30 × 0.65 ≈ 0.6250 + 0.1950 = 0.8200
   */
  public function testTaggingConfidenceUnhcrFixture(): void {
    $result = $this->buildResultWithTagging(
      fieldSources: [
        'field_primary_country'  => SeriesMatchFieldUpdateSource::AllCandidates,
        'field_country'          => SeriesMatchFieldUpdateSource::Merged,
        'field_language'         => SeriesMatchFieldUpdateSource::AllCandidates,
        'field_content_format'   => SeriesMatchFieldUpdateSource::AllCandidates,
        'field_theme'            => SeriesMatchFieldUpdateSource::MostRecent,
        'field_disaster'         => SeriesMatchFieldUpdateSource::AllCandidates,
        'field_disaster_type'    => SeriesMatchFieldUpdateSource::AllCandidates,
      ],
      titleSource: SeriesMatchTitleSource::AiGenerated,
    );

    $scoring = $this->defaultScoring();
    $field_score = (5 * 1.0 + 1 * 0.75 + 1 * 0.50) / 7;
    $title_score = 0.65;
    $expected = round(0.70 * $field_score + 0.30 * $title_score, 4);

    $this->assertEqualsWithDelta($expected, $result->calculateTaggingConfidence($scoring), 0.0001);
  }

  /**
   * Failed title sources all map to 0.25 band.
   */
  #[DataProvider('failedTitleSourceProvider')]
  public function testTaggingConfidenceFailedTitleBand(SeriesMatchTitleSource $source): void {
    $result = $this->buildResultWithTagging(
      fieldSources: ['field_a' => SeriesMatchFieldUpdateSource::AllCandidates],
      titleSource: $source,
    );
    $scoring = $this->defaultScoring();
    // field_score = 1.0, title_score = 0.25.
    $expected = round(0.70 * 1.0 + 0.30 * 0.25, 4);
    $this->assertEqualsWithDelta($expected, $result->calculateTaggingConfidence($scoring), 0.0001);
  }

  /**
   * Data provider: all failed/skipped title sources that map to band 0.25.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource}>
   *   Title source enum cases for failed title scoring.
   */
  public static function failedTitleSourceProvider(): array {
    return [
      'no candidate titles'   => [SeriesMatchTitleSource::FailedNoCandidateTitles],
      'no attachment text'    => [SeriesMatchTitleSource::SkippedNoAttachmentText],
      'inconsistent examples' => [SeriesMatchTitleSource::SkippedInconsistentExamples],
      'low title match confidence' => [SeriesMatchTitleSource::SkippedLowTitleMatchConfidence],
      'ai disabled'           => [SeriesMatchTitleSource::SkippedAiDisabled],
      'unsupported ai plugin' => [SeriesMatchTitleSource::FailedUnsupportedAiPlugin],
      'ai call error'         => [SeriesMatchTitleSource::FailedAiCallError],
      'empty ai output'       => [SeriesMatchTitleSource::FailedEmptyAiOutput],
      'ungrounded markers'    => [SeriesMatchTitleSource::FailedUngroundedTitleMarkers],
      'series pattern mismatch' => [SeriesMatchTitleSource::FailedSeriesPatternMismatch],
    ];
  }

  /**
   * Changing a configured weight changes the computed series confidence.
   */
  public function testSeriesConfidenceUsesConfiguredWeights(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 1.0,
      clusterScore: 1.0,
      clusterCount: 1,
      bothSignalsCount: 0,
      mergedAfterLimitCount: 17,
    );

    $default = $this->defaultScoring();
    $custom = SeriesMatchConfidenceScoringSettings::fromConfigArray([
      'series' => [
        'cluster_share_weight' => 0.50,
        'cluster_score_weight' => 0.50,
        'dual_signal_ratio_weight' => 0.0,
        'cluster_share_dominance_weight' => 0.0,
      ],
      'tagging' => SeriesMatchConfidenceScoringSettings::defaultConfig()['tagging'],
    ]);

    $this->assertEqualsWithDelta(0.80, $result->calculateSeriesConfidence($default), 0.0001);
    $this->assertEqualsWithDelta(1.0, $result->calculateSeriesConfidence($custom), 0.0001);
  }

  /**
   * Builds a passed-minimum result with specific evidence values.
   *
   * @param float $bestClusterShare
   *   Best cluster share for series scoring.
   * @param float $clusterScore
   *   Composite cluster score.
   * @param int $clusterCount
   *   Number of clusters.
   * @param int $bothSignalsCount
   *   Candidates matching both title and URL signals.
   * @param int $mergedAfterLimitCount
   *   Candidate count after applying the retrieval limit.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   Result with the given evidence values.
   */
  private function buildResultWithEvidence(
    float $bestClusterShare,
    float $clusterScore,
    int $clusterCount,
    int $bothSignalsCount,
    int $mergedAfterLimitCount,
  ): SeriesMatchResult {
    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(),
      new SeriesMatchEvidence(
        candidateIds: range(1, max(1, $mergedAfterLimitCount)),
        bestClusterShare: $bestClusterShare,
        clusterScore: $clusterScore,
        clusterCount: $clusterCount,
        bothSignalsCount: $bothSignalsCount,
        mergedAfterLimitCount: $mergedAfterLimitCount,
      ),
    );
  }

  /**
   * Builds a passed-minimum result with specific tagging field sources.
   *
   * @param array<string, \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource> $fieldSources
   *   Field name → provenance enum.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource $titleSource
   *   Title provenance.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   Result with the given tagging field sources and title source.
   */
  private function buildResultWithTagging(
    array $fieldSources,
    SeriesMatchTitleSource $titleSource,
  ): SeriesMatchResult {
    $fields = array_fill_keys(array_keys($fieldSources), []);
    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: $fields,
        updatedFieldSources: $fieldSources,
        titleSource: $titleSource,
      ),
      new SeriesMatchEvidence(candidateIds: [1]),
    );
  }

}
