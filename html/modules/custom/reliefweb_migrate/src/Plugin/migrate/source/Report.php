<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;

/**
 * Retrieve report nodes from the Drupal 7 database.
 *
 * Available configuration keys:
 * - bundle: (optional) The node type (machine name) to filter nodes
 *   retrieved from the source - can be a string or an array. If omitted, all
 *   nodes are retrieved.
 *
 * @see \Drupal\node\Plugin\migrate\source\d7\Node
 *
 * @MigrateSource(
 *   id = "reliefweb_report"
 * )
 */
class Report extends Node {

  /**
   * Store the list of duplicate reports.
   *
   * @var array
   */
  protected $duplicateReports;

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();

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

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $iterator = parent::initializeIterator();

    if (!empty($this->preloadedFieldValues['report']['field_file'])) {
      // Extract information from the D7 field_file description field.
      $items = [];
      foreach ($this->preloadedFieldValues['report']['field_file'] as $entity_id => $field_items) {
        foreach ($field_items as $delta => $item) {
          if (isset($item['fid'])) {
            $data = $this->parseAttachmentDescription($item['description'] ?? '');
            $data['entity_id'] = $entity_id;
            $data['delta'] = $delta;
            $items[$item['fid']] = $data;
          }
        }
      }

      $attachments = [];
      if (!empty($items)) {
        // Load the data from the file_managed table.
        $records = $this->select('file_managed', 'fm')
          ->fields('fm')
          ->condition('fm.status', 1, '=')
          ->condition('fm.fid', array_keys($items), 'IN')
          ->execute()
          ?->fetchAll(\PDO::FETCH_OBJ) ?? [];

        // Prepare the D9 field_file data.
        $attachments = [];
        foreach ($records as $record) {
          if (empty($record->uri)) {
            continue;
          }
          if (strtolower(pathinfo($record->filename, PATHINFO_EXTENSION)) === 'pdf') {
            $record->filemime = 'application/pdf';
          }
          $item = $items[$record->fid];
          $item['uuid'] = LegacyHelper::generateAttachmentUuid($record->uri);
          $item['file_uuid'] = LegacyHelper::generateAttachmentFileUuid($item['uuid'], $record->fid);
          if (!empty($item['preview_page'])) {
            $item['preview_uuid'] = LegacyHelper::generateAttachmentPreviewUuid($item['uuid'], $item['file_uuid']);
          }
          $item['file_name'] = $record->filename;
          $item['file_mime'] = $record->filemime;
          $item['file_size'] = $record->filesize;
          // The revision ID will be updated when running the file migration
          // script.
          $item['revision_id'] = 0;
          $item['timestamp'] = $record->timestamp;

          $delta = $item['delta'];
          $entity_id = $item['entity_id'];
          unset($item['delta'], $item['entity_id']);

          $attachments[$entity_id][$delta] = $item;
        }
      }
      $this->preloadedFieldValues['report']['field_file'] = $attachments;
    }

    return $iterator;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

    $nid = $row->getSourceProperty('nid');

    // Generate the UUID based on the URI.
    $uuid = LegacyHelper::generateDocumentUuid($nid);

    // Set the node UUID.
    $row->setDestinationProperty('uuid', $uuid);

    // Set the attachments.
    $field_file = $row->getSourceProperty('field_file');
    if (!empty($field_file)) {
      $row->setDestinationProperty('field_file', array_values($field_file));
    }
  }

  /**
   * Parse an attachment's description.
   *
   * @param string $description
   *   File description.
   *
   * @return array
   *   Array containing the optional description, language, preview page and
   *   preview rotation.
   */
  protected function parseAttachmentDescription($description) {
    if (empty($description)) {
      return [];
    }

    $result = [];

    // Extract the preview page and rotation from the description.
    if (preg_match('/\|(?P<page>\d+)\|(?P<rotation>0|90|-90)$/', $description, $matches) === 1) {
      if ($matches['page'] > 0) {
        $result['preview_page'] = $matches['page'];
        $result['preview_rotation'] = $matches['rotation'];
      }
    }
    $description = preg_replace('/\|(\d+)\|(0|90|-90)$/', '', $description);

    // Extract the language from the description.
    if (!empty($description)) {
      $languages = reliefweb_docstore_get_languages();
      $versions = [];
      $version_pattern = [];
      foreach ($languages as $code => $label) {
        $version_pattern[] = preg_quote($label . ' version');
        $versions[strtolower($label . ' version')] = $code;
      }
      $version_pattern = implode('|', $version_pattern);
      if (!empty($version_pattern) && preg_match('/( - )?(?P<version>' . $version_pattern . ')$/i', $description, $matches) === 1) {
        $result['language'] = $versions[strtolower($matches['version'])];
        $description = mb_substr($description, 0, -mb_strlen($matches[0]));
      }
    }

    $result['description'] = $description;

    return $result;
  }

}
