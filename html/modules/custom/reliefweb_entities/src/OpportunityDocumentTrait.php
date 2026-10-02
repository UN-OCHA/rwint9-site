<?php

namespace Drupal\reliefweb_entities;

/**
 * Trait for "opportunity" documents like jobs and trainings.
 *
 * @see Drupal\reliefweb_entities\DocuemntInterface
 */
trait OpportunityDocumentTrait {

  /**
   * Update the status for the entity based on the expiration date.
   */
  protected function updateModerationStatusFromExpirationDate() {
    if ($this->getModerationStatus() === 'published' && $this->hasExpired()) {
      $this->setModerationStatus('expired');
    }
  }

  /**
   * Update creation date when the opportunity is published for the first time.
   */
  protected function updateDateWhenPublished() {
    if ($this->id() === NULL || !$this->isPublishedModerationStatus()) {
      return;
    }

    // Update publication date if this is the first time the opportunity goes
    // to a published-equivalent status.
    if (!$this->wasEverPublished()) {
      $this->setCreatedTime($this->getChangedTime());
    }
  }

}
