<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\Field\FieldType;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'reliefweb_post_api_key' entity field type.
 *
 * @FieldType(
 *   id = "reliefweb_post_api_key",
 *   label = @Translation("ReliefWeb POST API key"),
 *   description = @Translation("An entity field containing an API key value."),
 *   default_widget = "reliefweb_post_api_key",
 *   default_formatter = "reliefweb_post_api_key"
 * )
 */
class ApiKeyItem extends StringItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('The hashed API key'))
      ->setSetting('case_sensitive', TRUE);
    $properties['existing'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Existing API key'));
    $properties['pre_hashed'] = DataDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Determines if a API key needs hashing'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    parent::preSave();

    $entity = $this->getEntity();
    $field = $this->getFieldDefinition()->getName();
    $value = trim($this->value ?? '');
    $original_value = $entity->original?->get($field)->value ?? '';

    if ($this->pre_hashed) {
      // Reset the pre_hashed value since it has now been used.
      $this->pre_hashed = FALSE;
    }
    elseif (empty($value)) {
      // If the API key is empty, that means it was not changed, so use the
      // original one.
      $this->value = $original_value;
    }
    elseif ($value !== $original_value) {
      // Allow alternate hashing schemes.
      $this->value = \Drupal::service('password')->hash(trim($value));

      // Abort if the hashing failed and returned FALSE.
      if (!$this->value) {
        throw new EntityMalformedException('The entity does not have an API key.');
      }
    }

    // Ensure that the existing API key is unset to minimize risks of it
    // getting serialized and stored somewhere.
    $this->existing = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    // We cannot use the parent implementation from StringItem as it does not
    // consider the additional 'existing' property that ApiKeyItem contains.
    $value = $this->get('value')->getValue();
    $existing = $this->get('existing')->getValue();
    return $value === NULL && $existing === NULL;
  }

}
