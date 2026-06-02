<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum;

/**
 * Provenance for a proposed field value in series matching.
 */
enum SeriesMatchFieldUpdateSource: string {

  case AllCandidates = 'all_candidates';
  case MostRecent = 'most_recent';
  case Merged = 'merged';
  case Skipped = 'skipped';

}
