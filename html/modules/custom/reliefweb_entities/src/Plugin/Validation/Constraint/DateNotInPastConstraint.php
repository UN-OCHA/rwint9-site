<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Check that a date is not in the past.
 *
 * @Constraint(
 *   id = "DateNotInPast",
 *   label = @Translation("Date not in the past", context = "Validation")
 * )
 */
class DateNotInPastConstraint extends Constraint {

  /**
   * Entity status for which to check the constraint.
   *
   * @var array
   */
  public $statuses = [];

  /**
   * User permission for which to bypass the validation.
   *
   * @var string
   */
  public $permission = '';

  /**
   * Error message when the date is in the past.
   *
   * @var string
   */
  public $mustNotBeInPast = 'The %field cannot be in the past';

  /**
   * Error message when the date field property is in the past.
   *
   * @var string
   */
  public $mustNotBeInPastProperty = 'The %field %property cannot be in the past';

}
