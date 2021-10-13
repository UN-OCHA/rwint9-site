<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

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
    $field_definition = $item->getFieldDefinition();

    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->context->getRoot()->getValue();
    $field_name = $field_definition->getName();

    if ($entity->hasField($field_name) && isset($constraint->max)) {
      $max = (int) $constraint->max;
      $label = $field_definition->getLabel();

      $values = $entity->get($field_name)->getValue();
      if (count($values) > $max) {
        $this->context
          ->buildViolation($constraint->mustHaveLess)
          ->setParameter('%field', $label)
          ->setParameter('%max', $max)
          ->addViolation();
      }
    }
  }

}
