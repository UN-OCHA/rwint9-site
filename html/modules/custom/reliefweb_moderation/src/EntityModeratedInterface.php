<?php

namespace Drupal\reliefweb_moderation;

/**
 * Interface for entities with a moderation status.
 */
interface EntityModeratedInterface {

  /**
   * Set the moderation status.
   *
   * @param string $status
   *   The moderation status.
   */
  public function setModerationStatus($status);

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

  /**
   * Get the list of allowed statuses for the enitity.
   *
   * @return array
   *   List of moderation statuses keyed by status and with labels as values.
   */
  public function getAllowedModerationStatuses();

  /**
   * Get the default moderation status.
   *
   * @return string
   *   The default moderation status.
   */
  public function getDefaultModerationStatus();

  /**
   * Get the moderation service for the entity.
   *
   * @return \Drupal\reliefweb_moderation\Services\ModerationServiceInterface|null
   *   The moderation service for the entity.
   */
  public function getModerationService();

  /**
   * Get the original revision log message.
   *
   * We need this function to be able to extract the revision log message
   * from the raw entity values because, for example, on an entity form page,
   * the entity revision log field is emptied as the entity is the "skeleton"
   * of the new entity revision to be created.
   *
   * @return string
   *   Moderation log message.
   */
  public function getOriginalRevisionLogMessage();

  /**
   * Get the type of the original revision log message.
   *
   * @return string
   *   Moderation log message type.
   */
  public function getOriginalRevisionLogMessageType();

}
