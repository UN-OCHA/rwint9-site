<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Event\ImportAwareInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Row;
use Drupal\migrate_plus\Plugin\migrate\source\Table as TableBase;
use Drupal\reliefweb_migrate\Plugin\migrate\id_map\AccumulatedSql;

/**
 * Table migration source.
 *
 * @MigrateSource(
 *   id = "reliefweb_table"
 * )
 */
class Table extends TableBase implements SourceMigrationStatusInterface, ImportAwareInterface {

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

      $this->query->where("CONCAT_WS('###'," . implode(',', array_keys($this->idFields)) . ") IN ('" . implode("','", $ids) . "')");
    }
    else {
      $this->idsToProcess = [];
      $this->query->alwaysFalse();
    }

    // Wrap the query result in an iterator.
    $statement = $this->query->execute();
    $statement->setFetchMode(\PDO::FETCH_ASSOC);
    $iterator = new \IteratorIterator($statement);

    return $iterator;
  }

  /**
   * Get the list of entity IDs to migrate.
   *
   * @return array
   *   List of IDs (ex: revision IDs).
   */
  protected function getIdsToMigrate() {
    $source_ids = $this->getSourceIds();
    $destination_ids = $this->getDestinationIds();

    return array_diff($source_ids, $destination_ids);
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
      }

      $this->fetchNextRow();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function rowChanged(Row $row) {
    $ids = [];
    foreach (array_keys($this->idFields) as $id_field) {
      $ids[] = $row->getSourceProperty($id_field);
    }
    $id = implode('###', $ids);

    if (isset($this->idsToProcess[$id])) {
      return TRUE;
    }
    return parent::rowChanged($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getMigrationStatus() {
    $source_ids = $this->getSourceIds();
    $destination_ids = $this->getDestinationIds();

    $total = count($source_ids);
    $imported = count($destination_ids);
    $unchanged = count(array_intersect($source_ids, $destination_ids));
    $new = count(array_diff($source_ids, $destination_ids));
    $deleted = count(array_diff($destination_ids, $source_ids));
    // There is no notion of update in the case of bookmarks and subscriptions.
    $updated = 0;

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
   * Remove the entities from the D9 site that don't exist in the D7 site.
   */
  protected function removeDeletedEntities() {
    $source_ids = $this->getSourceIds();
    $destination_ids = $this->getDestinationIds();

    $deleted_ids = array_diff($destination_ids, $source_ids);
    if (empty($deleted_ids)) {
      return;
    }

    $destination_plugin = $this->migration->getDestinationPlugin();
    $delete_from_id_map = $this->idMap instanceof AccumulatedSql;

    foreach (array_chunk($deleted_ids, 1000) as $ids) {
      $destination_plugin->deleteIds($ids);
      if ($delete_from_id_map) {
        $this->idMap->deleteFromSourceIds($ids);
      }
    }

    \Drupal::logger('migrate')->info(strtr('IDs deleted: @ids', [
      '@ids' => count($deleted_ids),
    ]));
  }

  /**
   * Get the full list of source IDs.
   *
   * @return array
   *   Source IDs.
   */
  protected function getSourceIds() {
    $source_ids = $this->select($this->tableName, 't')
      ->fields('t', array_keys($this->idFields))
      ->execute()
      ?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

    return array_map(function ($item) {
      return implode('###', $item);
    }, $source_ids);
  }

  /**
   * Get the full list of destination IDs.
   *
   * @return array
   *   Destination IDs.
   */
  protected function getDestinationIds() {
    // We take a huge shortcut there assuming the destination plugin is a
    // Drupal\reliefweb_migrate\Plugin\migrate\destination\Table.
    // This works for RW because this source plugin is only used for the
    // bookmarks and subscriptions where the source and destination tables
    // have the same ID fields.
    $destination_ids = $this->migration
      ->getDestinationPlugin()
      ->getDestinationIds();

    return array_map(function ($item) {
      return implode('###', $item);
    }, $destination_ids);
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

    $this->removeDeletedEntities();
  }

}
