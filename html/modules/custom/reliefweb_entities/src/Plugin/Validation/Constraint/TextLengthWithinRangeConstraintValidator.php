<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Text length within range constraint validator.
 */
class TextLengthWithinRangeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($item, Constraint $constraint) {
    $field_definition = $item->getFieldDefinition();

    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->context->getRoot()->getValue();
    $field_name = $field_definition->getName();

    if ($entity->hasField($field_name) && isset($constraint->min, $constraint->max)) {
      $min = (int) $constraint->min;
      $max = (int) $constraint->max;
      $skip_if_empty = !empty($constraint->skipIfEmpty);
      $label = $field_definition->getLabel();

      foreach ($entity->get($field_name)->getValue() as $delta => $item) {
        if (array_key_exists('value', $item)) {
          $length = mb_strlen($item['value']);

          // Skip the validation.
          if ($length === 0 && $skip_if_empty) {
            continue;
          }

          if ($length < $min || $length > $max) {
            $this->context
              ->buildViolation($constraint->mustBeWithinRange)
              ->setParameter('%field', $label)
              ->setParameter('%min', $min)
              ->setParameter('%max', $max)
              ->atPath($delta . '.value')
              ->addViolation();
          }
        }
      }
    }
  }

}
