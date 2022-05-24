<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

/**
 * Retrieve report node revisions from the Drupal 7 database.
 *
 * Available configuration keys:
 * - bundle: (optional) The node type (machine name) to filter nodes
 *   retrieved from the source - can be a string or an array. If omitted, all
 *   nodes are retrieved.
 *
 * @see \Drupal\node\Plugin\migrate\source\d7\NodeRevision
 *
 * @MigrateSource(
 *   id = "reliefweb_report_revision"
 * )
 */
class ReportRevision extends NodeRevision {

  use SourceReportTrait;

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $this->removeDuplicateReports($query);
    $query->innerJoin('field_data_field_status', 'fs', 'fs.entity_id = n.nid');
    $query->condition('fs.entity_type', 'node', '=');
    $query->condition('fs.field_status_value', 'on-hold', '<>');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIdsToDelete(array $ids) {
    if (!empty($ids)) {
      $query = $this->select('node_revision', 'nr')
        ->fields('nr', ['vid'])
        ->condition('nr.vid', $ids, 'IN');

      $query->innerJoin('field_data_field_status', 'fs', 'fs.entity_id = nr.nid');
      $query->condition('fs.entity_type', 'node', '=');
      $query->condition('fs.field_status_value', 'on-hold', '<>');

      $source_ids = $query->execute()
        ?->fetchCol() ?? [];

      return array_diff($ids, $source_ids);
    }
    return [];
  }

}
