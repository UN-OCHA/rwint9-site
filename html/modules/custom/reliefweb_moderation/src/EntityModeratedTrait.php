<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides a trait for moderated entities.
 */
trait EntityModeratedTrait {

  /**
   * Set the moderation status.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::setModerationStatus()
   */
  public function setModerationStatus($status) {
    if ($this->hasField('moderation_state')) {
      $this->set('moderation_state', $status, FALSE);
    }
  }

  /**
   * Get the moderation status.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getModerationStatus()
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
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getModerationStatusLabel()
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

  /**
   * Get the original revision log message.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getOriginalRevisionLogMessage()
   */
  public function getOriginalRevisionLogMessage() {
    if ($this instanceof RevisionLogInterface) {
      $key = $this->getEntityType()->getRevisionMetadataKey('revision_log_message');
      if (!empty($this->values[$key][$this->activeLangcode])) {
        $log = $this->values[$key][$this->activeLangcode];
        if (is_string($log)) {
          return trim($log);
        }
        // For some reason when the form is rebuilt after previewing the entity
        // the log field is an array with a value property while it's a string
        // otherwise...
        elseif (is_array($log) && isset($log['value'])) {
          return trim($log['value']);
        }
      }
    }
    return '';
  }

  /**
   * Get the type of the original revision log message.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getOriginalRevisionLogMessageType()
   */
  public function getOriginalRevisionLogMessageType() {
    // If the revision user is the same as the creator of the entity, then
    // we consider the message from being an instruction for other editors,
    // otherwise we consider the message as being some feedback to the author
    // or previous reviewers.
    if ($this instanceof EntityOwnerInterface && $this instanceof RevisionLogInterface) {
      $key = $this->getEntityType()->getRevisionMetadataKey('revision_user');
      if (isset($this->values[$key][$this->activeLangcode])) {
        return $this->getOwnerId() == $this->values[$key][$this->activeLangcode] ? 'instruction' : 'feedback';
      }
    }
    return 'instruction';
  }

}
