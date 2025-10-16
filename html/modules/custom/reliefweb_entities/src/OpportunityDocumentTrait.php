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
    if ($this->id() === NULL || $this->getModerationStatus() !== 'published') {
      return;
    }

    $entity_type = $this->getEntityType();
    $table = $entity_type->getRevisionDataTable();
    $id_field = $entity_type->getKey('id');

    $previously_published = \Drupal::database()
      ->select($table, $table)
      ->fields($table, [$entity_type->getKey('revision')])
      ->condition($table . '.' . $id_field, $this->id(), '=')
      ->condition($table . '.moderation_status', 'published', '=')
      ->range(0, 1)
      ->execute()
      ?->fetchField();

    // Update publication date if published for the first time.
    if (empty($previously_published)) {
      $this->setCreatedTime($this->getChangedTime());
    }
  }

}
