<?php

namespace Drupal\reliefweb_revisions;

/**
 * Interface for entities with a revision history.
 */
interface EntityRevisionedInterface {

  /**
   * Get the entity's revision history.
   *
   * @return array
   *   Entity revision history render array.
   */
  public function getHistory();

}
