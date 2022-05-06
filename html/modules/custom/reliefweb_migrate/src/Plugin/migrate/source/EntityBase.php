<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Database;
use Drupal\migrate\Event\ImportAwareInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\RollbackAwareInterface;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Plugin\migrate\id_map\AccumulatedSql;

/**
 * Base entity source plugin for reliefweb migrations.
 */
abstract class EntityBase extends SqlBase implements ImportAwareInterface, RollbackAwareInterface, SourceMigrationHighWaterInterface, SourceMigrationStatusInterface {

  /**
   * Entity type.
   *
   * @var string
   */
  protected $entityType;

  /**
   * Id field.
   *
   * @var string
   */
  protected $idField;

  /**
   * Bundle field.
   *
   * @var string
   */
  protected $bundleField;

  /**
   * Revision id field.
   *
   * @var string
   */
  protected $revisionIdField;

  /**
   * Flag indicating whether to pass the revision id to get the fields.
   *
   * When TRUE, this will load the data from the field revision tables.
   *
   * @var bool
   */
  protected $useRevisionId = FALSE;

  /**
   * Stored high water mark property.
   *
   * @var mixed
   */
  protected $storedHighWater;

  /**
   * Map of already migrated content.
   *
   * @var array
   */
  protected $migratedEntities = [];

  /**
   * IDs of the entities to migrate.
   *
   * @var array
   */
  protected $idsToMigrate;

  /**
   * Store the list IDs being processed during the current iteration.
   *
   * @var array
   */
  protected $idsToProcess = [];

