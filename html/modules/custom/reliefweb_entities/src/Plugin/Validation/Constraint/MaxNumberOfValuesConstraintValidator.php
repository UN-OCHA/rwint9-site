<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Max number of values constraint validator.
 */
class MaxNumberOfValuesConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($item, Constraint $constraint) {
    if ($item instanceof FieldItemListInterface && isset($constraint->max)) {
      $max = (int) $constraint->max;
      $label = $item->getFieldDefinition()->getLabel();

      if ($item->count() > $max) {
        $this->context
          ->buildViolation($constraint->mustHaveLess)
          ->setParameter('%field', $label)
          ->setParameter('%max', $max)
          ->addViolation();
      }
    }
  }

}
