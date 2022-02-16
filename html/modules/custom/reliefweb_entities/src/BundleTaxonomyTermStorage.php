<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\Entity\EntityInterface;
use Drupal\taxonomy\TermStorage;

/**
 * Interface for bundle entities.
 */
class BundleTaxonomyTermStorage extends TermStorage implements BundleEntityStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    parent::save($entity);

    $this->invokeHook('after_save', $entity);
  }

}
