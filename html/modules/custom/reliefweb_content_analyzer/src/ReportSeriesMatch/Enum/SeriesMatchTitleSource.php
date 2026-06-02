<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum;

/**
 * How the proposed report title was chosen during series matching.
 */
enum SeriesMatchTitleSource: string {

  case KeptOriginalPatternMatch = 'kept_original_pattern_match';
  case AiGenerated = 'ai_generated';
  case FailedNoCandidateTitles = 'failed_no_candidate_titles';
  case FailedNoSourceText = 'failed_no_source_text';
  case FailedAi = 'failed_ai';
  case FailedEmptyAiOutput = 'failed_empty_ai_output';

}
