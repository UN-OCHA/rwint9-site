<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\user\UserStorage;

/**
 * User SQL storage for the migrations that regroup queries.
 */
class AccumulatedUserStorage extends UserStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

  /**
   * {@inheritdoc}
   */
  protected function invokeHook($hook, EntityInterface $entity) {
    $blacklist = [
      'social_auth_hid_user_insert' => TRUE,
    ];

    $this->doInvokeHook($this->entityTypeId . '_' . $hook, $entity, $blacklist);
    $this->doInvokeHook('entity_' . $hook, $entity, $blacklist);
  }

}
