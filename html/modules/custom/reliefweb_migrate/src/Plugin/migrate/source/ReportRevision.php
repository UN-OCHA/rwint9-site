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
    return $query;
  }

}
