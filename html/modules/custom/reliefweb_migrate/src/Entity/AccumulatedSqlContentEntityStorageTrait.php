<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * SQL storage for the migrations that regroup queries.
 */
trait AccumulatedSqlContentEntityStorageTrait {

  /**
   * Accumulator of the data to save.
   *
   * @var array
   */
  protected $accumulator = [];

  /**
   * Counter to determine when to flush the accumulated insert queries.
   *
   * @var int
   */
  protected $accumulationCounter = 0;

  /**
   * Accumalator of the tags to invalidate when flushing.
   *
   * @var array
   */
  protected $accumulatedTagsToInvalidate = [];

  /**
   * Accumulate the data to save the entity permanently.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to save.
   * @param int $max
   *   Maximum number of entities to store before flushing.
   *
   * @see Drupal\Core\Entity\ContentEntityStorageBase::save()
   */
  public function saveAccumulated(EntityInterface $entity, $max = 1000) {
    try {
      // Track if this entity is new.
      $is_new = $entity->isNew();

      // Execute presave logic and invoke the related hooks.
      $id = $this->doPreSave($entity);

      // Perform the save and reset the static cache for the changed entity.
      $return = $this->doSaveAccumulated($id, $entity);

      // Execute post save logic and invoke the related hooks.
      $this->doPostSaveAccumulated($entity, !$is_new);

      // Flush the accumulated data.
      if ($this->accumulationCounter++ > $max) {
        $this->flushAccumulated();
      }
    }
    catch (\Exception $exception) {
      // DEBUG.
      print_r($exception->getTraceAsString());
      throw $exception;
    }

    return $return;
  }

  /**
   * Perform post save tasks.
   *
   * Note: this regroups the tasks that are useful for the migration,
   * after analyzing the different content entity postSave methods.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity that was saved.
   * @param bool $update
   *   Wether the entity was updated or not.
   */
  protected function doPostSaveAccumulated(EntityInterface $entity, $update) {
    $this->resetCache([$entity->id()]);

    // The entity is no longer new.
    $entity->enforceIsNew(FALSE);

    // Allow code to run after saving.
    // Note: we removed the calls to the $entity::postSave().
    $this->invokeHook($update ? 'update' : 'insert', $entity);

    // After saving, this is now the "original entity", and subsequent saves
    // will be updates instead of inserts, and updates must always be able to
    // correctly identify the original entity.
    $entity->setOriginalId($entity->id());
    unset($entity->original);

    // The revision is stored, it should no longer be marked as new now.
    if ($this->entityType->isRevisionable()) {
      $entity->updateLoadedRevisionId();
      $entity->setNewRevision(FALSE);
    }

    // Accumulate the tags to invalidate.
    $entity_type = $this->getEntityType();
    $tags = $entity_type->getListCacheTags();
    if ($entity_type->hasKey('bundle')) {
      $tags[] = $entity_type->id() . '_list:' . $entity->bundle();
    }
    if ($update) {
      $tags = Cache::mergeTags($tags, $entity->getCacheTagsToInvalidate());
    }
    $this->accumulatedTagsToInvalidate = Cache::mergeTags($this->accumulatedTagsToInvalidate, $tags);
  }

  /**
   * Save the accumulated table data in the database.
   */
  public function flushAccumulated() {
    // Insert the accumulated data.
    $this->doFlushAccumulated();

    // Invalidate the entity tags and reset the accumulator.
    Cache::invalidateTags($this->accumulatedTagsToInvalidate);
    $this->accumulatedTagsToInvalidate = [];

    // Reset the accumulator.
    $this->accumulator = [];

    // Reset the accumulation counter.
    $this->accumulationCounter = 0;
  }