  /**
   * Initialize the batch size.
   */
  protected function initializeBatchSize() {
    if ($this->batchSize == 0 && isset($this->configuration['batch_size'])) {
      // Valid batch sizes are integers >= 0.
      if (is_int($this->configuration['batch_size']) && ($this->configuration['batch_size']) >= 0) {
        $this->batchSize = $this->configuration['batch_size'];
      }
      else {
        throw new MigrateException("batch_size must be greater than or equal to zero");
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $this->initializeBatchSize();
    if (empty($this->batchSize)) {
      return parent::initializeIterator();
    }

    // If a batch has run the query is already setup.
    // We also need to have a clean query if we use IDs to migrate because,
    // otherwise, the ID condition will be merged with the previous one...
    if ($this->batch == 0 || isset($this->idsToMigrate)) {
      $this->prepareQuery();
    }

    if (!empty($this->getHighWaterProperty())) {
      $high_water_field = $this->getHighWaterField();
      $high_water = $this->getHighWater();
      $this->idsToProcess = [];

      // Initialize the list of IDs to migrate.
      if (!isset($this->idsToMigrate)) {
        $this->idsToMigrate = $this->getIdsToMigrate();

        \Drupal::logger('migrate')->info(strtr('IDs to migrate: @ids', [
          '@ids' => count($this->idsToMigrate),
        ]));
      }

      // If there are IDs to migrate, then we go through the list.
      if (!empty($this->idsToMigrate)) {
        $ids = array_splice($this->idsToMigrate, 0, $this->batchSize);
        $this->idsToProcess = array_flip($ids);

        $this->query->condition($high_water_field, $ids, 'IN');
      }
      // Otherwise we check against the high water, which allows for example to
      // re-import existing content (for tests etc.) by changing the high water
      // mark manually.
      else {
        // We check against NULL because 0 is an acceptable value for the high
        // water mark.
        if ($high_water !== NULL) {
          $this->query->condition($high_water_field, $high_water, '>');
        }
      }
      // Always sort by the high water field, to ensure that the first run
      // (before we have a high water value) also has the results in a
      // consistent order.
      $this->query->orderBy($high_water_field);
      $this->query->range(0, $this->batchSize);
    }
    else {
      $this->query->range($this->batch * $this->batchSize, $this->batchSize);
    }

    // Wrap the query result in an iterator.
    $statement = $this->query->execute();
    $statement->setFetchMode(\PDO::FETCH_ASSOC);
    $iterator = new \IteratorIterator($statement);

    // Preload the ID mapping and the list of migrated entities for the results.
    $this->preloadIdMapping($iterator);
    $this->preloadExisting($iterator);

    // Rewind the iterator just in case.
    $iterator->rewind();

    return $iterator;
  }

  /**
   * {@inheritdoc}
   */
  public function next() {
    $this->currentSourceIds = NULL;
    $this->currentRow = NULL;

    // In order to find the next row we want to process, we ask the source
    // plugin for the next possible row.
    while (!isset($this->currentRow) && $this->getIterator()->valid()) {

      $row_data = $this->getIterator()->current() + $this->configuration;
      $row = new Row($row_data, $this->getIds());

      // Populate the source key for this row.
      $this->currentSourceIds = $row->getSourceIdValues();

      // Pick up the existing map row, if any, unless fetchNextRow() did it.
      if (!$this->mapRowAdded && ($id_map = $this->idMap->getRowBySource($this->currentSourceIds))) {
        $row->setIdMap($id_map);
      }

      // Clear any previous messages for this row before potentially adding
      // new ones.
      if (!empty($this->currentSourceIds)) {
        $this->idMap->delete($this->currentSourceIds, TRUE);
      }

      // Preparing the row gives source plugins the chance to skip.
      if ($this->prepareRow($row) !== FALSE) {
        // Check whether the row needs processing.
        // 1. This row has not been imported yet.
        // 2. Explicitly set to update.
        // 3. The row is newer than the current high-water mark.
        // 4. If no such property exists then try by checking the hash of the
        //    row.
        if (!$row->getIdMap() || $row->needsUpdate() || $this->aboveHighWater($row) || $this->rowChanged($row)) {
          $this->currentRow = $row->freezeSource();
        }

        // @todo This should probably be updated even when skipping the row.
        if (!empty($this->getHighWaterProperty())) {
          $this->saveHighWater($row->getSourceProperty($this->highWaterProperty['name']));
        }
      }

      $this->fetchNextRow();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function rowChanged(Row $row) {
    if (!empty($this->highWaterProperty['name'])) {
      $id = $row->getSourceProperty($this->highWaterProperty['name']);
      if (isset($this->idsToProcess[$id])) {
        return TRUE;
      }
    }
    return parent::rowChanged($row);
  }

  /**
   * Preload the ID mapping.
   *
   * @param \IteratorIterator $iterator
   *   The iterator over the records returned from the database query.
   */
  protected function preloadIdMapping(\IteratorIterator $iterator) {
    // Pre-compute the ID of the row to be created.
    if ($this->idMap instanceof AccumulatedSql && $iterator->count() > 0) {
      $iterator->rewind();

      $source_ids = $this->getIds();

      $source_id_hashes = [];
      foreach ($iterator as $record) {
        $source_id_values = array_merge($source_ids, array_intersect_key($record, $source_ids));
        $source_id_hashes[] = $this->idMap->getSourceIdsHash($source_id_values);
      }

      $this->idMap->preloadIdMapping($source_id_hashes);

      $iterator->rewind();
    }
  }

  /**
   * Check if any of the entities to load are already in the databse.
   *
   * @param \IteratorIterator $iterator
   *   The iterator over the records returned from the database query.
   */
  protected function preloadExisting(\IteratorIterator $iterator) {
    $ids = $this->getEntityIdsFromIterator($iterator);
    if (!empty($ids)) {
      foreach ($this->doPreloadExisting($ids) as $id) {
        $this->migratedEntities[$id] = TRUE;
      }
    }
  }

  /**
   * Get the list of existing entities in the database.
   *
   * @param array $ids
   *   List of entity IDs to check.
   *
   * @return array
   *   List of existing IDs.
   */
  abstract protected function doPreloadExisting(array $ids);

  /**
   * Get the destination database connection.
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection of the drupal 9 site.
   */
  protected function getDatabaseConnection() {
    return Database::getConnection('default', 'default');
  }

  /**
   * Get the entity ids from the iterator.
   *
   * @param \IteratorIterator $iterator
   *   The iterator over the records returned from the database query.
   *
   * @return array
   *   List of entity ids.
   */
  protected function getEntityIdsFromIterator(\IteratorIterator $iterator) {
    $ids = [];
    if ($iterator->count() > 0) {
      $iterator->rewind();

      $use_revision_id = $this->useRevisionId && !empty($this->revisionIdField);

      foreach ($iterator as $record) {
        if ($use_revision_id) {
          $ids[] = $record[$this->revisionIdField];
        }
        else {
          $ids[] = $record[$this->idField];
        }
      }

      $iterator->rewind();
    }

    return $ids;
  }

  /**
   * Check if an entity was already migrated.
   *
   * Note: we "consume" the entity in the migratedEntities array to release
   * some memory. This works because this method is only called in
   * Drupal\reliefweb_migrate\Plugin\migrate\destination\Entity::getEntity().
   *
   * @param int $id
   *   Entity id.
   *
   * @return bool
   *   TRUE if the entity already exists.
   */
  public function entityExists($id) {
    $exists = isset($this->migratedEntities[$id]);
    unset($this->migratedEntities[$id]);
    return $exists;
  }

  /**
   * {@inheritdoc}
   */
  protected function getHighWater() {
    if (!isset($this->storedHighWater)) {
      $this->storedHighWater = parent::getHighWater();
    }
    return $this->storedHighWater;
  }

  /**
   * {@inheritdoc}
   */
  protected function saveHighWater($high_water) {
    // To avoid excessive database usage, we store the high water and we'll
    // save it only after the import.
    if (!isset($this->storedHighWater) || $high_water > $this->storedHighWater) {
      $this->storedHighWater = $high_water;
    }
  }

  /**
   * Do save the hight water property.
   */
  protected function doSaveHighWater() {
    if (isset($this->storedHighWater)) {
      $this->getHighWaterStorage()->set($this->migration->id(), $this->storedHighWater);
      $this->storedHighWater = NULL;
    }
  }

  /**
   * Reset the high water.
   */
  public function resetHighWater() {
    $this->getHighWaterStorage()->set($this->migration->id(), NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function setHighWaterToLatestNonImported($check_only = FALSE) {
    $destination_ids = $this->getDestinationEntityIds();
    $source_ids = $this->getSourceEntityIds();
    $imported_ids = array_intersect($destination_ids, $source_ids);
    $updated_ids = array_diff_assoc($imported_ids, $source_ids);
    $new_ids = array_diff($source_ids, $imported_ids);
    $ids = array_keys($new_ids + $updated_ids);

    if ($check_only) {
      return empty($ids) ? 0 : min($ids);
    }

    if (!empty($ids)) {
      $this->getHighWaterStorage()->set($this->migration->id(), min($ids) - 1);
    }
    return $this->getHighWater();
  }

  /**
   * Remove the entities from the D9 site that don't exist in the D7 site.
   */
  protected function removeDeletedEntities() {
    $destination_plugin = $this->migration->getDestinationPlugin();

    $ids_list = $this->getDestinationEntityIds();
    if (empty($ids_list)) {
      return;
    }

    $count = 0;
    foreach (array_chunk($ids_list, 1000) as $ids) {
      $ids_to_delete = $this->getDestinationEntityIdsToDelete($ids);
      if (!empty($ids_to_delete)) {
        $count += count($ids_to_delete);
        foreach ($ids_to_delete as $id) {
          $destination_plugin->rollback([$id]);
        }
        $this->idMap->deleteFromSourceIds($ids_to_delete);
      }
    }

    \Drupal::logger('migrate')->info(strtr('IDs deleted: @ids', [
      '@ids' => $count,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function getMigrationStatus() {
    $destination_ids = $this->getDestinationEntityIds();
    $source_ids = $this->getSourceEntityIds();
    $imported_ids = array_intersect($destination_ids, $source_ids);

    $total = count($source_ids);
    $imported = count($destination_ids);
    $unchanged = count(array_intersect_assoc($source_ids, $destination_ids));
    $new = count(array_diff($source_ids, $imported_ids));
    $deleted = count(array_diff($destination_ids, $source_ids));
    $updated = count(array_diff_assoc($imported_ids, $source_ids));

    return [
      'total' => $total,
      'imported' => $imported,
      'unchanged' => $unchanged,
      'new' => $new,
      'deleted' => $deleted,
      'updated' => $updated,
    ];
  }

  /**
   * Get the list of entity IDs to migrate.
   *
   * @return array
   *   List of IDs (ex: revision IDs).
   */
  protected function getIdsToMigrate() {
    $destination_ids = $this->getDestinationEntityIds();
    $source_ids = $this->getSourceEntityIds();
    $imported_ids = array_intersect($destination_ids, $source_ids);
    $updated_ids = array_diff_assoc($imported_ids, $source_ids);
    $new_ids = array_diff($source_ids, $imported_ids);
    $ids = array_keys($new_ids + $updated_ids);
    sort($ids);
    return $ids;
  }

  /**
   * Get the list of source ids that can be imported.
   *
   * @return array
   *   Associative array keyed by revision ids if available, otherwise keyed by
   *   entity ids and with the entity ids as values.
   */
  protected function getSourceEntityIds() {
    $query = $this->query();

    // ID and revision fields.
    $id_fields = [$this->idField => TRUE];
    if (isset($this->revisionIdField)) {
      $id_fields[$this->revisionIdField] = TRUE;
    }

    // Get the fields used for grouping. We need to preserve them.
    $group_by = $query->getGroupBy();

    // Remove all the unnecessary fields.
    $fields = &$query->getFields();
    foreach ($fields as $alias => $field) {
      $table_and_field = $field['table'] . '.' . $field['field'];

      if (isset($id_fields[$field['field']])) {
        $id_fields[$field['field']] = $alias;
      }
      elseif (!isset($group_by[$alias]) && !isset($group_by[$table_and_field])) {
        unset($fields[$alias]);
      }
    }

    // No need to sort.
    $order = &$query->getOrderBy();
    $order = [];

    $records = $query->execute() ?? [];

    $ids = [];
    foreach ($records as $record) {
      $id = $record[$this->idField];
      if (isset($this->revisionIdField)) {
        $revision_id = $record[$this->revisionIdField];
        if (empty($this->useRevisionId)) {
          $ids[$revision_id] = $id;
        }
        else {
          $ids[$revision_id] = $revision_id;
        }
      }
      else {
        $ids[$id] = $id;
      }
    }

    return $ids;
  }

  /**
   * Get the entity ids from the offset and limit.
   *
   * @return array
   *   Destination entity ids.
   */
  abstract protected function getDestinationEntityIds();

  /**
   * Get the entity ids from the offset and limit.
   *
   * @param array $ids
   *   Entity ids.
   *
   * @return array
   *   Entity ids to delete.
   */
  abstract protected function getDestinationEntityIdsToDelete(array $ids);

  /**
   * Get the batch size.
   *
   * @return int
   *   Batch size.
   */
  public function getBatchSize() {
    return $this->batchSize;
  }

  /**
   * {@inheritdoc}
   */
  public function preImport(MigrateImportEvent $event) {
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    // Just in case, ensure the destination postImport is run before the source
    // one so that the database has all the entity data.
    $destination_plugin = $this->migration->getDestinationPlugin();
    if ($destination_plugin instanceof ImportAwareInterface) {
      $destination_plugin->postImport($event);
    }

    if ($this->idMap instanceof AccumulatedSql) {
      $this->idMap->flushAccumulatedIdMapping();
    }

    $this->doSaveHighWater();

    $this->removeDeletedEntities();
  }

  /**
   * {@inheritdoc}
   */
  public function preRollback(MigrateRollbackEvent $event) {
  }

  /**
   * {@inheritdoc}
   */
  public function postRollback(MigrateRollbackEvent $event) {
    // Unset the high water value after a rollback.
    $this->resetHighWater();
  }

}
