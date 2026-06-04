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

  /**
   * Returns the editor attention level for this field update source.
   */
  public function attentionLevel(): SeriesMatchAttentionLevel {
    return match ($this) {
      self::AllCandidates => SeriesMatchAttentionLevel::Ok,
      self::Merged => SeriesMatchAttentionLevel::Info,
      self::MostRecent => SeriesMatchAttentionLevel::Warning,
      self::Skipped => SeriesMatchAttentionLevel::Error,
    };
  }

}
