<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides a trait for moderated entities.
 */
trait EntityModeratedTrait {

  /**
   * Allowed moderation statuses.
   *
   * @var array
   */
  protected $allowedModerationStatuses;

  /**
   * The moderation service for the entity.
   *
   * @var \Drupal\reliefweb_moderation\Services\ModerationService\Interface
   */
  protected $moderationService;

  /**
   * Set the moderation status.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::setModerationStatus()
   */
  public function setModerationStatus($status) {
    $statuses = $this->getAllowedModerationStatuses();
    if (!isset($statuses[$status])) {
      throw new \InvalidArgumentException('Status ' . $status . 'is not allowed.');
    }
    $this->moderation_status->value = $status;
  }

  /**
   * Get the moderation status.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getModerationStatus()
   */
  public function getModerationStatus() {
    return $this->moderation_status->value ?? $this->getDefaultModerationStatus();
  }

  /**
   * Get the moderation status label.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getModerationStatusLabel()
   */
  public function getModerationStatusLabel() {
    return $this->getAllowedModerationStatuses()[$this->getModerationStatus()];
  }

  /**
   * Get the list of allowed statuses for the enitity.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getAllowedModerationStatuses()
   */
  public function getAllowedModerationStatuses() {
    if (!isset($this->allowedModerationStatuses)) {
      $service = $this->getModerationService();
      if (isset($service)) {
        $this->allowedModerationStatuses = $service->getStatuses();
      }
      else {
        $this->allowedModerationStatuses = [
          'draft' => $this->t('Draft'),
          'published' => $this->t('Published'),
        ];
      }
    }
    return $this->allowedModerationStatuses;
  }

  /**
   * Get the default moderation status.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getDefaultModerationStatus()
   */
  public function getDefaultModerationStatus() {
    return 'draft';
  }

  /**
   * Get the moderation service for the entity.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getModerationService()
   */
  public function getModerationService() {
    if (!isset($this->moderationService)) {
      $this->moderationService = ModerationServiceBase::getModerationService($this->bundle());
    }
    return $this->moderationService;
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

  /**
   * Get the original revision user.
   *
   * @see \Drupal\reliefweb_moderation\EntityModeratedInterface::getOriginalRevisionUser()
   */
  public function getOriginalRevisionUser() {
    if ($this instanceof RevisionLogInterface) {
      $key = $this->getEntityType()->getRevisionMetadataKey('revision_user');
      if (isset($this->values[$key][$this->activeLangcode])) {
        $uid = $this->values[$key][$this->activeLangcode];
        return \Drupal::entityTypeManager()
          ->getStorage('user')
          ->load($uid);
      }
    }
    return NULL;
  }

}
