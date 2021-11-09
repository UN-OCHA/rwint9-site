<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Check that a field is empty when another field is empty.
 *
 * @Constraint(
 *   id = "EmptyIfOtherFieldEmpty",
 *   label = @Translation("Empty if other field empty", context = "Validation")
 * )
 */
class EmptyIfOtherFieldEmptyConstraint extends Constraint {

  /**
   * Other field name to check for emptiness.
   *
   * @var string
   */
  public $otherFieldName = '';

  /**
   * Error message when a field is not empty while another field is.
   *
   * @var string
   */
  public $mustBeEmpty = 'The %field cannot have a value when %other_field has no value';

}
