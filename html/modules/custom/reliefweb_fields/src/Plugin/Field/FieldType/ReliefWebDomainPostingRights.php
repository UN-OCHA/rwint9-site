<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'reliefweb_domain_posting_rights' field type.
 *
 * @FieldType(
 *   id = "reliefweb_domain_posting_rights",
 *   label = @Translation("ReliefWeb Domain Posting Rights"),
 *   description = @Translation("A field to store email domain posting rights."),
 *   category = "reliefweb",
 *   default_widget = "reliefweb_domain_posting_rights",
 *   default_formatter = "reliefweb_domain_posting_rights",
 *   cardinality = -1,
 * )
 */
class ReliefWebDomainPostingRights extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'domain' => [
          'description' => 'Domain.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
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
        'report' => [
          'description' => 'Report posting rights: 0 = unverified; 1 = blocked; 2 = allowed; 3 = trusted.',
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
        'domain' => ['domain'],
        'job' => ['job'],
        'training' => ['training'],
        'report' => ['report'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['domain'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Domain'))
      ->setRequired(TRUE);

    $properties['job'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Job'))
      ->setRequired(TRUE);

    $properties['training'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Training'))
      ->setRequired(FALSE);

    $properties['report'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Report'))
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
    $url = $this->get('domain')->getValue();
    return empty($url);
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints = parent::getConstraints();

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
    $constraints[] = $constraint_manager->create('ComplexData', [
      'report' => [
        'AllowedValues' => [
          'choices' => [0, 1, 2, 3],
          'strict' => TRUE,
          'message' => $this->t('%name: the Report rights must be one of 0, 1, 2 or 3.', [
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
    $values['domain'] = $random->string(mt_rand(1, 250)) . '.' . $random->string(mt_rand(1, 4));
    $values['job'] = mt_rand(0, 3);
    $values['training'] = mt_rand(0, 3);
    $values['report'] = mt_rand(0, 3);

    return $values;
  }

}
