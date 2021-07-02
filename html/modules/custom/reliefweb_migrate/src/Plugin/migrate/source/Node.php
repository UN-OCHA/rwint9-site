<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

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

    return $query;
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
