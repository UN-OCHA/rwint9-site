<?php

/**
 * @file
 * ReliefWeb AI API file.
 */

use Drupal\Core\Entity\EntityInterface;

/**
 * Force a language taxonomy term for report language detection.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity being processed.
 *
 * @return int|string|null
 *   Language taxonomy term ID to apply, or NULL to leave detection alone.
 *   ModuleHandler::invokeAll() omits NULL returns; the first non-NULL
 *   implementation wins.
 */
function hook_reliefweb_ai_force_language(EntityInterface $entity): int|string|null {
  // Force French (example term ID).
  return 268;
}
