<?php

namespace Drupal\extended_field_description;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\extended_field_description\Entity\DescriptionEntityInterface;

/**
 * Defines the storage handler class for Description entity entities.
 *
 * This extends the base storage class, adding required special handling for
 * Description entity entities.
 *
 * @ingroup extended_field_description
 */
interface DescriptionEntityStorageInterface extends ContentEntityStorageInterface {

  /**
   * Gets a list of Description entity revision IDs for a specific Description entity.
   *
   * @param \Drupal\extended_field_description\Entity\DescriptionEntityInterface $entity
   *   The Description entity entity.
   *
   * @return int[]
   *   Description entity revision IDs (in ascending order).
   */
  public function revisionIds(DescriptionEntityInterface $entity);

  /**
   * Gets a list of revision IDs having a given user as Description entity author.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user entity.
   *
   * @return int[]
   *   Description entity revision IDs (in ascending order).
   */
  public function userRevisionIds(AccountInterface $account);

  /**
   * Counts the number of revisions in the default language.
   *
   * @param \Drupal\extended_field_description\Entity\DescriptionEntityInterface $entity
   *   The Description entity entity.
   *
   * @return int
   *   The number of revisions in the default language.
   */
  public function countDefaultLanguageRevisions(DescriptionEntityInterface $entity);

  /**
   * Unsets the language for all Description entity with the given language.
   *
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The language object.
   */
  public function clearRevisionsLanguage(LanguageInterface $language);

}
