<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Base class to retrieve entity fields.
 *
 * @see \Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity.
 */
abstract class FieldableEntityBase extends SqlBase {

  /**
   * Entity type.
   *
   * @var string
   */
  protected $entityType;

  /**
   * Id field.
   *
   * @var string
   */
  protected $idField;

  /**
   * Revision id field.
   *
   * @var string
   */
  protected $revisionIdField = 'revision_id';


  /**
   * Bundle field.
   *
   * @var string
   */
  protected $bundleField;

  /**
   * Flag indicating whether to pass the revision id to get the fields.
   *
   * When TRUE, this will load the data from the field revision tables.
   *
   * @var bool
   */
  protected $useRevisionId = FALSE;

  /**
   * Store the field instance data per entity types and bundles.
   *
   * @var array
   */
  protected $fieldInstances = [];

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $id = $row->getSourceProperty($this->idField);
    $bundle = $row->getSourceProperty($this->bundleField);

    $revision_id = NULL;
    if ($this->useRevisionId && !empty($this->revisionIdField)) {
      $revision_id = $row->getSourceProperty($this->revisionIdField);
    }

    // Get Field API field values.
    foreach ($this->getFields($entity_type, $bundle) as $field_name => $field) {
      $row->setSourceProperty($field_name, $this->getFieldValues($entity_type, $field_name, $id, $revision_id));
    }

    return parent::prepareRow($row);
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
