<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * Helper to retrieve information about entities.
 */
class EntityHelper {

  /**
   * Get the revision log message.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity this field is attached to.
   * @param bool $load_revision
   *   Whether to get to load the entity revision to get the log message or not.
   *
   * @return string
   *   Log message.
   */
  public static function getRevisionLogMessage(EntityInterface $entity, $load_revision = FALSE) {
    $entity_id = $entity->id();
    if (!empty($entity_id) && $entity instanceof RevisionLogInterface) {
      if (empty($load_revision)) {
        $log = $entity->getRevisionLogMessage();
      }
      // Load the revision and retrieve its log message. This is useful when
      // the entity comes from the entity form object because the entity
      // has been modified (some fields have been emptied like the log message
      // one).
      elseif ($entity->getRevisionId() !== NULL) {
        $log = \Drupal::entityTypeManager()
          ?->getStorage($entity->getEntityTypeId())
          ?->loadRevision($entity->getRevisionId())
          ?->getRevisionLogMessage() ?? '';
      }
      return trim($log);
    }
    return '';
  }

}
