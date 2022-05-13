<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeStorage;

/**
 * Interface for bundle entities.
 */
class BundleNodeStorage extends NodeStorage implements BundleEntityStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    parent::save($entity);

    $this->invokeHook('after_save', $entity);
  }

}
