<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;

/**
 * Retrieve nodes from the Drupal 7 database.
 *
 * Available configuration keys:
 * - bundle: (optional) The node type (machine name) to filter nodes
 *   retrieved from the source - can be a string or an array. If omitted, all
 *   nodes are retrieved.
 *
 * @see \Drupal\node\Plugin\migrate\source\d7\Node
 *
 * @MigrateSource(
 *   id = "reliefweb_node"
 * )
 */
class Node extends FieldableEntityBase {

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'node';

  /**
   * {@inheritdoc}
   */
  protected $idField = 'nid';

  /**
   * {@inheritdoc}
   */
  protected $bundleField = 'type';

  /**
   * {@inheritdoc}
   */
  protected $useRevisionId = FALSE;

  /**
   * Join condition for the taxonomy_term_data table.
   *
   * @var string
   */
  protected $join = 'n.vid = nr.vid';

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('node_revision', 'nr');

    // Join the data table.
    $query->innerJoin('node', 'n', $this->join);

    // Base fields.
    $query->addField('n', 'nid', 'nid');
    $query->addField('n', 'type', 'type');
    $query->addField('n', 'title', 'title');
    $query->addField('n', 'uid', 'uid');
    $query->addField('n', 'created', 'created');
    $query->addField('n', 'changed', 'changed');
    $query->addField('n', 'status', 'status');

    // Revision fields.
    $query->addField('nr', 'vid', 'revision_id');
    $query->addField('nr', 'log', 'revision_log_message');
    $query->addField('nr', 'uid', 'revision_user');
    $query->addField('nr', 'timestamp', 'revision_created');
    $query->addExpression('IF(nr.vid = n.vid, 1, 0)', 'revision_default');

    // Restrict to the given bundles.
    if (isset($this->configuration['bundle'])) {
      $query->condition('n.type', (array) $this->configuration['bundle'], 'IN');
    }

    // Flag to limit the migration to the 1000 most recent nodes.
    if (\Drupal::state()->get('reliefweb_migrate.restrict', FALSE) === TRUE) {
      $query->range(0, 1000);
      $query->orderBy('n.nid', 'DESC');
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

    $bundle = $row->getSourceProperty('type');
    if ($bundle === 'report') {
      $nid = $row->getSourceProperty('nid');

      // Generate the UUID based on the URI.
      $uuid = LegacyHelper::generateDocumentUuid($nid);

      // Set the node UUID.
      $row->setDestinationProperty('uuid', $uuid);

      // Prepare the file data to be added to the created/updated node.
      $this->prepareAttachments($row);
    }
  }

  /**
   * Prepare the attachments to add to a report node.
   *
   * @param \Drupal\migrate\Row $row
   *   Migration row.
   */
  protected function prepareAttachments(Row $row) {
    // Skip if it's a revision as we cannot migrate file revisions because tere
    // is no usable data anymore and in theory, the editorial workflow is to
    // delete previous files when uploading a replacement. In that case, there
    // is already a message in the revision log that will be migrated so we
    // normally don't have anything to.
    if ($this instanceof NodeRevision) {
      return;
    }

    $field_file = $row->getSourceProperty('field_file');
    if (empty($field_file)) {
      return;
    }

    $items = [];
    foreach ($field_file as $item) {
      $items[$item['fid']] = $this->parseAttachmentDescription($item['description'] ?? '');
    }
    if (empty($items)) {
      return;
    }

    // Load the data from the file_managed table.
    $records = $this->select('file_managed', 'fm')
      ->fields('fm', ['fid', 'uri', 'filename', 'filemime', 'filesize'])
      ->condition('fm.status', 1, '=')
      ->condition('fm.fid', array_keys($items), 'IN')
      ->execute()
      ?->fetchAll(\PDO::FETCH_OBJ) ?? [];

    $attachments = [];
    foreach ($records as $record) {
      if (empty($record->uri)) {
        continue;
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
      // The revision ID will be updated when running the file migration script.
      $item['revision_id'] = 0;
      $attachments[$record->fid] = $item;
    }

    if (!empty($attachments)) {
      $row->setDestinationProperty('field_file', $attachments);
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
        $versions[$label . ' version'] = $code;
      }
      $version_pattern = implode('|', $version_pattern);
      if (!empty($version_pattern) && preg_match('/( - )?(?P<version>' . $version_pattern . ')$/i', $description, $matches) === 1) {
        $result['language'] = $versions[$matches['version']];
        $description = mb_substr($description, 0, -mb_strlen($matches[0]));
      }
    }

    $result['description'] = $description;

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    // The language, promote and sticky fields are not migrated as they
    // were not used in ReliefWeb Drupal 7.
    $fields = [
      'nid' => $this->t('Node ID'),
      'type' => $this->t('Type'),
      'title' => $this->t('Title'),
      'uid' => $this->t('Node authored by (uid)'),
      'created' => $this->t('Created timestamp'),
      'changed' => $this->t('Modified timestamp'),
      'status' => $this->t('Publication status'),
      'revision_id' => $this->t('The node revision ID.'),
      'revision_log_message' => $this->t('The node revision log message.'),
      'revision_user' => $this->t('The node revision user id.'),
      'revision_created' => $this->t('The node revision creation timestamp.'),
      'revision_default' => $this->t('The node revision default status.'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['nid']['type'] = 'integer';
    $ids['nid']['alias'] = 'n';
    return $ids;
  }

}
