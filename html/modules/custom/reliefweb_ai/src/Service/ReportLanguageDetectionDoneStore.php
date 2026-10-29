<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Service;

use Drupal\Core\Entity\EntityInterface;

/**
 * Per-request store for report language detection completion.
 *
 * Prevents running language detection more than once for the same entity
 * object during a request (e.g. re-entrant post-classify hooks).
 */
final class ReportLanguageDetectionDoneStore {

  /**
   * Per-entity done flags for the current request.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, true>|null
   */
  private static ?\WeakMap $done = NULL;

  /**
   * Marks that language detection has been handled for this entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being classified.
   */
  public static function mark(EntityInterface $entity): void {
    self::done()[$entity] = TRUE;
  }

  /**
   * Checks whether language detection was already handled for this entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being classified.
   *
   * @return bool
   *   TRUE if language detection should be skipped.
   */
  public static function isDone(EntityInterface $entity): bool {
    return self::done()->offsetExists($entity);
  }

  /**
   * Returns the per-request done WeakMap.
   *
   * @return \WeakMap<\Drupal\Core\Entity\EntityInterface, true>
   *   The done WeakMap.
   */
  private static function done(): \WeakMap {
    return self::$done ??= new \WeakMap();
  }

}
