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
  protected $revisionIdField = 'vid';

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
    $query->addField('n', 'uid', 'uid');
    $query->addField('n', 'created', 'created');
    $query->addField('n', 'changed', 'changed');

    if (!$this->useRevisionId) {
      $query->addField('n', 'title', 'title');
      $query->addField('n', 'status', 'status');
    }
    else {
      $query->addField('nr', 'title', 'title');
      $query->addField('nr', 'status', 'status');
    }

    // Revision fields. We need to keep the "vid" name instead of "revision_id"
    // for the revision ID so that we can use the high water mark property.
    $query->addField('nr', 'vid', 'vid');
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
    else {
      $query->orderBy('nr.vid', 'ASC');
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
      'vid' => $this->t('The node revision ID.'),
      'revision_log_message' => $this->t('The node revision log message.'),
      'revision_user' => $this->t('The node revision user id.'),
      'revision_created' => $this->t('The node revision creation timestamp.'),
      'revision_default' => $this->t('The node revision default status.'),
      'moderation_status' => $this->t('The moderation status'),
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
