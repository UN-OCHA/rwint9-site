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
 * Plugin implementation of the 'reliefweb_links' field type.
 *
 * @FieldType(
 *   id = "reliefweb_links",
 *   label = @Translation("ReliefWeb Links"),
 *   description = @Translation("A field to store a list of internal or external links."),
 *   category = @Translation("ReliefWeb"),
 *   default_widget = "reliefweb_links",
 *   default_formatter = "reliefweb_links",
 *   cardinality = -1,
 *   constraints = {"ReliefWebLink" = {}}
 * )
 */
class ReliefWebLinks extends FieldItemBase {

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
        'image' => [
          'type' => 'varchar',
          'description' => 'Url of an image accompanying the link (ex: logo).',
          'length' => 2048,
          'not null' => FALSE,
          'sortable' => TRUE,
        ],
        'active' => [
          'description' => '0 = inactive; 1 = active',
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
      'indexes' => [
        'url' => ['url'],
        'active' => ['active'],
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

    $properties['image'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Image'))
      ->setRequired(FALSE);

    $properties['active'] = DataDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'internal' => 0,
      'use_cover' => 0,
      'keep_archives' => 0,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);

    $element['internal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Internal links'),
      '#description' => $this->t('Check if the url is used for links internal to the site.'),
      '#default_value' => $this->getSetting('internal'),
    ];

    $element['use_cover'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use cover'),
      '#description' => $this->t('Check to use to the cover of the document matching the url. Only used when internal is checked.'),
      '#default_value' => $this->getSetting('use_cover'),
    ];

    $element['keep_archives'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Keep archives'),
      '#description' => $this->t('Check to keep the archives of the links.'),
      '#default_value' => $this->getSetting('keep_archives'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    // @todo check title as well?
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
          'maxMessage' => $this->t('The URL may not be longer than @max characters.', [
            '@max' => 2048,
          ]),
        ],
      ],
    ]);
    $constraints[] = $constraint_manager->create('ComplexData', [
      'title' => [
        'Length' => [
          'max' => 1024,
          'maxMessage' => $this->t('The title may not be longer than @max characters.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
            '@max' => 1024,
          ]),
        ],
      ],
    ]);
    $constraints[] = $constraint_manager->create('ComplexData', [
      'image' => [
        'Length' => [
          'max' => 2048,
          'maxMessage' => $this->t('The image may not be longer than @max characters.', [
            '@max' => 2048,
          ]),
        ],
      ],
    ]);

    return $constraints;
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();

    $values['url'] = 'https://' .
      $random->string(mt_rand(4, 8)) . '.' .
      $random->string(mt_rand(2, 3)) . '/' .
      $random->string(mt_rand(4, 12));

    $values['title'] = $random->sentences(mt_rand(2, 5));

    $values['image'] = 'https://' .
      $random->string(mt_rand(4, 8)) . '.' .
      $random->string(mt_rand(2, 3)) . '/' .
      $random->string(mt_rand(4, 12)) . '.jpg';

    $values['active'] = mt_rand(0, 1);

    return $values;
  }

}
