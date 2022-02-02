<?php

namespace Drupal\content_entity_clone\Plugin\content_entity_clone\FieldProcessor;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\content_entity_clone\Plugin\FieldProcessorPluginBase;

/**
 * Field processor that adds a ' [CLONE]' suffix to the entity label field.
 *
 * @ContentEntityCloneFieldProcessor(
 *   id = "entity_label_clone_suffix",
 *   label = @Translation("Add [CLONE] suffix"),
 *   description = @Translation("Add a ' [CLONE]' suffix to the entity label."),
 *   fieldTypes = {
 *     "text",
 *     "text_long",
 *     "text_with_summary",
 *     "string",
 *     "string_long",
 *   }
 * )
 */
class EntityLabelCloneSuffix extends FieldProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function process(FieldItemListInterface $field) {
    $entity_type_id = $field->getEntity()->getEntityTypeId();
    $label_field = static::getEntityTypeLabelField($entity_type_id);
    if ($field->getFieldDefinition()->getName() === $label_field) {
      $field->value .= ' [CLONE]';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function supports(FieldDefinitionInterface $field_definition) {
    if (!parent::supports($field_definition)) {
      return FALSE;
    }
    $entity_type_id = $field_definition->getTargetEntityTypeId();
    $label_field = static::getEntityTypeLabelField($entity_type_id);
    return $field_definition->getName() === $label_field;
  }

  /**
   * Get the label field name for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   *
   * @return string
   *   The entity type label key.
   */
  protected function getEntityTypeLabelField($entity_type_id) {
    return \Drupal::entityTypeManager()
      ->getDefinition($entity_type_id)
      ?->getKey('label') ?? '';
  }

}
