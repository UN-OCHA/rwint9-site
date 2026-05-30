<?php

/**
 * @file
 * ReliefWeb API hooks.
 */

/**
 * Ephemeral entity properties used when saving content for the API index.
 *
 * Set on the entity before save; consumed in hook_entity_after_save():
 * - $entity->needs_reindex (bool): queue batch re-indexing for the entity.
 * - $entity->skip_reliefweb_api_indexing (bool): skip indexing for this save
 *   only (index removal on entity delete is unaffected).
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
