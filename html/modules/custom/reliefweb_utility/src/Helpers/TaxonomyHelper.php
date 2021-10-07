<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\taxonomy\TermInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;

/**
 * Helper to retrieve info about taxonomy terms.
 */
class TaxonomyHelper {

  use EntityDatabaseInfoTrait;

  /**
   * Get the shortnames for the given sources.
   *
   * @param array $ids
   *   Source term ids.
   *
   * @return array
   *   Associative array with the source ids as keys and their shortname or name
   *   as values.
   */
  public static function getSourceShortnames(array $ids) {
    if (empty($ids)) {
      return [];
    }

    $helper = new TaxonomyHelper();

    $table = $helper->getEntityTypeDataTable('taxonomy_term');
    $id_field = $helper->getEntityTypeIdField('taxonomy_term');
    $label_field = $helper->getEntityTypeLabelField('taxonomy_term');

    $field_name = 'field_shortname';
    $field_table = $helper->getFieldTableName('taxonomy_term', $field_name);
    $field_field = $helper->getFieldColumnName('taxonomy_term', $field_name, 'value');

    $query = $helper->getDatabase()->select($table, $table);
    $query->addField($table, $id_field, 'id');
    $query->condition($table . '.' . $id_field, $ids, 'IN');

    $field_table_alias = $query->innerJoin($field_table, $field_table, "%alias.entity_id = {$table}.{$id_field}");
    $query->addExpression("COALESCE({$field_table_alias}.{$field_field}, {$table}.{$label_field})", 'label');
    $query->orderBy('label', 'ASC');

    return $query->execute()?->fetchAllKeyed() ?? [];
  }

  /**
   * Check if the given term is referenced by another entity.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   Term.
   *
   * @return bool
   *   TRUE if the term is referenced by another entity.
   */
  public static function isTermReferenced(TermInterface $term) {
    // New terms are obviously not referenced.
    if ($term->id() === NULL) {
      return FALSE;
    }

    $helper = new TaxonomyHelper();

    $entity_field_manager = $helper->getEntityFieldManager();

    $field_mapping = $entity_field_manager
      ->getFieldMapByFieldType('entity_reference');

    // Retrieve all the fields that reference the entity type.
    // @todo we could reduce a bit the number of database queries below
    // by loading the field configs and check the target bundles.
    $field_table_names = [];
    foreach ($field_mapping as $entity_type_id => $fields) {
      $field_storage_definitions = $entity_field_manager
        ->getFieldStorageDefinitions($entity_type_id);

      foreach ($fields as $field => $info) {
        $field_storage_definition = $field_storage_definitions[$field] ?? NULL;
        if (isset($field_storage_definition) && $field_storage_definition instanceof FieldStorageConfig) {
          if ($field_storage_definition->getSetting('target_type') === 'taxonomy_term') {
            $field_table_name = $helper->getFieldTableName($entity_type_id, $field);
            $field_table_names[$field_table_name] = $helper->getFieldColumnName($entity_type_id, $field, 'target_id');
          }
        }
      }
    }

    foreach ($field_table_names as $field_table_name => $field_column_name) {
      $existing = $helper->getDatabase()
        ->select($field_table_name, $field_table_name)
        ->fields($field_table_name, ['entity_id'])
        ->condition($field_table_name . '.' . $field_column_name, $term->id(), '=')
        ->range(0, 1)
        ->execute()
        ?->fetchField();
      if (!empty($existing)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
