<?php

namespace Drupal\extended_field_description;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
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
class DescriptionEntityStorage extends SqlContentEntityStorage implements DescriptionEntityStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function revisionIds(DescriptionEntityInterface $entity) {
    return $this->database->query(
      'SELECT vid FROM {description_entity_revision} WHERE id=:id ORDER BY vid',
      [':id' => $entity->id()]
    )->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function userRevisionIds(AccountInterface $account) {
    return $this->database->query(
      'SELECT vid FROM {description_entity_field_revision} WHERE uid = :uid ORDER BY vid',
      [':uid' => $account->id()]
    )->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function countDefaultLanguageRevisions(DescriptionEntityInterface $entity) {
    return $this->database->query('SELECT COUNT(*) FROM {description_entity_field_revision} WHERE id = :id AND default_langcode = 1', [':id' => $entity->id()])
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function clearRevisionsLanguage(LanguageInterface $language) {
    return $this->database->update('description_entity_revision')
      ->fields(['langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED])
      ->condition('langcode', $language->getId())
      ->execute();
  }

}
