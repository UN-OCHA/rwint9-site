<?php

/**
 * @file
 * ReliefWeb API hooks.
 */

/**
 * Perform operations after an entity has been indexed.
 *
 * Note: the database may not available anymore at this point.
 *
 * @param string $entity_type_id
 *   The entity type ID (ex: node).
 * @param string $bundle
 *   The entity bundle.
 * @param int|null $entity_id
 *   The entity id.
 */
function hook_reliefweb_api_post_indexing($entity_type_id, $bundle, $entity_id = NULL) {
  // Clear the cache for the bundle.
  \Drupal::cache()->invalidateTags([$entity_type_id . '_list:' . $bundle]);
}
