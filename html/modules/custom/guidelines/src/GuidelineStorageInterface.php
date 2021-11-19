<?php

namespace Drupal\guidelines;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\guidelines\Entity\GuidelineInterface;

/**
 * Defines the storage handler class for Guideline entities.
 *
 * This extends the base storage class, adding required special handling for
 * Guideline entities.
 *
 * @ingroup guidelines
 */
interface GuidelineStorageInterface extends ContentEntityStorageInterface {

  /**
   * Gets a list of Guideline revision IDs for a specific Guideline.
   *
   * @param \Drupal\guidelines\Entity\GuidelineInterface $entity
   *   The Guideline entity.
   *
   * @return int[]
   *   Guideline revision IDs (in ascending order).
   */
  public function revisionIds(GuidelineInterface $entity);

  /**
   * Gets a list of revision IDs having a given user as Guideline author.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user entity.
   *
   * @return int[]
   *   Guideline revision IDs (in ascending order).
   */
  public function userRevisionIds(AccountInterface $account);

  /**
   * Counts the number of revisions in the default language.
   *
   * @param \Drupal\guidelines\Entity\GuidelineInterface $entity
   *   The Guideline entity.
   *
   * @return int
   *   The number of revisions in the default language.
   */
  public function countDefaultLanguageRevisions(GuidelineInterface $entity);

  /**
   * Unsets the language for all Guideline with the given language.
   *
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The language object.
   */
  public function clearRevisionsLanguage(LanguageInterface $language);

}
