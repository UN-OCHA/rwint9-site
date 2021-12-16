<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
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
    if ($item instanceof FieldItemListInterface) {
      $label = $item->getFieldDefinition()->getLabel();

      foreach ($item->getValue() as $delta => $field_item) {
        if (array_key_exists('value', $field_item) && array_key_exists('end_value', $field_item)) {
          $start = DateHelper::getDateTimeStamp($field_item['value']);
          $end = DateHelper::getDateTimeStamp($field_item['end_value']);

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
