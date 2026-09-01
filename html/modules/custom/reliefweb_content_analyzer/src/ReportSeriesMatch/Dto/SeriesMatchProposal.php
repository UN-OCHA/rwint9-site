<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;

/**
 * Proposed field values and provenance from a successful series match.
 */
final readonly class SeriesMatchProposal {

  /**
   * Constructs a series match proposal value object.
   *
   * @param array<string, null|string|string[]|int[]> $updatedFields
   *   Proposed field values to apply to the analyzed report, keyed by field
   *   name.
   * @param array<string, \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource> $updatedFieldSources
   *   Provenance for each entry in updatedFields, keyed by field name.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource|null $titleSource
   *   How the proposed title was chosen, when title generation ran.
   * @param float|null $titleAiDurationSeconds
   *   AI title generation duration in seconds, when titleSource is
   *   ai_generated.
   * @param int $mostRecentCandidateId
   *   Candidate node ID used as the most recent source for field copying.
   */
  public function __construct(
    public array $updatedFields = [],
    public array $updatedFieldSources = [],
    public ?SeriesMatchTitleSource $titleSource = NULL,
    public ?float $titleAiDurationSeconds = NULL,
    public int $mostRecentCandidateId = 0,
  ) {}

}
