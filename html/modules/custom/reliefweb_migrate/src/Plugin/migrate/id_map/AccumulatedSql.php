<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\id_map;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateMapSaveEvent;
use Drupal\migrate\Plugin\migrate\id_map\Sql;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Plugin\migrate\source\EntityBase;

/**
 * Migration ID map class that accumulates data before inserting.
 */
class AccumulatedSql extends Sql {

  /**
   * Store the preloaded id mapping.
   *
   * @var array
   */
  protected $preloadedIdMapping = [];

  /**
   * Accumulate the data to save in the ID mapping.
   *
   * @var arrat
   */
  protected $accumulatedIdMapping = [];

  /**
   * {@inheritdoc}
   */
  public function getRowBySource(array $source_id_values) {
    $hash = $this->getSourceIdsHash($source_id_values);
    if (array_key_exists($hash, $this->preloadedIdMapping)) {
      return $this->preloadedIdMapping[$hash];
    }
    else {
      return parent::getRowBySource($source_id_values);
    }
  }

  /**
   * Preload source row data from the mapping table.
   *
   * @param array $ids
   *   Source IDs.
   *
   * @return array
   *   The preloaded ID mapping.
   */
  public function preloadIdMapping(array $ids) {
    if (!empty($ids)) {
      $data = $this->getDatabase()
        ->select($this->mapTableName(), 'map')
        ->fields('map')
        ->condition($this::SOURCE_IDS_HASH, $ids, 'IN')
        ->execute()
        ?->fetchAllAssoc($this::SOURCE_IDS_HASH, \PDO::FETCH_ASSOC) ?? [];

      $this->preloadedIdMapping = [];
      foreach ($ids as $id) {
        $this->preloadedIdMapping[$id] = $data[$id] ?? NULL;
      }
    }
    return $this->preloadedIdMapping;
  }

  /**
   * {@inheritdoc}
   */
  public function lookupDestinationIds(array $source_id_values) {
    if (empty($source_id_values)) {
      return [];
    }

    $hash = $this->getSourceIdsHash($source_id_values);
    if (array_key_exists($hash, $this->preloadedIdMapping)) {
      $data = $this->preloadedIdMapping[$hash];
      if (empty($data)) {
        return [];
      }

      $result = [];
      foreach ($this->destinationIdFields() as $field) {
        $result[] = isset($data[$field]) ? [$data[$field]] : [];
      }
      return $result;
    }
    else {
      return parent::lookupDestinationIds($source_id_values);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function saveIdMapping(Row $row, array $destination_id_values, $source_row_status = MigrateIdMapInterface::STATUS_IMPORTED, $rollback_action = MigrateIdMapInterface::ROLLBACK_DELETE) {

    if ($this->migration->getSourcePlugin() instanceof EntityBase) {
      $this->saveIdMappingAccumulated($row, $destination_id_values, $source_row_status, $rollback_action);
    }
    else {
      parent::saveIdMapping($row, $destination_id_values, $source_row_status, $rollback_action);
    }
  }

  /**
   * Save the accumulated mapping data.
   *
   * @see \Drupal\migrate\Plugin\MigrateIdMapInterface::saveIdMapping(),
   */
  protected function saveIdMappingAccumulated(Row $row, array $destination_id_values, $source_row_status = MigrateIdMapInterface::STATUS_IMPORTED, $rollback_action = MigrateIdMapInterface::ROLLBACK_DELETE) {
    // Construct the source key.
    $source_id_values = $row->getSourceIdValues();

    // Construct the source key and initialize to empty variable keys.
    $fields = [];
    foreach ($this->sourceIdFields() as $field_name => $key_name) {

      // A NULL key value is usually an indication of a problem.
      if (!isset($source_id_values[$field_name])) {
        $message = $this->t('Did not save to map table due to NULL value for key field @field', [
          '@field' => $field_name,
        ]);
        $this->message->display($message, 'error');
        return;
      }
      $fields[$key_name] = $source_id_values[$field_name];
    }
    if (!$fields) {
      return;
    }
    $fields += [
      'source_row_status' => (int) $source_row_status,
      'rollback_action' => (int) $rollback_action,
      'hash' => $row->getHash(),
    ];
    $count = 0;
    foreach ($destination_id_values as $dest_id) {
      $fields['destid' . ++$count] = $dest_id;
    }
    if ($count && $count != count($this->destinationIdFields())) {
      $message = $this->t('Could not save to map table due to missing destination id values');
      $this->message->display($message, 'error');
      return;
    }
    if ($this->migration->getTrackLastImported()) {
      $fields['last_imported'] = time();
    }
    $keys = [
      $this::SOURCE_IDS_HASH => $this->getSourceIdsHash($source_id_values),
    ];

    // Notify anyone listening of the map row we're about to save.
    $this->eventDispatcher->dispatch(new MigrateMapSaveEvent($this, $fields), MigrateEvents::MAP_SAVE);

    // Store the data to save in the map table.
    $this->accumulatedIdMapping[$keys[$this::SOURCE_IDS_HASH]] = $fields;

    // Save the mapping.
    if (count($this->accumulatedIdMapping) >= 1000) {
      $this->flushAccumulatedIdMapping();
    }
  }

  /**
   * Save the id mapping in the database.
   */
  public function flushAccumulatedIdMapping() {
    if (empty($this->accumulatedIdMapping)) {
      return;
    }

    $database = $this->getDatabase();
    $table = $this->mapTableName();
    $hash_field = $this::SOURCE_IDS_HASH;

    // Retrieve the list of rows to update.
    $existing = $database
      ->select($table, 'map')
      ->fields('map', [$hash_field])
      ->condition('map.' . $hash_field, array_keys($this->accumulatedIdMapping), 'IN')
      ->execute()
      ?->fetchCol() ?? [];
    $existing = array_flip($existing);

    $insertions = [];
    foreach ($this->accumulatedIdMapping as $hash => $fields) {
      // Update the data.
      if (isset($existing[$hash])) {
        $database
          ->update($table)
          ->fields($fields)
          ->condition($hash_field, $hash)
          ->execute();
      }
      else {
        $insertions[] = [$hash_field => $hash] + $fields;
      }
    }

    $count_fields = NULL;
    if (!empty($insertions)) {
      $query = $database->insert($table);
      foreach ($insertions as $values) {
        if (!isset($count_fields)) {
          $count_fields = count(array_keys($values));
        }
        $query->fields(array_keys($values));
        $query->values(array_values($values));
      }
      $query->execute();
    }

    $this->accumulatedIdMapping = [];
  }

}
