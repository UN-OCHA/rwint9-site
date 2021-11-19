<?php

namespace Drupal\guidelines;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
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
class GuidelineStorage extends SqlContentEntityStorage implements GuidelineStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function revisionIds(GuidelineInterface $entity) {
    return $this->database->query(
      'SELECT vid FROM {guideline_revision} WHERE id=:id ORDER BY vid',
      [':id' => $entity->id()]
    )->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function userRevisionIds(AccountInterface $account) {
    return $this->database->query(
      'SELECT vid FROM {guideline_field_revision} WHERE uid = :uid ORDER BY vid',
      [':uid' => $account->id()]
    )->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function countDefaultLanguageRevisions(GuidelineInterface $entity) {
    return $this->database->query('SELECT COUNT(*) FROM {guideline_field_revision} WHERE id = :id AND default_langcode = 1', [':id' => $entity->id()])
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function clearRevisionsLanguage(LanguageInterface $language) {
    return $this->database->update('guideline_revision')
      ->fields(['langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED])
      ->condition('langcode', $language->getId())
      ->execute();
  }

}
