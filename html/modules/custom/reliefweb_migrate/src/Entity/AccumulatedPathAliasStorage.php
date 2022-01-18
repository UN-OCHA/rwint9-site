<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\path_alias\PathAliasStorage;

/**
 * Path Alias SQL storage for the migrations that regroup queries.
 */
class AccumulatedPathAliasStorage extends PathAliasStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

  /**
   * {@inheritdoc}
   */
  protected function accumulateEntityId(EntityInterface $entity) {
    $this->accumulatedEntityIds[$entity->uuid()] = $entity->uuid();
  }

  /**
   * {@inheritdoc}
   */
  protected function getExistingEntitiesDeletionCondition() {
    $ids = [];
    if (!empty($this->accumulatedEntityIds)) {
      $ids = $this->database
        ->select($this->baseTable, 't')
        ->fields('t', [$this->idKey])
        ->condition('t.uuid', $this->accumulatedEntityIds, 'IN')
        ->execute()
        ?->fetchAllKeyed(0, 0) ?? [];
    }

    return [
      'base_table_field' => $this->idKey,
      'field_table_field' => '',
      'revision_only' => FALSE,
      'ids' => $ids,
    ];
  }

}
