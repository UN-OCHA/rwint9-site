<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\migrate_plus\Plugin\migrate\destination\Table as TableBase;

/**
 * Table migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_table"
 * )
 */
class Table extends TableBase {

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

  /**
   * Delete the following IDs.
   *
   * @param array $ids
   *   Ids to delete.
   */
  public function deleteIds(array $ids) {
    if (!empty($ids)) {

      $this->dbConnection
        ->delete($this->tableName)
        ->where("CONCAT_WS('###'," . implode(',', array_keys($this->idFields)) . ") IN ('" . implode("','", $ids) . "')")
        ->execute();
    }
  }

}
