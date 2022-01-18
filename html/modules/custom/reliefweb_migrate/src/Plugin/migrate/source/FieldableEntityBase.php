<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Base class to retrieve entity fields.
 *
 * @see \Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity.
 */
abstract class FieldableEntityBase extends EntityBase {

  /**
   * Store the field instance data per entity types and bundles.
   *
   * @var array
   */
  protected $fieldInstances = [];

  /**
   * Preloaded field values when a batch size is used.
   *
   * @var array
   */
  protected $preloadedFieldValues = [];

  /**
   * Preloaded url aliases when a batch size is used.
   *
   * @var array
   */
  protected $preloadedUrlAliases = [];

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $iterator = parent::initializeIterator();

    // Preload the raw field values if we are processing the entities in batch
    // to reduce the number of database requests.
    if (!empty($this->batchSize)) {
      $this->preloadFields($iterator);
    }

    return $iterator;
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
      $use_revision_id = $this->useRevisionId && !empty($this->revisionIdField);

      // Extract the entity ids or revision ids.
      $bundles = [];
      foreach ($iterator as $record) {
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
   * Preload URL aliases.
   *
   * @param array $bundles
   *   List of entity ids grouped by bundles.
   */
  protected function preloadUrlAliases(array $bundles) {
    // Ignore aliases for revisions.
    if (!empty($this->useRevisionId)) {
      return;
    }
    // There are only aliases for nodes and terms in ReliefWeb Drupal 7.
    if ($this->entityType !== 'node' && $this->entityType !== 'taxonomy_term') {
      return;
    }

    $base = strtr($this->entityType, '_', '/') . '/';

    $sources = [];
    foreach ($bundles as $ids) {
      foreach ($ids as $id) {
        $sources[$id] = $base . $id;
      }
    }

    // Order by pid ASC to get the most recent URL aliases for the entities.
    $records = $this->select('url_alias', 'u')
      ->fields('u', ['pid', 'source', 'alias'])
      ->condition('u.source', $sources, 'IN')
      ->orderBy('u.pid', 'ASC')
      ->execute();

    $aliases = [];
    foreach ($records as $record) {
      $aliases[$record['source']] = [
        'id' => $record['pid'],
        'alias' => $record['alias'],
      ];
    }

    foreach ($sources as $id => $source) {
      if (isset($aliases[$source])) {
        $this->preloadedUrlAliases[$id] = $aliases[$source];
      }
    }
  }

  /**
   * Get an entity URL alias.
   *
   * @param int $id
   *   Entity id.
   */
  protected function getUrlAlias($id) {
    $source = strtr($this->entityType, '_', '/') . '/' . $id;

    // Order by pid ASC to get the most recent URL alias for the entity.
    $records = $this->select('url_alias', 'u')
      ->fields('u', ['pid', 'source', 'alias'])
      ->condition('u.source', $source, '=')
      ->orderBy('u.pid', 'ASC')
      ->execute();

    $aliases = [];
    foreach ($records as $record) {
      $aliases[$record['source']] = [
        'id' => $record['pid'],
        'alias' => $record['alias'],
      ];
    }

    return $aliases[$source] ?? NULL;
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
  public function prepareRow(Row $row) {
    $bundle = $row->getSourceProperty($this->bundleField);

    if (!empty($this->batchSize)) {
      if ($this->useRevisionId && !empty($this->revisionIdField)) {
        $id = $row->getSourceProperty($this->revisionIdField);
      }
      else {
        $id = $row->getSourceProperty($this->idField);
      }

      $this->setRowSourceFields($id, $bundle, $row);

      $this->setRowDestinationUrlAlias($id, $row);
    }
    else {
      $id = $row->getSourceProperty($this->idField);

      $revision_id = NULL;
      if ($this->useRevisionId && !empty($this->revisionIdField)) {
        $revision_id = $row->getSourceProperty($this->revisionIdField);
      }

      foreach ($this->getFields($this->entityType, $bundle) as $field_name => $field) {
        $row->setSourceProperty($field_name, $this->getFieldValues($this->entityType, $field_name, $id, $revision_id));
      }

      $url_alias = $this->getUrlAlias($id);
      if (!empty($url_alias)) {
        $row->setDestinationProperty('url_alias', $url_alias);
      }
    }

    return parent::prepareRow($row);
  }

  /**
   * Set the row source field properties based on the preloaded field data.
   *
   * @param int $id
   *   Entity ID or revision ID.
   * @param string $bundle
   *   Entity bundle.
   * @param \Drupal\migrate\Row $row
   *   Migration row.
   */
  protected function setRowSourceFields($id, $bundle, Row $row) {
    foreach ($this->getFields($this->entityType, $bundle) as $field_name => $field) {
      if (isset($this->preloadedFieldValues[$bundle][$field_name][$id])) {
        $row->setSourceProperty($field_name, $this->preloadedFieldValues[$bundle][$field_name][$id]);
        unset($this->preloadedFieldValues[$bundle][$field_name][$id]);
      }
    }
  }

  /**
   * Set the row destination url alias from the preloaded URL aliases.
   *
   * @param int $id
   *   Entity ID or revision ID.
   * @param \Drupal\migrate\Row $row
   *   Migration row.
   */
  protected function setRowDestinationUrlAlias($id, Row $row) {
    if (!empty($this->preloadedUrlAliases[$id])) {
      $row->setDestinationProperty('url_alias', $this->preloadedUrlAliases[$id]);
      unset($this->preloadedUrlAliases[$id]);
    }
  }

  /**
   * Returns all non-deleted field instances attached to a specific entity type.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string|null $bundle
   *   (optional) The bundle.
   *
   * @return array[]
   *   The field instances, keyed by field name.
   */
  protected function getFields($entity_type, $bundle = NULL) {
    $bundle = isset($bundle) ? $bundle : $entity_type;

    if (!isset($this->fieldInstances[$entity_type][$bundle])) {
      $query = $this->select('field_config_instance', 'fci')
        ->fields('fci')
        ->condition('fci.entity_type', $entity_type)
        ->condition('fci.bundle', $bundle)
        ->condition('fci.deleted', 0);

      // Join the 'field_config' table and add the 'translatable' setting to the
      // query.
      $query->leftJoin('field_config', 'fc', 'fci.field_id = fc.id');
      $query->addField('fc', 'translatable');

      $this->fieldInstances[$entity_type][$bundle] = $query->execute()->fetchAllAssoc('field_name');
    }

    return $this->fieldInstances[$entity_type][$bundle];
  }

  /**
   * Retrieves field values for a single field of a single entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $field
   *   The field name.
   * @param int $entity_id
   *   The entity ID.
   * @param int|null $revision_id
   *   (optional) The entity revision ID.
   * @param string $language
   *   (optional) The field language.
   *
   * @return array
   *   The raw field values, keyed by delta.
   */
  protected function getFieldValues($entity_type, $field, $entity_id, $revision_id = NULL, $language = NULL) {
    $table = (isset($revision_id) ? 'field_revision_' : 'field_data_') . $field;
    $query = $this->select($table, 't')
      ->fields('t')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->condition('deleted', 0);
    if (isset($revision_id)) {
      $query->condition('revision_id', $revision_id);
    }
    // Add 'language' as a query condition if it has been defined by Entity
    // Translation.
    if ($language) {
      $query->condition('language', $language);
    }
    $values = [];
    foreach ($query->execute() as $row) {
      foreach ($row as $key => $value) {
        $delta = $row['delta'];
        if (strpos($key, $field) === 0) {
          $column = substr($key, strlen($field) + 1);
          $values[$delta][$column] = $value;
        }
      }
    }
    return $values;
  }

}
