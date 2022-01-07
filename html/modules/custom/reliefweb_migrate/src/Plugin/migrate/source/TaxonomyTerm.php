<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;

/**
 * Retrieve taxonomy terms from the Drupal 7 database.
 *
 * Available configuration keys:
 * - bundle: (optional) The taxonomy vocabulary (machine name) to filter terms
 *   retrieved from the source - can be a string or an array. If omitted, all
 *   terms are retrieved.
 *
 * @see \Drupal\taxonomy\Plugin\migrate\source\d7\Term
 *
 * @MigrateSource(
 *   id = "reliefweb_taxonomy_term"
 * )
 */
class TaxonomyTerm extends FieldableEntityBase {

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'taxonomy_term';

  /**
   * {@inheritdoc}
   */
  protected $idField = 'tid';

  /**
   * {@inheritdoc}
   */
  protected $bundleField = 'vid';

  /**
   * {@inheritdoc}
   */
  protected $revisionIdField = 'revision_id';

  /**
   * {@inheritdoc}
   */
  protected $useRevisionId = FALSE;

  /**
   * Join condition for the taxonomy_term_data table.
   *
   * @var string
   */
  protected $join = 'td.revision_id = tdr.revision_id';

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('taxonomy_term_data_revision', 'tdr');

    // Join the data table.
    $query->innerJoin('taxonomy_term_data', 'td', $this->join);

    // Join the vocabulary table.
    $query->innerJoin('taxonomy_vocabulary', 'tv', 'tv.vid = tdr.vid');

    // Base fields.
    $query->addField('td', 'tid', 'tid');

    if (!$this->useRevisionId) {
      $query->addField('td', 'name', 'name');
      $query->addField('td', 'description', 'description');
      $query->addField('td', 'format', 'format');
      $query->addField('td', 'weight', 'weight');

    }
    else {
      $query->addField('tdr', 'name', 'name');
      $query->addField('tdr', 'description', 'description');
      $query->addField('tdr', 'format', 'format');
      $query->addField('tdr', 'weight', 'weight');
    }

    // Use the vocabulary machine name for the `vid` as expected by D9.
    $query->addField('tv', 'machine_name', 'vid');

    // Revision fields.
    $query->addField('tdr', 'revision_id', 'revision_id');
    $query->addField('tdr', 'log', 'revision_log_message');
    $query->addField('tdr', 'uid', 'revision_user');
    $query->addField('tdr', 'timestamp', 'revision_created');
    $query->addExpression('IF(tdr.revision_id = td.revision_id, 1, 0)', 'revision_default');

    // Restrict to the given bundles.
    if (isset($this->configuration['bundle'])) {
      $query->condition('tv.machine_name', (array) $this->configuration['bundle'], 'IN');
    }

    $query->orderBy('tdr.revision_id', 'ASC');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $iterator = parent::initializeIterator();
    $this->convertProfileFieldImageUrls();
    return $iterator;
  }

  /**
   * Convert the profile field image URLS to the new UUID-based pattern.
   */
  protected function convertProfileFieldImageUrls() {
    if (!isset($this->configuration['bundle']) || !is_string($this->configuration['bundle'])) {
      return;
    }
    $bundle = $this->configuration['bundle'];

    $field_names = [
      'field_key_content',
      'field_appeals_response_plans',
    ];

    $nids = [];
    foreach ($field_names as $field_name) {
      if (empty($this->preloadedFieldValues[$bundle][$field_name])) {
        continue;
      }

      foreach ($this->preloadedFieldValues[$bundle][$field_name] as $entity_id => $items) {
        foreach ($items as $delta => $item) {
          $nid = str_replace('/node/', '', $item['url']);
          $nids[$nid] = $nid;
        }
      }
    }

    $image_uris = [];
    if (!empty($nids)) {
      $query = $this->select('file_managed', 'fm');
      $query->innerJoin('field_data_field_file', 'f', '%alias.field_file_fid = fm.fid');
      $query->addField('f', 'entity_id', 'nid');
      $query->addField('fm', 'uri', 'uri');
      $query->condition('f.delta', 0, '=');
      $query->condition('f.entity_type', 'node', '=');
      $query->condition('f.entity_id', $nids, 'IN');
      foreach ($query->execute() ?? [] as $record) {
        $uuid = LegacyHelper::generateAttachmentUuid($record['uri']);
        $image_uris['/node/' . $record['nid']] = ReliefWebFile::getFileUriFromUuid($uuid, 'png', FALSE, TRUE);
      }
    }

    if (!empty($image_uris)) {
      foreach ($field_names as $field_name) {
        if (empty($this->preloadedFieldValues[$bundle][$field_name])) {
          continue;
        }

        foreach ($this->preloadedFieldValues[$bundle][$field_name] as $entity_id => $items) {
          foreach ($items as $delta => $item) {
            $item['image'] = $image_uris[$item['url']] ?? '';
            $items[$delta] = $item;
          }
          $this->preloadedFieldValues[$bundle][$field_name][$entity_id] = $items;
        }
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

    // Basic Glide fix.
    if ($row->hasSourceProperty('field_glide')) {
      $glide = $row->getSourceProperty('field_glide');
      foreach ($glide as $delta => $item) {
        $glide[$delta]['value'] = preg_replace('#(Glide Number|GLIDE|\s)#i', '', $item['value']);
      }
      $row->setSourceProperty('field_glide', $glide);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'tid' => $this->t('The term ID.'),
      'name' => $this->t('The name of the term.'),
      'description' => $this->t('The term description.'),
      'format' => $this->t('Format of the term description.'),
      'weight' => $this->t('Weight'),
      'vid' => $this->t('Vocabulary machine name'),
      // Empty in ReliefWeb as there is no term hierarchy.
      'parent' => $this->t("The Drupal term IDs of the term's parents."),
      'revision_id' => $this->t('The term revision ID.'),
      'revision_log_message' => $this->t('The term revision log message.'),
      'revision_user' => $this->t('The term revision user id.'),
      'revision_created' => $this->t('The term revision creation timestamp.'),
      'revision_default' => $this->t('The term revision default status.'),
      'moderation_status' => $this->t('The moderation status'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['tid']['type'] = 'integer';
    $ids['tid']['alias'] = 'td';
    return $ids;
  }

}
