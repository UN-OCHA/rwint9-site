<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'reliefweb_section_links' field type.
 *
 * @FieldType(
 *   id = "reliefweb_section_links",
 *   label = @Translation("ReliefWeb Section links"),
 *   description = @Translation("A field to store a list of section links."),
 *   category = @Translation("ReliefWeb"),
 *   default_widget = "reliefweb_section_links",
 *   default_formatter = "reliefweb_section_links",
 * )
 */
class ReliefWebSectionLinks extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'url' => [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => TRUE,
          'sortable' => TRUE,
        ],
        'title' => [
          'type' => 'varchar',
          'length' => 1024,
          'not null' => FALSE,
          'sortable' => TRUE,
        ],
        'override' => [
          'type' => 'int',
          'description' => 'Node Id of node to use as first item.',
          'not null' => FALSE,
        ],
      ],
      'indexes' => [
        'url' => ['url'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['url'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Url'))
      ->setRequired(TRUE);

    $properties['title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE);

    $properties['override'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Override'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'use_override' => 0,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);

    $element['use_override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use override'),
      '#description' => $this->t('Check to use the override option.'),
      '#default_value' => $this->getSetting('use_override'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $url = $this->get('url')->getValue();
    return empty($url);
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints = parent::getConstraints();

    // @todo Add URL validation constraint? Notably for internal links?
    $constraints[] = $constraint_manager->create('ComplexData', [
      'url' => [
        'Length' => [
          'max' => 2048,
          'maxMessage' => $this->t('%name: the URL may not be longer than @max characters.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
            '@max' => 2048,
          ]),
        ],
      ],
    ]);
    $constraints[] = $constraint_manager->create('ComplexData', [
      'title' => [
        'Length' => [
          'max' => 1024,
          'maxMessage' => $this->t('%name: the Title may not be longer than @max characters.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
            '@max' => 1024,
          ]),
        ],
      ],
    ]);

    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();

    $values['url'] = 'https://' .
      $random->string(mt_rand(4, 8)) . '.' .
      $random->string(mt_rand(2, 3)) . '/' .
      $random->string(mt_rand(4, 12));

    $values['title'] = $random->sentences(mt_rand(2, 5));

    $values['override'] = mt_rand(1111, 9999);

    return $values;
  }

}
