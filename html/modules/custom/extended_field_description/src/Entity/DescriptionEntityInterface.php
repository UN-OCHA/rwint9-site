<?php

namespace Drupal\extended_field_description\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Description entity entities.
 *
 * @ingroup extended_field_description
 */
interface DescriptionEntityInterface extends ContentEntityInterface, RevisionLogInterface, EntityChangedInterface, EntityPublishedInterface, EntityOwnerInterface {

  /**
   * Add get/set methods for your configuration properties here.
   */

  /**
   * Gets the Description entity name.
   *
   * @return string
   *   Name of the Description entity.
   */
  public function getName();

  /**
   * Sets the Description entity name.
   *
   * @param string $name
   *   The Description entity name.
   *
   * @return \Drupal\extended_field_description\Entity\DescriptionEntityInterface
   *   The called Description entity entity.
   */
  public function setName($name);

  /**
   * Gets the Description entity creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Description entity.
   */
  public function getCreatedTime();

  /**
   * Sets the Description entity creation timestamp.
   *
   * @param int $timestamp
   *   The Description entity creation timestamp.
   *
   * @return \Drupal\extended_field_description\Entity\DescriptionEntityInterface
   *   The called Description entity entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Gets the Description entity revision creation timestamp.
   *
   * @return int
   *   The UNIX timestamp of when this revision was created.
   */
  public function getRevisionCreationTime();

  /**
   * Sets the Description entity revision creation timestamp.
   *
   * @param int $timestamp
   *   The UNIX timestamp of when this revision was created.
   *
   * @return \Drupal\extended_field_description\Entity\DescriptionEntityInterface
   *   The called Description entity entity.
   */
  public function setRevisionCreationTime($timestamp);

  /**
   * Gets the Description entity revision author.
   *
   * @return \Drupal\user\UserInterface
   *   The user entity for the revision author.
   */
  public function getRevisionUser();

  /**
   * Sets the Description entity revision author.
   *
   * @param int $uid
   *   The user ID of the revision author.
   *
   * @return \Drupal\extended_field_description\Entity\DescriptionEntityInterface
   *   The called Description entity entity.
   */
  public function setRevisionUserId($uid);

}
