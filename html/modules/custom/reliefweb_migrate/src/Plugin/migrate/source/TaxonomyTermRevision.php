<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

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
  public function prepareRow(Row $row) {
    $log = $row->getSourceProperty('revision_log_message');
    if (strpos($log, 'Automatic update of') === 0) {
      $row->setDestinationProperty('skip_saving', TRUE);
    }
    return parent::prepareRow($row);
  }

  /**
   * Preload the raw field values for the entity records.
   *
   * @param \IteratorIterator $iterator
   *   Iterator of the database statement with the query results.
   */
  protected function preloadFields(\IteratorIterator $iterator) {
    $iterator->rewind();

    if ($iterator->count() > 0) {
      // Clear the previously preloaded values and aliases.
      $this->preloadedFieldValues = [];
      $this->preloadedUrlAliases = [];

      $use_revision_id = $this->useRevisionId && !empty($this->revisionIdField);

      // Extract the entity ids or revision ids.
      $bundles = [];
      foreach ($iterator as $record) {
        // The entries with such a log message are skipped in ::prepareRow()
        // so no need to load the field data.
        if (strpos($record['revision_log_message'], 'Automatic update of') === 0) {
          continue;
        }
        if ($use_revision_id) {
          $id = $record[$this->revisionIdField];
        }
        else {
          $id = $record[$this->idField];
        }
        $bundles[$record[$this->bundleField]][$id] = $id;
      }

      $iterator->rewind();

      // Retrieve the field values.
      foreach ($bundles as $bundle => $ids) {
        foreach ($this->getFields($this->entityType, $bundle) as $field_name => $field) {
          $this->preloadedFieldValues[$bundle][$field_name] = $this->getAllFieldValues($this->entityType, $field_name, $ids, $use_revision_id);
        }
      }

      // Preload the URL aliases.
      $this->preloadUrlAliases($bundles);
    }
  }

  /**
   * Retrieves field values for a single field of a single entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $field
   *   The field name.
   * @param array $ids
   *   The entity IDs or revision IDs.
   * @param bool $use_revision_id
   *   Whether the ids are revision ids or entity ids.
   * @param string $language
   *   (optional) The field language.
   *
   * @return array
   *   The raw field values, keyed by entity or revision id, then delta.
   */
  protected function getAllFieldValues($entity_type, $field, array $ids, $use_revision_id = FALSE, $language = NULL) {
    if (!$use_revision_id) {
      $table = 'field_data_' . $field;
      $id_field = 'entity_id';
    }
    else {
      $table = 'field_revision_' . $field;
      $id_field = 'revision_id';
    }

    $query = $this->select($table, 't')
      ->fields('t')
      ->condition('entity_type', $entity_type)
      ->condition($id_field, $ids, 'IN')
      ->condition('deleted', 0);

    // Skip inactive key content and appeals or response plans in the revisions
    // because it's too heavy and of little use. The latest revision has all
    // the data.
    if ($field === 'field_key_content' || $field === 'field_appeals_response_plans') {
      $query->condition('t.' . $field . '_active', 1, '=');
    }

    // Add 'language' as a query condition if it has been defined by Entity
    // Translation.
    if ($language) {
      $query->condition('language', $language);
    }
    $values = [];
    foreach ($query->execute() as $row) {
      $id = $row[$id_field];
      foreach ($row as $key => $value) {
        $delta = $row['delta'];
        if (strpos($key, $field) === 0) {
          $column = substr($key, strlen($field) + 1);
          $values[$id][$delta][$column] = $value;
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['revision_id']['type'] = 'integer';
    $ids['revision_id']['alias'] = 'tdr';
    return $ids;
  }

}
