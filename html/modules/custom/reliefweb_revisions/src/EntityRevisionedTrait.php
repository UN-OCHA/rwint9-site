<?php

namespace Drupal\reliefweb_revisions;

use Drupal\Core\Entity\ContentEntityInterface;

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
   * Get the entity's revision history cache tag.
   *
   * @see \Drupal\reliefweb_revisions\EntityRevisionedInterface::getHistoryCacheTags()
   */
  public function getHistoryCacheTag(): string {
    return implode(':', [
      'reliefweb_revisions',
      'history',
      'entity',
      $this->getEntityTypeId(),
      $this->bundle(),
      $this->id(),
    ]);
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

  /**
   * Returns an array of field names to skip when checking for changes.
   *
   * Note: the method's name is prefixed with `trait` so it can override the
   * original trait method. See ContentEntityBase.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A content entity object.
   *
   * @return string[]
   *   An array of field names.
   *
   * @see \Drupal\Core\Entity\EntityChangesDetectionTrait::getFieldsToSkipFromTranslationChangesCheck()
   * @see \Drupal\Core\Entity\ContentEntityBase::getFieldsToSkipFromTranslationChangesCheck()
   */
  protected function traitGetFieldsToSkipFromTranslationChangesCheck(ContentEntityInterface $entity) {
    /** @var \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type */
    $entity_type = $entity->getEntityType();

    $revision_keys = $entity_type->getRevisionMetadataKeys();
    // A new revision comment is considered a change.
    unset($revision_keys['revision_log_message']);

    // A list of known revision metadata fields which should be skipped from
    // the comparison.
    $fields = [
      $entity_type->getKey('revision'),
      $entity_type->getKey('revision_translation_affected'),
    ];
    $fields = array_merge($fields, array_values($revision_keys));

    // Computed fields should be skipped by the check for translation changes.
    foreach (array_diff_key($entity->getFieldDefinitions(), array_flip($fields)) as $field_name => $field_definition) {
      if ($field_definition->isComputed()) {
        $fields[] = $field_name;
      }
    }

    return $fields;
  }

}
