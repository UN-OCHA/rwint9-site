<?php

namespace Drupal\reliefweb_revisions;

/**
 * Provides a trait for the entity revision history.
 */
trait EntityRevisionedTrait {

  /**
   * Entity history service.
   *
   * @var \Drupal\reliefweb_revisions\Services\EntityHistory
   */
  protected $entityHistory;

  /**
   * Get the entity's revision history.
   *
   * @see \Drupal\reliefweb_revisions\EntityRevisionedInterface::getHistory()
   */
  public function getHistory() {
    return $this->getEntityHistoryService()->getEntityHistory($this);
  }

  /**
   * Get the entity's revision history content.
   *
   * @see \Drupal\reliefweb_revisions\EntityRevisionedInterface::getHistoryContent()
   */
  public function getHistoryContent() {
    return $this->getEntityHistoryService()->getEntityHistoryContent($this);
  }

  /**
   * Get the entity history service.
   *
   * @return \Drupal\reliefweb_revisions\Services\EntityHistory
   *   Entity history service.
   */
  protected function getEntityHistoryService() {
    if (!isset($this->entityHistory)) {
      $this->entityHistory = \Drupal::service('reliefweb_revisions.entity.history');
    }
    return $this->entityHistory;
  }

}
