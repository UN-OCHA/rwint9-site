<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

/**
 * Retrieve node revisions from the Drupal 7 database.
 *
 * Available configuration keys:
 * - bundle: (optional) The node type (machine name) to filter nodes
 *   retrieved from the source - can be a string or an array. If omitted, all
 *   nodes are retrieved.
 *
 * @see \Drupal\node\Plugin\migrate\source\d7\NodeRevision
 *
 * @MigrateSource(
 *   id = "reliefweb_node_revision"
 * )
 */
class NodeRevision extends Node {

  /**
   * {@inheritdoc}
   */
  protected $useRevisionId = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $join = 'n.nid = nr.nid AND n.vid <> nr.vid';

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['vid']['type'] = 'integer';
    $ids['vid']['alias'] = 'nr';
    return $ids;
  }

}
