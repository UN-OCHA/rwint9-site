<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;

/**
 * Gate outcome for a report series match run.
 */
final readonly class SeriesMatchStatus {

  /**
   * Constructs a series match status value object.
   *
   * @param bool $applicable
   *   Whether series matching applies to the analyzed entity.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason|null $reason
   *   Primary outcome when matching did not produce candidates or a proposal.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason|null $rejectionReason
   *   Reason when candidates were found but failed a gating rule.
   * @param bool $passedMinimum
   *   Whether the winning cluster passed the minimum size threshold.
   */
  public function __construct(
    public bool $applicable = TRUE,
    public ?SeriesMatchReason $reason = NULL,
    public ?SeriesMatchReason $rejectionReason = NULL,
    public bool $passedMinimum = FALSE,
  ) {}

}
