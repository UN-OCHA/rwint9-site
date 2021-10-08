<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Plugin implementation of the 'reliefweb_disaster' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_disaster",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb Disaster"),
 *   multiple_values = true,
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class ReliefWebDisaster extends ReliefWebEntityReferenceSelect {

  /**
   * {@inheritdoc}
   */
  protected function executeOptionQuery(SelectInterface $query, FieldableEntityInterface $entity) {
    // Exclude the Complex Emergency disasters unless the current user has
    // is an external disaster manager.
    if (!UserHelper::userHasRoles(['external_disaster_manager'])) {
      $entity_type_id = $this->getReferencedEntityTypeId();

      // Get the datbase info for the referenced entity type.
      $table = $this->getEntityTypeDataTable($entity_type_id);
      $id_field = $this->getEntityTypeIdField($entity_type_id);
      $langcode_field = $this->getEntityTypeLangcodeField($entity_type_id);

      // Join the disaster type table.
      $field_name = 'field_primary_disaster_type';
      $field_table = $this->getFieldTableName($entity_type_id, $field_name);
      $field_field = $this->getFieldColumnName($entity_type_id, $field_name, 'target_id');
      $field_table_alias = $query->innerJoin($field_table, $field_table, implode(' AND ', [
        "%alias.entity_id = {$table}.{$id_field}",
        "%alias.langcode = {$table}.{$langcode_field}",
      ]));

      // Exclude the Complex Emergency (41764) disasters.
      $query->condition($field_table_alias . '.' . $field_field, 41764, '<>');
    }

    return parent::executeOptionQuery($query, $entity);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    // Limit to fields references disaster terms.
    $settings = $field_definition->getSetting('handler_settings');
    $bundles = $settings['target_bundles'] ?? [];
    return count($bundles) === 1 && in_array('disaster', $bundles);
  }

}