  /**
   * Do save the accumulated table data in the database.
   */
  public function doFlushAccumulated() {
    $transaction = $this->database->startTransaction();
    try {
      foreach ($this->accumulator as $table => $entries) {
        $query = $this->database->insert($table);
        foreach ($entries as $rows) {
          foreach ($rows as $row) {
            $row = (array) $row;
            $query->fields(array_keys($row));
            $query->values(array_values($row));
          }
        }
        $query->execute();
      }

      // Ignore replica server temporarily.
      \Drupal::service('database.replica_kill_switch')->trigger();
    }
    catch (\Exception $exception) {
      $transaction->rollBack();
      watchdog_exception($this->entityTypeId, $exception);
      throw new EntityStorageException($exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * Accumulate the data to save in the entity and field tables.
   *
   * @see Drupal\Core\Entity\ContentEntityStorageBase::doSave()
   */
  protected function doSaveAccumulated($id, EntityInterface $entity) {
    if ($entity->isNew()) {
      $entity->enforceIsNew();
      if ($this->entityType->isRevisionable()) {
        $entity->setNewRevision();
      }
      $return = SAVED_NEW;
    }
    else {
      $return = $entity->isDefaultRevision() ? SAVED_UPDATED : FALSE;
    }

    // Populate the "revision_default" flag. We skip this when we are resaving
    // the revision because this is only allowed for default revisions, and
    // these cannot be made non-default.
    if ($this->entityType->isRevisionable() && $entity->isNewRevision()) {
      $revision_default_key = $this->entityType->getRevisionMetadataKey('revision_default');
      $entity->set($revision_default_key, $entity->isDefaultRevision());
    }
    $this->doSaveFieldItemsAccumulated($entity);
    return $return;
  }

  /**
   * Accumulate the data to save in the entity and field tables.
   *
   * @see Drupal\Core\Entity\Sql\SqlContentEntityStorage::doSaveFieldItems()
   */
  protected function doSaveFieldItemsAccumulated(ContentEntityInterface $entity, array $names = []) {
    $default_revision = $entity->isDefaultRevision();

    if ($default_revision) {
      $record = $this->mapToStorageRecord($entity, $this->baseTable);
      $this->accumulator[$this->baseTable][] = [$record];
    }

    if ($this->revisionTable) {
      $this->saveRevisionAccumulated($entity);
    }

    if ($default_revision && $this->dataTable) {
      $this->saveToSharedTablesAccumulated($entity, $this->dataTable, TRUE);
    }

    if ($this->revisionDataTable) {
      $this->saveToSharedTablesAccumulated($entity, $this->revisionDataTable, TRUE);
    }

    // Update dedicated table records if necessary.
    $this->saveToDedicatedTablesAccumulated($entity, FALSE);
  }

  /**
   * Accumulate the data to save in the revision table.
   *
   * @see Drupal\Core\Entity\Sql\SqlContentEntityStorage::saveRevision()
   */
  protected function saveRevisionAccumulated(ContentEntityInterface $entity) {
    $record = $this->mapToStorageRecord($entity, $this->revisionTable);

    $entity->preSaveRevision($this, $record);

    $this->accumulator[$this->revisionTable][] = [$record];

    return $entity->getRevisionId();
  }

  /**
   * Accumulate the data to save for the shared tables.
   *
   * @see Drupal\Core\Entity\Sql\SqlContentEntityStorage::saveToSharedTable()
   */
  protected function saveToSharedTablesAccumulated(ContentEntityInterface $entity, $table_name = NULL, $new_revision = NULL) {
    $record = $this->mapToDataStorageRecord($entity, $table_name);
    $this->accumulator[$table_name][] = [$record];
  }

  /**
   * Accumulate the data to save for the fields that use dedicated tables.
   *
   * @see Drupal\Core\Entity\Sql\SqlContentEntityStorage::saveToDedicatedTables()
   */
  protected function saveToDedicatedTablesAccumulated(ContentEntityInterface $entity, $update = TRUE, array $names = []) {
    $id = $entity->id();
    $revision_id = $entity->getRevisionId() ?? $id;
    $bundle = $entity->bundle();
    $entity_type = $entity->getEntityTypeId();
    $langcode = $entity->getUntranslated()->language()->getId();
    $table_mapping = $this->getTableMapping();
    $default_revision = $entity->isDefaultRevision();

    // Determine which fields should be actually stored.
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    foreach ($definitions as $field_name => $field_definition) {
      $storage_definition = $field_definition->getFieldStorageDefinition();
      if (!$table_mapping->requiresDedicatedTableStorage($storage_definition)) {
        continue;
      }

      $table_name = $table_mapping->getDedicatedDataTableName($storage_definition);
      $revision_name = $table_mapping->getDedicatedRevisionTableName($storage_definition);

      // Accumulate the field values to store.
      $delta_count = 0;
      $items = $entity->get($field_name);
      $items->filterEmptyItems();
      foreach ($items as $delta => $item) {
        $record = [
          'entity_id' => $id,
          'revision_id' => $revision_id,
          'bundle' => $bundle,
          'delta' => $delta,
          'langcode' => $langcode,
        ];

        foreach ($storage_definition->getColumns() as $column => $attributes) {
          $column_name = $table_mapping->getFieldColumnName($storage_definition, $column);
          // Serialize the value if specified in the column schema.
          $value = $item->$column;
          if (!empty($attributes['serialize'])) {
            $value = serialize($value);
          }
          $record[$column_name] = SqlContentEntityStorageSchema::castValue($attributes, $value);
        }

        if ($default_revision) {
          $this->accumulator[$table_name][$revision_id][$delta] = $record;
        }
        if ($this->entityType->isRevisionable()) {
          $this->accumulator[$revision_name][$revision_id][$delta] = $record;
        }

        if ($storage_definition->getCardinality() != FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && ++$delta_count == $storage_definition->getCardinality()) {
          break;
        }
      }
    }
  }

  /**
   * Invoke entity hooks that are not blacklisted.
   *
   * @param string $hook
   *   Hook.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to pass to the hook implementations.
   * @param array $blacklist
   *   List of implementations to ignore.
   */
  protected function doInvokeHook($hook, EntityInterface $entity, array $blacklist = []) {
    $return = [];
    $implementations = $this->moduleHandler()->getImplementations($hook);
    foreach ($implementations as $module) {
      $function = $module . '_' . $hook;
      if (isset($blacklist[$function])) {
        continue;
      }
      $result = call_user_func_array($function, [$entity]);
      if (isset($result) && is_array($result)) {
        $return = NestedArray::mergeDeep($return, $result);
      }
      elseif (isset($result)) {
        $return[] = $result;
      }
    }
    return $return;
  }

}
