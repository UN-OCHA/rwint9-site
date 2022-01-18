<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Query\Select;

/**
 * Trait to add a method to remove duplicate reports.
 */
trait SourceReportTrait {

  /**
   * Store the list of duplicate reports.
   *
   * @var array
   */
  protected $duplicateReports;

  /**
   * Add a condition to the give query to ignore duplicate reports.
   *
   * @param \Drupal\Core\Database\Query\Select $query
   *   Select query.
   */
  public function removeDuplicateReports(Select $query) {
    // Retrieve duplicate reports. We cannot easily identify all the duplicates
    // but those with attachments are easy enough to find and are the
    // problematic ones.
    if (!isset($this->duplicateReports)) {
      $duplicate_query = $this->select('field_data_field_file', 'f');
      $duplicate_query->addField('f', 'field_file_fid', 'fid');
      $duplicate_query->addExpression('COUNT(f.field_file_fid)', 'total');
      $duplicate_query->addExpression('GROUP_CONCAT(f.entity_id ORDER BY f.entity_id)', 'ids');
      $duplicate_query->groupBy('f.field_file_fid');
      $duplicate_query->having('total > 1');

      $this->duplicateReports = [];
      foreach ($duplicate_query->execute() ?? [] as $record) {
        $ids = explode(',', $record['ids']);
        if (min($ids) !== max($ids)) {
          $this->duplicateReports = array_merge($this->duplicateReports, array_slice($ids, 0, -1));
        }
      }
    }

    if (!empty($this->duplicateReports)) {
      $query->condition('n.nid', $this->duplicateReports, 'NOT IN');
    }
  }

}
