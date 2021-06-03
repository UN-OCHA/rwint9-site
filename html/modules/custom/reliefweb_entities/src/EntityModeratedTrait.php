<?php

namespace Drupal\reliefweb_entities;

/**
 * Provides a trait for the entity moderation status.
 */
trait EntityModeratedTrait {

  /**
   * Get the moderation status.
   *
   * @see \Drupal\reliefweb_entities\EntityModeratedInterface::getModerationStatus()
   */
  public function getModerationStatus() {
    if ($this->hasField('moderation_state')) {
      return $this->get('moderation_state')->getString();
    }
    return $this->isPublished() ? 'published' : 'unpublished';
  }

  /**
   * Get the moderation status label.
   *
   * @see \Drupal\reliefweb_entities\EntityModeratedInterface::getModerationStatusLabel()
   *
   * @todo review that because this doesn't allow for translation of the status.
   */
  public function getModerationStatusLabel() {
    if ($this->hasField('moderation_state')) {
      $status = $this->get('moderation_state')->getString();

      // The status label is part of the workflow configuration.
      $config = \Drupal::config('workflows.workflow.' . $this->bundle());
      if (!empty($config)) {
        $label = $config->get('type_settings.states.' . $status . '.label');
      }

      // If we couldn't find the label, use the moderation status itself.
      if (empty($label)) {
        $label = ucfirst(str_replace('_', ' ', $status));
      }

      return $label;
    }
    return '';
  }

}
