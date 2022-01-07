<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Event\ImportAwareInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\RollbackAwareInterface;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\reliefweb_migrate\Plugin\migrate\id_map\AccumulatedSql;

/**
 * Base entity source plugin for reliefweb migrations.
 */
abstract class EntityBase extends SqlBase implements ImportAwareInterface, RollbackAwareInterface {

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
  protected $revisionIdField = 'revision_id';

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
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $iterator = parent::initializeIterator();

    if (!empty($this->batchSize)) {
      $this->preloadIdMapping($iterator);
    }

    return $iterator;
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
      $use_revision_id = $this->useRevisionId && !empty($this->revisionIdField);

      $iterator->rewind();

      $source_ids = $this->getIds();

      $source_id_hashes = [];
      foreach ($iterator as $record) {
        if ($use_revision_id) {
          $id = $record[$this->revisionIdField];
        }
        else {
          $id = $record[$this->idField];
        }

        $source_id_values = array_merge($source_ids, array_intersect_key($record, $source_ids));
        $source_id_hashes[$this->idMap->getSourceIdsHash($source_id_values)] = $id;
      }

      $preloaded = $this->idMap->preloadIdMapping(array_keys($source_id_hashes));
      foreach ($preloaded as $hash => $data) {
        $this->migratedEntities[$source_id_hashes[$hash]] = !empty($data);
      }

      $iterator->rewind();
    }
  }

  /**
   * Check if an entity was already migrated.
   *
   * @param int $id
   *   Entity id.
   *
   * @return bool
   *   TRUE if the entity already exists.
   */
  public function entityExists($id) {
    $exists = !empty($this->migratedEntities[$id]);
    unset($this->migratedEntities[$id]);
    return $exists;
  }

  /**
   * {@inheritdoc}
   */
  protected function getHighWater() {
    if (isset($this->storedHighWater)) {
      return $this->storedHighWater;
    }
    return parent::getHighWater();
  }

  /**
   * {@inheritdoc}
   */
  protected function saveHighWater($high_water) {
    // To avoid excessive database usage, we store the the high water and we'll
    // save it only after the import.
    $this->storedHighWater = $high_water;
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
    if ($this->idMap instanceof AccumulatedSql) {
      $this->idMap->flushAccumulatedIdMapping();
    }

    $this->doSaveHighWater();
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
    // Unset the hight water value after a rollback.
    $this->getHighWaterStorage()->set($this->migration->id(), NULL);
  }

}
