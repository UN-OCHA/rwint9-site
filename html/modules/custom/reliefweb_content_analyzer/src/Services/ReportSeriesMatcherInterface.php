<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;

/**
 * Matches report nodes to a document series.
 */
interface ReportSeriesMatcherInterface {

  /**
   * Finds report nodes that belong to the same series as the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity being analyzed (typically a report node).
   * @param bool $includeDebug
   *   When TRUE, includes form-only diagnostics on the result.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   Status, proposal, evidence and optional debug trace.
   */
  public function findSeriesCandidates(EntityInterface $entity, bool $includeDebug = FALSE): SeriesMatchResult;

}
