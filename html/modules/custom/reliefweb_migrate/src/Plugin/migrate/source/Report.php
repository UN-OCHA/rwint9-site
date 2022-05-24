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

  use SourceReportTrait;

  /**
   * Preloaded attachment data.
   *
   * @var array
   */
  protected $preloadedAttachments = [];

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
  protected function initializeIterator() {
    $iterator = parent::initializeIterator();
    $this->convertFileFieldData();
    return $iterator;
  }

  /**
   * Convert the field file data.
   */
  protected function convertFileFieldData() {
    // Skip if empty.
    if (empty($this->preloadedFieldValues['report']['field_file'])) {
      return;
    }

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

    if (!empty($items)) {
      // Load the data from the file_managed table.
      $records = $this->select('file_managed', 'fm')
        ->fields('fm')
        ->condition('fm.status', 1, '=')
        ->condition('fm.fid', array_keys($items), 'IN')
        ->execute()
        ?->fetchAll(\PDO::FETCH_OBJ) ?? [];

      // Prepare the D9 field_file data.
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
        if (!empty($item['preview_page']) && $record->filemime = 'application/pdf') {
          $item['preview_uuid'] = LegacyHelper::generateAttachmentPreviewUuid($item['uuid'], $item['file_uuid']);
        }
        else {
          unset($item['preview_page']);
          unset($item['preview_rotation']);
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

        $this->preloadedAttachments[$entity_id][$delta] = $item;
      }
      // Sort by delta (key).
      if (!empty($this->preloadedAttachments[$entity_id])) {
        ksort($this->preloadedAttachments[$entity_id]);
      }
    }
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
    // @todo handle cases where the batch_size is empty?
    if (!empty($this->preloadedAttachments[$nid])) {
      $row->setDestinationProperty('field_file', array_values($this->preloadedAttachments[$nid]));
      unset($this->preloadedAttachments[$nid]);
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
      $languages = reliefweb_files_get_languages();
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

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIdsToDelete(array $ids) {
    if (!empty($ids)) {
      $query = $this->select('node', 'n')
        ->fields('n', ['nid'])
        ->condition('n.nid', $ids, 'IN');

      $query->innerJoin('field_data_field_status', 'fs', 'fs.entity_id = n.nid');
      $query->condition('fs.entity_type', 'node', '=');
      $query->condition('fs.field_status_value', 'on-hold', '<>');

      $source_ids = $query->execute()
        ?->fetchCol() ?? [];

      return array_diff($ids, $source_ids);
    }
    return [];
  }

}
