<?php

namespace Drupal\reliefweb_revisions;

/**
 * Interface for entities with a revision history.
 */
interface EntityRevisionedInterface {

  /**
   * Get the entity's revision history.
   *
   * Note: the content will be loaded asynchronously.
   *
   * @return array
   *   Entity revision history render array.
   */
  public function getHistory();

  /**
   * Get the entity's revision history content.
   *
   * @return array
   *   Entity revision history render array.
   */
  public function getHistoryContent();

  /**
   * Get the entity's revision history cache tag.
   *
   * @return string
   *   Cache tag.
   */
  public function getHistoryCacheTag(): string;

}
