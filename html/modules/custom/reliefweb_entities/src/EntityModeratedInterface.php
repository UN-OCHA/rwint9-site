<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for entities with a moderation status.
 */
interface EntityModeratedInterface {

  /**
   * Get the moderation status.
   *
   * @return string
   *   The moderation status.
   */
  public function getModerationStatus();

  /**
   * Get the moderation status label.
   *
   * @return string
   *   The moderation status label.
   */
  public function getModerationStatusLabel();

}
