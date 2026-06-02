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

}
