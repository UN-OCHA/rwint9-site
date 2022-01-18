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
  protected function doPreloadExisting(array $ids) {
    if (!empty($ids)) {
      return $this->getDatabaseConnection()
        ->select('node_revision', 'nr')
        ->fields('nr', ['vid'])
        ->condition('nr.vid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? [];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIds() {
    $bundle = $this->configuration['bundle'];
    if ($bundle === 'topics') {
      $bundle = 'topic';
    }

    $query = $this->getDatabaseConnection()
      ->select('node_revision', 'nr')
      ->fields('nr', ['vid'])
      ->orderBy('nr.vid', 'ASC');

    $query->innerJoin('node', 'n', 'n.nid = nr.nid AND n.vid <> nr.vid');
    $query->condition('n.type', $bundle, '=');

    return $query->execute()
      ?->fetchAllKeyed(0, 0) ?? [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIdsToDelete(array $ids) {
    if (!empty($ids)) {
      return array_diff($ids, $this->select('node_revision', 'nr')
        ->fields('nr', ['vid'])
        ->condition('nr.vid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? []);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['vid']['type'] = 'integer';
    $ids['vid']['alias'] = 'nr';
    return $ids;
  }

}
