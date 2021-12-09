<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
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
    if ($item instanceof FieldItemListInterface && isset($constraint->min, $constraint->max)) {
      $min = (int) $constraint->min;
      $max = (int) $constraint->max;
      $skip_if_empty = !empty($constraint->skipIfEmpty);
      $label = $item->getFieldDefinition()->getLabel();

      foreach ($item as $delta => $field_item) {
        $value = $field_item->value;
        if (is_string($value)) {
          $length = mb_strlen($value);

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
