<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate_plus\Plugin\migrate\source\Table as TableBase;

/**
 * Table migration source.
 *
 * @MigrateSource(
 *   id = "reliefweb_table"
 * )
 */
class Table extends TableBase implements SourceMigrationStatusInterface {

  /**
   * {@inheritdoc}
   */
  public function getMigrationStatus() {
    $source_ids = $this->select($this->tableName, 't')
      ->fields('t', array_keys($this->idFields))
      ->execute()
      ?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

    // We take a huge shortcut there assuming the destination plugin is a
    // Drupal\reliefweb_migrate\Plugin\migrate\destination\Table.
    // This works for RW because this source plugin is only used for the
    // bookmarks and subscriptions where the source and destination tables
    // have the same ID fields.
    $destination_ids = $this->migration
      ->getDestinationPlugin()
      ->getDestinationIds();

    $source_ids = array_map(function ($item) {
      return implode('###', $item);
    }, $source_ids);

    $destination_ids = array_map(function ($item) {
      return implode('###', $item);
    }, $destination_ids);

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

}
