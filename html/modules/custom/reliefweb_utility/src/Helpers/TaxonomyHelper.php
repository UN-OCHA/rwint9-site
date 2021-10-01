<?php

namespace Drupal\reliefweb_utility\Helpers;

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

}
