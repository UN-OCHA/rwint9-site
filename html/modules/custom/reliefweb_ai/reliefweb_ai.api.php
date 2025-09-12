<?php

/**
 * @file
 * ReliefWeb AI API file.
 */

use Drupal\Core\Entity\EntityInterface;

/**
 * Hook so other modules can force a certain language for language detection.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity being processed.
 *
 * @return string|null
 *   The forced language code or NULL.
 */
function hook_reliefweb_ai_force_language(EntityInterface $entity): ?string {
  // Force a specific language for detection.
  $language = 'fr';

  return $language;
}
