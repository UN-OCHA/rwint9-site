<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Check that a field has no more than the given number of values.
 *
 * @Constraint(
 *   id = "MaxNumberOfValues",
 *   label = @Translation("Max number of values", context = "Validation")
 * )
 */
class MaxNumberOfValuesConstraint extends Constraint {

  /**
   * Maximum number of values.
   *
   * @var max
   */
  public $max;

  /**
   * Error message when the number of values is above the allowed maximum.
   *
   * @var string
   */
  public $mustHaveLess = 'The %field cannot have more than %max values';

}
