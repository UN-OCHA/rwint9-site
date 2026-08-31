<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchConfidenceScoringSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;

/**
 * Aggregate result of report series candidate lookup.
 *
 * Combines gate status, scoring evidence, an optional field proposal, and
 * optional debug diagnostics for a single match run.
 */
final readonly class SeriesMatchResult {

  /**
   * Constructs a series match result.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus $status
   *   Gate outcome for the match run.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal $proposal
   *   Proposed field values and provenance (empty when matching stopped early).
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence $evidence
   *   Retrieval and clustering metrics from the run.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace|null $debug
   *   Form-only diagnostics, when the matcher was asked to include them.
   */
  public function __construct(
    public SeriesMatchStatus $status,
    public SeriesMatchProposal $proposal,
    public SeriesMatchEvidence $evidence,
    public ?SeriesMatchDebugTrace $debug = NULL,
  ) {}

  /**
   * Computes a series identification confidence score from clustering evidence.
   *
   * Measures how reliably we found the right series, independent of tagging
   * quality.
   *
   * Weights are configured via confidence_scoring settings (sum = 1.0 when all
   * signals present):
   * - cluster share (pattern-score-weighted fraction of retrieval in the
   *   selected cluster)
   * - cluster composite score
   * - dual title+URL retrieval ratio
   * - dominance bonus (cluster_share_dominance_weight × bestClusterShare)
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchConfidenceScoringSettings $scoring
   *   Confidence formula weights from workflow config.
   *
   * @return float|null
   *   A score between 0.0 and 1.0, or NULL when the match is not scorable.
   */
  public function calculateSeriesConfidence(SeriesMatchConfidenceScoringSettings $scoring): ?float {
    if (!$this->status->passedMinimum || $this->evidence->candidateIds === []) {
      return NULL;
    }

    $share = min(1.0, max(0.0, $this->evidence->bestClusterShare));
    $score = 0.0;

    $score += $scoring->clusterShareWeight * $share;
    $score += $scoring->clusterScoreWeight * min(1.0, $this->evidence->clusterScore);

    if ($this->evidence->mergedAfterLimitCount > 0) {
      $both_ratio = $this->evidence->bothSignalsCount / $this->evidence->mergedAfterLimitCount;
      $score += $scoring->dualSignalRatioWeight * min(1.0, $both_ratio);
    }

    $score += $scoring->clusterShareDominanceWeight * $share;

    return round(min(1.0, max(0.0, $score)), 4);
  }

  /**
   * Computes a tagging proposal confidence score from field provenance.
   *
   * Measures how safe it is to apply the proposed field values and title,
   * independent of how coherent the series cluster is.
   *
   * The score is a weighted combination of:
   * - Field provenance: average weight per field based on source type.
   * - Title band: score per title source outcome from config.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchConfidenceScoringSettings $scoring
   *   Confidence formula weights from workflow config.
   *
   * @return float|null
   *   A score between 0.0 and 1.0, or NULL when the proposal is not scorable.
   */
  public function calculateTaggingConfidence(SeriesMatchConfidenceScoringSettings $scoring): ?float {
    $sources = array_values($this->proposal->updatedFieldSources);
    if ($sources === []) {
      return NULL;
    }

    $field_score = 0.0;
    foreach ($sources as $source) {
      $field_score += $scoring->fieldProvenanceWeight($source);
    }
    $field_score /= count($sources);

    $title_score = $scoring->titleSourceScore($this->proposal->titleSource);

    $score = ($scoring->fieldBlendWeight * $field_score) + ($scoring->titleBlendWeight * $title_score);

    return round(min(1.0, max(0.0, $score)), 4);
  }

  /**
   * Creates a result when series matching does not apply to the entity.
   *
   * @return self
   *   A result with applicable FALSE and reason not_report.
   */
  public static function notApplicable(): self {
    return new self(
      new SeriesMatchStatus(
        applicable: FALSE,
        reason: SeriesMatchReason::NotReport,
      ),
      new SeriesMatchProposal(),
      new SeriesMatchEvidence(),
    );
  }

  /**
   * Creates a result when matching stopped before a successful proposal.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason $reason
   *   Primary outcome for the stopped run.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence $evidence
   *   Partial or full evidence collected before the stop.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace|null $debug
   *   Optional debug trace when diagnostics were requested.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason|null $rejectionReason
   *   Rejection reason when candidates failed a gating rule.
   * @param bool $passedMinimum
   *   Whether the winning cluster passed the minimum size threshold.
   *
   * @return self
   *   A result with an empty proposal.
   */
  public static function stopped(
    SeriesMatchReason $reason,
    SeriesMatchEvidence $evidence = new SeriesMatchEvidence(),
    ?SeriesMatchDebugTrace $debug = NULL,
    ?SeriesMatchReason $rejectionReason = NULL,
    bool $passedMinimum = FALSE,
  ): self {
    return new self(
      new SeriesMatchStatus(
        reason: $reason,
        rejectionReason: $rejectionReason,
        passedMinimum: $passedMinimum,
      ),
      new SeriesMatchProposal(),
      $evidence,
      $debug,
    );
  }

}
