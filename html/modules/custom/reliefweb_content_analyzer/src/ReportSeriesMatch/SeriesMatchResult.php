<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;

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
   * Weights (sum = 1.0 when all signals present):
   * - 0.40 cluster share (fraction of candidates in the best cluster)
   * - 0.25 cluster composite score
   * - 0.20 dual title+URL retrieval ratio
   * - 0.15 single-cluster bonus (all candidates collapsed into one cluster)
   *
   * @return float|null
   *   A score between 0.0 and 1.0, or NULL when the match is not scorable.
   */
  public function calculateSeriesConfidence(): ?float {
    if (!$this->status->passedMinimum || $this->evidence->candidateIds === []) {
      return NULL;
    }

    $score = 0.0;

    $score += 0.40 * min(1.0, max(0.0, $this->evidence->bestClusterShare));
    $score += 0.25 * min(1.0, $this->evidence->clusterScore);

    if ($this->evidence->mergedAfterLimitCount > 0) {
      $both_ratio = $this->evidence->bothSignalsCount / $this->evidence->mergedAfterLimitCount;
      $score += 0.20 * min(1.0, $both_ratio);
    }

    if ($this->evidence->clusterCount === 1) {
      $score += 0.15;
    }

    return round(min(1.0, max(0.0, $score)), 4);
  }

  /**
   * Computes a tagging proposal confidence score from field provenance.
   *
   * Measures how safe it is to apply the proposed field values and title,
   * independent of how coherent the series cluster is.
   *
   * The score is a weighted combination of:
   * - Field provenance (70%): average weight per field based on source type.
   *   AllCandidates=1.0, Merged=0.75, MostRecent=0.50, Skipped=0.0.
   * - Title band (30%): KeptOriginalPatternMatch=1.0, AiGenerated=0.65,
   *   other title outcomes=0.25.
   *
   * @return float|null
   *   A score between 0.0 and 1.0, or NULL when the proposal is not scorable.
   */
  public function calculateTaggingConfidence(): ?float {
    $sources = array_values($this->proposal->updatedFieldSources);
    if ($sources === []) {
      return NULL;
    }

    $field_weights = [
      SeriesMatchFieldUpdateSource::AllCandidates->value => 1.00,
      SeriesMatchFieldUpdateSource::Merged->value => 0.75,
      SeriesMatchFieldUpdateSource::MostRecent->value => 0.50,
      SeriesMatchFieldUpdateSource::Skipped->value => 0.00,
    ];

    $field_score = 0.0;
    foreach ($sources as $source) {
      $field_score += $field_weights[$source->value] ?? 0.0;
    }
    $field_score /= count($sources);

    $title_score = match ($this->proposal->titleSource) {
      SeriesMatchTitleSource::KeptOriginalPatternMatch => 1.00,
      SeriesMatchTitleSource::AiGenerated => 0.65,
      default => 0.25,
    };

    $score = (0.70 * $field_score) + (0.30 * $title_score);

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
