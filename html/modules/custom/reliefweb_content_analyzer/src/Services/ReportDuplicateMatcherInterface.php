<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;

/**
 * Finds near-duplicate reports for new report submissions.
 */
interface ReportDuplicateMatcherInterface {

  /**
   * Find near-duplicate reports for the given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The report being saved (typically new).
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult
   *   Matches at or above threshold, or an empty result with a reason.
   */
  public function findDuplicates(ContentEntityInterface $entity): DuplicateMatchResult;

}
