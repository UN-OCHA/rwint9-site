<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Check that the end date of a date range is after the start date.
 *
 * @Constraint(
 *   id = "DateEndAfterStart",
 *   label = @Translation("Date end after start", context = "Validation")
 * )
 */
class DateEndAfterStartConstraint extends Constraint {

  /**
   * Error message when the end date is before the start date.
   *
   * @var string
   */
  public $mustBeAfterStart = 'The %field end date must be after the start date';

}
