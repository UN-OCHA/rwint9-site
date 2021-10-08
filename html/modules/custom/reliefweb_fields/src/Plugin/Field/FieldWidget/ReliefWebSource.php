<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Plugin implementation of the 'reliefweb_source' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_source",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb Source"),
 *   multiple_values = true,
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class ReliefWebSource extends ReliefWebEntityReferenceSelect {

  /**
   * Get the list of allowed content types.
   *
   * @return array
   *   Array keyed by the content type bundle and with the correspoding field
   *   numeric value as value.
   *
   * @see field.storage.taxonomy_term.field_allowed_content_types.yml
   */
  protected function getAllowedContentTypes() {
    return [
      'job' => 0,
      'report' => 1,
      'training' => 2,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function executeOptionQuery(SelectInterface $query, FieldableEntityInterface $entity) {
    // Allowed content types.
    $bundles = $this->getAllowedContentTypes();

    // Skip if the entity to which this field is attached has a bundle not
    // in the allowed content types.
    $bundle = $entity->bundle();
    if (isset($bundles[$bundle])) {
      $entity_type_id = $this->getReferencedEntityTypeId();

      // Get the datbase info for the referenced entity type.
      $table = $this->getEntityTypeDataTable($entity_type_id);
      $id_field = $this->getEntityTypeIdField($entity_type_id);
      $langcode_field = $this->getEntityTypeLangcodeField($entity_type_id);

      // Allowed content types field.
      $field_name = 'field_allowed_content_types';
      $field_table = $this->getFieldTableName($entity_type_id, $field_name);
      $field_field = $this->getFieldColumnName($entity_type_id, $field_name, 'value');
      $field_table_alias = $query->leftJoin($field_table, $field_table, implode(' AND ', [
        "%alias.entity_id = {$table}.{$id_field}",
        "%alias.langcode = {$table}.{$langcode_field}",
      ]));

      // Limit to the content type matching the referencing entity bundle.
      $query->condition($field_table_alias . '.' . $field_field, $bundles[$bundle], '=');
    }

    return parent::executeOptionQuery($query, $entity);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    // Limit to fields references source terms.
    $settings = $field_definition->getSetting('handler_settings');
    $bundles = $settings['target_bundles'] ?? [];
    return count($bundles) === 1 && in_array('source', $bundles);
  }

}
