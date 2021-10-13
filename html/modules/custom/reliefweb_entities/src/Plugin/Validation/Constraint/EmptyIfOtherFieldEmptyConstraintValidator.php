<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

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
    $field_definition = $item->getFieldDefinition();

    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->context->getRoot()->getValue();
    $field_name = $field_definition->getName();
    $other_field_name = $constraint->otherFieldName ?? '';

    if (!empty($other_field_name) && $entity->hasField($field_name) && $entity->hasField($other_field_name)) {
      if ($entity->get($other_field_name)->isEmpty() && !$entity->get($field_name)->isEmpty()) {
        $label = $entity
          ->get($field_name)
          ->getFieldDefinition()
          ->getLabel();

        $other_label = $entity
          ->get($field_name)
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
