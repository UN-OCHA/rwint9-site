<?php

declare(strict_types=1);

namespace Drupal\reliefweb_api\Indexing;

use Drupal\Core\Entity\EntityInterface;

/**
 * Per-request store for skip-once API indexing intent.
 *
 * Callers mark an entity before save; reliefweb_api consumes and clears the
 * flag when handling entity_after_save for that save only.
 */
final class ReliefWebApiIndexingSkipStore {

  /**
   * Per-entity skip flags for the current request.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, true>|null
   */
  private static ?\WeakMap $skips = NULL;

  /**
   * Marks that API indexing should be skipped for the next save of this entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  public static function markSkip(EntityInterface $entity): void {
    self::skips()[$entity] = TRUE;
  }

  /**
   * Consumes a skip flag if one was set for this entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being indexed.
   *
   * @return bool
   *   TRUE if indexing should be skipped for this save.
   */
  public static function consumeSkip(EntityInterface $entity): bool {
    if (!self::skips()->offsetExists($entity)) {
      return FALSE;
    }
    unset(self::skips()[$entity]);
    return TRUE;
  }

  /**
   * Returns the per-request skip WeakMap.
   *
   * @return \WeakMap<\Drupal\Core\Entity\EntityInterface, true>
   *   The skip WeakMap.
   */
  private static function skips(): \WeakMap {
    return self::$skips ??= new \WeakMap();
  }

}
