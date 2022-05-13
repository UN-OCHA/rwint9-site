<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Plugin\migrate\id_map\AccumulatedSql;

/**
 * Retrieve url aliases from the Drupal 7 database.
 *
 * @MigrateSource(
 *   id = "reliefweb_url_alias"
 * )
 */
class UrlAlias extends EntityBase {

  /**
   * {@inheritdoc}
   */
  protected $idField = 'pid';

  /**
   * Store the source entity IDs.
   *
   * @var array
   */
  protected $sourceEntityIds;

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

      // @see ::query() for the condition field.
      $this->query->condition('ua.pid', $ids, 'IN');
    }
    else {
      $this->idsToProcess = [];
      $this->query->alwaysFalse();
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
  protected function rowChanged(Row $row) {
    $id = $row->getSourceProperty('pid');
    if (isset($this->idsToProcess[$id])) {
      return TRUE;
    }
    return parent::rowChanged($row);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('url_alias', 'ua');
    $query->fields('ua', ['pid', 'source', 'alias']);
    $query->orderBy('ua.pid', 'ASC');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function doCount() {
    return count($this->getSourceEntityIds());
  }

  /**
   * {@inheritdoc}
   */
  protected function doPreloadExisting(array $ids) {
    if (!empty($ids)) {
      return $this->getDatabaseConnection()
        ->select('redirect', 'r')
        ->fields('r', ['rid'])
        ->condition('r.rid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? [];
    }
    return [];
  }

  /**
   * Get the list of source ids that can be imported.
   *
   * @return array
   *   Associative array keyed by revision ids if available, otherwise keyed by
   *   entity ids and with the entity ids as values.
   */
  protected function getSourceEntityIds() {
    // The query to get the url aliases is slow so we store the result.
    if (isset($this->sourceEntityIds)) {
      return $this->sourceEntityIds;
    }

    $subquery = $this->select('url_alias', 'ua2');
    $subquery->addField('ua2', 'source', 'source');
    $subquery->addExpression('MAX(ua2.pid)', 'pid');
    $subquery->groupBy('ua2.source');
    $subquery->having('COUNT(ua2.pid) > 1');

    $query = $this->select('url_alias', 'ua');
    $query->fields('ua', ['pid', 'source', 'alias']);
    $query->innerJoin($subquery, 'sq', 'ua.source  = %alias.source AND ua.pid <> %alias.pid');
    $query->orderBy('ua.pid', 'ASC');
    $query->groupBy('ua.alias');

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

    $this->sourceEntityIds = $ids;

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIds() {
    return $this->getDatabaseConnection()
      ->select('redirect', 'r')
      ->fields('r', ['rid'])
      ->orderBy('r.rid', 'ASC')
      ->execute()
      ?->fetchAllKeyed(0, 0) ?? [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIdsToDelete(array $ids) {
    // Not used. See ::removeDeletedEntities().
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function removeDeletedEntities() {
    $source_ids = $this->getSourceEntityIds();
    $destination_ids = $this->getDestinationEntityIds();

    $deleted_ids = array_diff($destination_ids, $source_ids);
    if (empty($deleted_ids)) {
      return;
    }

    $destination_plugin = $this->migration->getDestinationPlugin();
    $delete_from_id_map = $this->idMap instanceof AccumulatedSql;

    foreach (array_chunk($deleted_ids, 1000) as $ids) {
      foreach ($ids as $id) {
        $destination_plugin->rollback([$id]);
      }
      if ($delete_from_id_map) {
        $this->idMap->deleteFromSourceIds($ids);
      }
    }

    \Drupal::logger('migrate')->info(strtr('IDs deleted: @ids', [
      '@ids' => count($deleted_ids),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

    $source = $row->getSourceProperty('source');
    if (strpos($source, 'node/') === 0) {
      $row->setSourceProperty('source', 'entity:' . $source);
    }
    elseif (strpos($source, 'taxonomy/term/') === 0) {
      $source = str_replace('taxonomy/term', 'taxonomy_term', $source);
      $row->setSourceProperty('source', 'entity:' . $source);
    }
    else {
      $row->setSourceProperty('source', 'internal:/' . $source);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'pid' => $this->t('The numeric identifier of the path alias.'),
      'source' => $this->t('The internal system path.'),
      'alias' => $this->t('The path alias.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['pid']['type'] = 'integer';
    $ids['pid']['alias'] = 'ua';
    return $ids;
  }

}
