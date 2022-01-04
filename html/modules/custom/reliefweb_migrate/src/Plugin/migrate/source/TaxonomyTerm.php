<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

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
