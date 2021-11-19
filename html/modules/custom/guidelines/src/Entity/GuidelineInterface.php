<?php

namespace Drupal\guidelines\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Guideline entities.
 *
 * @ingroup guidelines
 */
interface GuidelineInterface extends ContentEntityInterface, RevisionLogInterface, EntityChangedInterface, EntityPublishedInterface, EntityOwnerInterface {

  /**
   * Add get/set methods for your configuration properties here.
   */

  /**
   * Gets the Guideline name.
   *
   * @return string
   *   Name of the Guideline.
   */
  public function getName();

  /**
   * Sets the Guideline name.
   *
   * @param string $name
   *   The Guideline name.
   *
   * @return \Drupal\guidelines\Entity\GuidelineInterface
   *   The called Guideline entity.
   */
  public function setName($name);

  /**
   * Gets the Guideline creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Guideline.
   */
  public function getCreatedTime();

  /**
   * Sets the Guideline creation timestamp.
   *
   * @param int $timestamp
   *   The Guideline creation timestamp.
   *
   * @return \Drupal\guidelines\Entity\GuidelineInterface
   *   The called Guideline entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Gets the Guideline revision creation timestamp.
   *
   * @return int
   *   The UNIX timestamp of when this revision was created.
   */
  public function getRevisionCreationTime();

  /**
   * Sets the Guideline revision creation timestamp.
   *
   * @param int $timestamp
   *   The UNIX timestamp of when this revision was created.
   *
   * @return \Drupal\guidelines\Entity\GuidelineInterface
   *   The called Guideline entity.
   */
  public function setRevisionCreationTime($timestamp);

  /**
   * Gets the Guideline revision author.
   *
   * @return \Drupal\user\UserInterface
   *   The user entity for the revision author.
   */
  public function getRevisionUser();

  /**
   * Sets the Guideline revision author.
   *
   * @param int $uid
   *   The user ID of the revision author.
   *
   * @return \Drupal\guidelines\Entity\GuidelineInterface
   *   The called Guideline entity.
   */
  public function setRevisionUserId($uid);

}
