<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

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
  public function query() {
    $query = $this->select('taxonomy_term_data', 'td')
      ->fields('td')
      ->orderBy('tid');

    $query->innerJoin('taxonomy_vocabulary', 'tv', 'td.vid = tv.vid');
    $query->addField('tv', 'machine_name');

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
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $tid = $row->getSourceProperty('tid');
    $vocabulary = $row->getSourceProperty('machine_name');

    // Get Field API field values.
    foreach ($this->getFields('taxonomy_term', $vocabulary) as $field_name => $field) {
      $row->setSourceProperty($field_name, $this->getFieldValues('taxonomy_term', $field_name, $tid));
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
