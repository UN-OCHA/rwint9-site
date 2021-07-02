<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

/**
 * Retrieve taxonomy term revisions from the Drupal 7 database.
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
class TaxonomyTermRevision extends TaxonomyTerm {

  /**
   * {@inheritdoc}
   */
  protected $useRevisionId = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $join = 'td.tid = tdr.tid AND td.revision_id <> tdr.revision_id';

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['revision_id']['type'] = 'integer';
    $ids['revision_id']['alias'] = 'tdr';
    return $ids;
  }

}
