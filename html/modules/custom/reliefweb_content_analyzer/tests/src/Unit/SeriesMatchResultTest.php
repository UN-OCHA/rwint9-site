<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

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
   * Returns NULL when passedMinimum is FALSE.
   */
  public function testSeriesConfidenceNullWhenNotPassedMinimum(): void {
    $result = SeriesMatchResult::stopped(
      SeriesMatchReason::BelowMinimumCluster,
    );
    $this->assertNull($result->calculateSeriesConfidence());
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
    $this->assertNull($result->calculateSeriesConfidence());
  }

  /**
   * Perfect cluster, single cluster, no dual signal.
   *
   * Expected: 0.40+0.25+0+0.15 = 0.80.
   */
  public function testSeriesConfidencePerfectClusterNoUrl(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 1.0,
      clusterScore: 1.0,
      clusterCount: 1,
      bothSignalsCount: 0,
      mergedAfterLimitCount: 17,
    );
    $this->assertEqualsWithDelta(0.80, $result->calculateSeriesConfidence(), 0.0001);
  }

  /**
   * Perfect cluster, single cluster, full dual signal.
   *
   * Expected: 0.40+0.25+0.20+0.15 = 1.00.
   */
  public function testSeriesConfidencePerfect(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 1.0,
      clusterScore: 1.0,
      clusterCount: 1,
      bothSignalsCount: 17,
      mergedAfterLimitCount: 17,
    );
    $this->assertEqualsWithDelta(1.0, $result->calculateSeriesConfidence(), 0.0001);
  }

  /**
   * Multiple clusters prevents single-cluster bonus; 0.40+0.25+0 = 0.65.
   */
  public function testSeriesConfidenceMultipleClustersPenalty(): void {
    $result = $this->buildResultWithEvidence(
      bestClusterShare: 1.0,
      clusterScore: 1.0,
      clusterCount: 3,
      bothSignalsCount: 0,
      mergedAfterLimitCount: 17,
    );
    $this->assertEqualsWithDelta(0.65, $result->calculateSeriesConfidence(), 0.0001);
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
    $this->assertNull($result->calculateTaggingConfidence());
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
    $this->assertEqualsWithDelta(1.0, $result->calculateTaggingConfidence(), 0.0001);
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

    $field_score = (5 * 1.0 + 1 * 0.75 + 1 * 0.50) / 7;
    $title_score = 0.65;
    $expected = round(0.70 * $field_score + 0.30 * $title_score, 4);

    $this->assertEqualsWithDelta($expected, $result->calculateTaggingConfidence(), 0.0001);
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
    // field_score = 1.0, title_score = 0.25.
    $expected = round(0.70 * 1.0 + 0.30 * 0.25, 4);
    $this->assertEqualsWithDelta($expected, $result->calculateTaggingConfidence(), 0.0001);
  }

  /**
   * Data provider: all failed/skipped title sources that map to band 0.25.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource}>
   *   Title source enum cases for failed title scoring.
   */
  public static function failedTitleSourceProvider(): array {
    return [
      'no candidate titles' => [SeriesMatchTitleSource::FailedNoCandidateTitles],
      'no source text'      => [SeriesMatchTitleSource::FailedNoSourceText],
      'ai error'            => [SeriesMatchTitleSource::FailedAi],
      'empty ai output'     => [SeriesMatchTitleSource::FailedEmptyAiOutput],
    ];
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
