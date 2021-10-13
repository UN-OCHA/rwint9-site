<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'reliefweb_user_posting_rights' field type.
 *
 * @FieldType(
 *   id = "reliefweb_user_posting_rights",
 *   label = @Translation("ReliefWeb User Posting Rights"),
 *   description = @Translation("A field to store user posting rights."),
 *   category = @Translation("ReliefWeb"),
 *   default_widget = "reliefweb_user_posting_rights",
 *   default_formatter = "reliefweb_user_posting_rights",
 *   cardinality = -1,
 * )
 */
class ReliefWebUserPostingRights extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'id' => [
          'description' => 'User ID.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'job' => [
          'description' => 'Job posting rights: 0 = unverified; 1 = blocked; 2 = allowed; 3 = trusted.',
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'training' => [
          'description' => 'Training posting rights: 0 = unverified; 1 = blocked; 2 = allowed; 3 = trusted.',
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'notes' => [
          'description' => 'Notes',
          'type' => 'text',
          'not null' => TRUE,
        ],
      ],
      'indexes' => [
        'id' => ['id'],
        'job' => ['job'],
        'training' => ['training'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Id'))
      ->setRequired(TRUE);

    $properties['job'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Job'))
      ->setRequired(TRUE);

    $properties['training'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Training'))
      ->setRequired(FALSE);

    $properties['notes'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $url = $this->get('id')->getValue();
    return empty($url);
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints = parent::getConstraints();

    // @todo Add user id lookup validation?
    $constraints[] = $constraint_manager->create('ComplexData', [
      'id' => [
        'Range' => [
          'min' => 3,
          'minMessage' => $this->t('%name: the User IS must be a number superior or equal to @min.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
            '@min' => 3,
          ]),
        ],
      ],
    ]);
    $constraints[] = $constraint_manager->create('ComplexData', [
      'job' => [
        'AllowedValues' => [
          'choices' => [0, 1, 2, 3],
          'strict' => TRUE,
          'message' => $this->t('%name: the Job rights must be one of 0, 1, 2 or 3.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
          ]),
        ],
      ],
    ]);
    $constraints[] = $constraint_manager->create('ComplexData', [
      'training' => [
        'AllowedValues' => [
          'choices' => [0, 1, 2, 3],
          'strict' => TRUE,
          'message' => $this->t('%name: the Training rights must be one of 0, 1, 2 or 3.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
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
    $values['url'] = mt_rand(3, 1000000);
    $values['job'] = mt_rand(0, 3);
    $values['training'] = mt_rand(0, 3);

    return $values;
  }

}
