<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_entities\BundleTaxonomyTermStorage;

/**
 * Term SQL storage for the migrations that regroup queries.
 */
class AccumulatedBundleTaxonomyTermStorage extends BundleTaxonomyTermStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

  /**
   * {@inheritdoc}
   */
  protected function invokeHook($hook, EntityInterface $entity) {
    $blacklist = [
      'taxonomy_term_revision_entity_presave' => TRUE,
    ];

    $this->doInvokeHook($this->entityTypeId . '_' . $hook, $entity, $blacklist);
    $this->doInvokeHook('entity_' . $hook, $entity, $blacklist);
  }

}
