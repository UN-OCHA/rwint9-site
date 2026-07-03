<?php

namespace Drupal\reliefweb_guidelines\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'guideline_field_target' field type.
 *
 * @FieldType(
 *   id = "guideline_field_target",
 *   label = @Translation("Guideline field target"),
 *   description = @Translation("Stores a form field target as entity_type.bundle.field_name."),
 *   default_widget = "guideline_field_target_select_widget",
 *   default_formatter = "guideline_field_target_default_formatter",
 *   module = "reliefweb_guidelines"
 * )
 */
class GuidelineFieldTarget extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('value')->getValue();
    return $value === NULL || $value === '';
  }

}
