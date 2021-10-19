<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'reliefweb_import_info' field type.
 *
 * @FieldType(
 *   id = "reliefweb_import_info",
 *   label = @Translation("Relief web import info"),
 *   description = @Translation("Import info field"),
 *   category = @Translation("ReliefWeb"),
 *   default_widget = "reliefweb_import_info",
 *   default_formatter = "reliefweb_import_info"
 * )
 */
class ReliefWebImportInfo extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'feed_url';
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['feed_url'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Feed Url'))
      ->setRequired(TRUE);

    $properties['base_url'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Base Url'))
      ->setRequired(TRUE);

    $properties['uid'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Associated user'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'feed_url' => [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => TRUE,
        ],
        'base_url' => [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => FALSE,
        ],
        'uid' => [
          'type' => 'int',
          'size' => 'medium',
          'not null' => TRUE,
        ],
      ],
      'indexes' => [
        'feed_url' => ['feed_url'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $values = [
      'feed_url' => 'https://www.example.com/jobs/feed.xml',
      'base_url' => 'https://www.example.com/',
      'uid' => 0,
    ];

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('feed_url')->getValue();
    return $value === NULL || $value === '';
  }

}
