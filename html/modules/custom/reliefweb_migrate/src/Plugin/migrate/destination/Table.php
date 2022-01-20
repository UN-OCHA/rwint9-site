<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate_plus\Plugin\migrate\destination\Table as TableBase;
use Drupal\reliefweb_migrate\Plugin\migrate\id_map\AccumulatedSql;

/**
 * Table migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_table"
 * )
 */
class Table extends TableBase {

  /**
   * {@inheritdoc}
   */
  public function preImport(MigrateImportEvent $event) {
    // Wipe out the table before inserting the new data. This is much faster
    // than trying to see what changed.
    $this->dbConnection->truncate($this->tableName)->execute();

    // Remove the migration mapping.
    $id_map = $this->migration->getIdMap();
    if ($id_map instanceof AccumulatedSql) {
      $id_map->deleteIdMapping();
    }
  }

  /**
   * Get the IDs from the destination table.
   *
   * @return array
   *   List of IDs from the destination table.
   */
  public function getDestinationIds() {
    return $this->dbConnection->select($this->tableName, 't')
      ->fields('t', array_keys($this->idFields))
      ->execute()
      ?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
  }

}
