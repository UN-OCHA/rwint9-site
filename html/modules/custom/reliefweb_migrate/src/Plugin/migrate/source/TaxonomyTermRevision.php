<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Retrieve taxonomy term revisios from the Drupal 7 database.
 *
 * Available configuration keys:
 * - bundle: (optional) The taxonomy vocabulary (machine name) to filter terms
 *   retrieved from the source - can be a string or an array. If omitted, all
 *   terms are retrieved.
 *
 * @see \Drupal\taxonomy\Plugin\migrate\source\d7\Term
 *
 * @MigrateSource(
 *   id = "reliefweb_taxonomy_term_revision"
 * )
 */
class TaxonomyTermRevision extends FieldableEntityBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('taxonomy_term_data_revision', 'tr')
      ->fields('tr', ['tid', 'vid', 'name', 'description', 'format', 'weight'])
      ->orderBy('tid');

    $query->addField('tr', 'revision_id', 'revision_id');
    $query->addField('tr', 'uid', 'revision_user');
    $query->addField('tr', 'timestamp', 'revision_created');
    $query->addField('tr', 'log', 'revision_log_message');

    $query->innerJoin('taxonomy_vocabulary', 'tv', 'tr.vid = tv.vid');
    $query->addField('tv', 'machine_name');

    // @todo remove the condition on the revision_id to allow importing
    // all the revisions.
    $query->innerJoin('taxonomy_term_data', 'td', 'td.tid = tr.tid AND td.revision_id = tr.revision_id');
    $query->addExpression('IF(tr.revision_id = td.revision_id, 1, 0)', 'revision_default');

    if (isset($this->configuration['bundle'])) {
      $query->condition('tv.machine_name', (array) $this->configuration['bundle'], 'IN');
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'tid' => $this->t('The term ID.'),
      'vid' => $this->t('Existing term VID'),
      'machine_name' => $this->t('Vocabulary machine name'),
      'name' => $this->t('The name of the term.'),
      'description' => $this->t('The term description.'),
      'weight' => $this->t('Weight'),
      'parent' => $this->t("The Drupal term IDs of the term's parents."),
      'format' => $this->t("Format of the term description."),
      'revision_id' => $this->t('The term revision ID.'),
      'revision_user' => $this->t('The term revision user ID.'),
      'revision_created' => $this->t('The term revision creation timestamp.'),
      'revision_log_message' => $this->t('The term revision log message.'),
      'revision_default' => $this->t('Wether the revision is the default revision or not.'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $tid = $row->getSourceProperty('tid');
    $revision_id = $row->getSourceProperty('revision_id');
    $vocabulary = $row->getSourceProperty('machine_name');

    // Get Field API field values.
    foreach ($this->getFields('taxonomy_term', $vocabulary) as $field_name => $field) {
      $row->setSourceProperty($field_name, $this->getFieldValues('taxonomy_term', $field_name, $tid, $revision_id));
    }

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['tid']['type'] = 'integer';
    return $ids;
  }

}
