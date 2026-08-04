<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportDuplicateMatch;

use Drupal\Core\Entity\EntityInterface;

/**
 * Per-request stash for report-duplicate detection across save hooks.
 */
final class DuplicateMatchApplyContext {

  /**
   * Per-entity apply contexts for the current request.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, self>|null
   */
  private static ?\WeakMap $contexts = NULL;

  /**
   * Constructs a DuplicateMatchApplyContext.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result with matches.
   * @param bool $isFormCreate
   *   TRUE when the report was created via the editorial form (not imported).
   */
  public function __construct(
    public readonly DuplicateMatchResult $result,
    public readonly bool $isFormCreate,
  ) {}

  /**
   * Attach context to an entity for later hooks in this request.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   * @param self $context
   *   Context to store.
   */
  public static function set(EntityInterface $entity, self $context): void {
    self::$contexts ??= new \WeakMap();
    self::$contexts[$entity] = $context;
  }

  /**
   * Get context for an entity, if any.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return self|null
   *   Context or NULL.
   */
  public static function get(EntityInterface $entity): ?self {
    return self::$contexts[$entity] ?? NULL;
  }

  /**
   * Remove context after it has been consumed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public static function clear(EntityInterface $entity): void {
    if (self::$contexts !== NULL && isset(self::$contexts[$entity])) {
      unset(self::$contexts[$entity]);
    }
  }

}
