<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum;

/**
 * Machine-readable outcome for series candidate lookup.
 */
enum SeriesMatchReason: string {

  case NotReport = 'not_report';
  case NoSource = 'no_source';
  case LimitZero = 'limit_zero';
  case NoPatterns = 'no_patterns';
  case NoPatternMatches = 'no_pattern_matches';
  case BelowMinimumCluster = 'below_minimum_cluster';

  /**
   * Returns a short editor-facing label for this reason.
   *
   * @return string
   *   Short editor-facing label.
   */
  public function label(): string {
    return match ($this) {
      self::NotReport => 'Not a report',
      self::NoSource => 'No source',
      self::LimitZero => 'Candidate limit is zero',
      self::NoPatterns => 'No title or URL patterns',
      self::NoPatternMatches => 'No pattern matches',
      self::BelowMinimumCluster => 'Below minimum series cluster size',
    };
  }

}
