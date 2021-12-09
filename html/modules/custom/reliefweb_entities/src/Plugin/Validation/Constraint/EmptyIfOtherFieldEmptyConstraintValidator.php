<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Empty if other field is empty constraint validator.
 */
class EmptyIfOtherFieldEmptyConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($item, Constraint $constraint) {
    $other_field_name = $constraint->otherFieldName ?? '';

    if ($item instanceof FieldItemListInterface && !empty($other_field_name)) {
      $entity = $item->getEntity();

      if ($entity->hasField($other_field_name) && $entity->get($other_field_name)->isEmpty() && !$item->isEmpty()) {
        $label = $item
          ->getFieldDefinition()
          ->getLabel();

        $other_label = $entity
          ->get($other_field_name)
          ->getFieldDefinition()
          ->getLabel();

        $this->context
          ->buildViolation($constraint->mustBeEmpty)
          ->setParameter('%field', $label)
          ->setParameter('%other_field', $other_label)
          ->addViolation();
      }
    }
  }

}
