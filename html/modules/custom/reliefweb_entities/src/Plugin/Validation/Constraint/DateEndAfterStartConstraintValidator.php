<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Drupal\reliefweb_utility\Helpers\DateHelper;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Date end after start constraint validator.
 */
class DateEndAfterStartConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($item, Constraint $constraint) {
    $field_definition = $item->getFieldDefinition();

    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->context->getRoot()->getValue();
    $field_name = $field_definition->getName();

    if ($entity->hasField($field_name)) {
      $label = $field_definition->getLabel();

      foreach ($entity->get($field_name)->getValue() as $delta => $item) {
        if (array_key_exists('value', $item) && array_key_exists('end_value', $item)) {
          $start = DateHelper::getDateTimeStamp($item['value']);
          $end = DateHelper::getDateTimeStamp($item['end_value']);

          if (!empty($start) && !empty($end) && $end < $start) {
            $this->context
              ->buildViolation($constraint->mustBeAfterStart)
              ->setParameter('%field', $label)
              ->atPath($delta . '.end_value')
              ->addViolation();
          }
        }
      }
    }
  }

}
