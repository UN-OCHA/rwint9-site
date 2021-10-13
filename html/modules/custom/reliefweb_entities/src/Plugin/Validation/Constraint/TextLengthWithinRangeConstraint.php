<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Check the length of a text is with a with a range of number of characters.
 *
 * @Constraint(
 *   id = "TextLengthWithinRange",
 *   label = @Translation("Text length within range", context = "Validation")
 * )
 */
class TextLengthWithinRangeConstraint extends Constraint {

  /**
   * Minimum number of characters.
   *
   * @var min
   */
  public $min;

  /**
   * Maximum number of characters.
   *
   * @var max
   */
  public $max;

  /**
   * Whether to skip the validation when the text is empty.
   *
   * @var bool
   */
  public $skipIfEmpty = FALSE;

  /**
   * Error message when the length of the text field is not within the range.
   *
   * @var string
   */
  public $mustBeWithinRange = 'The %field number of characters must be with %min and %max';

}
