<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Requires a future date when the entity is published.
 *
 * @Constraint(
 *   id = "FutureDateWhenPublished",
 *   label = @Translation("Future date when published", context = "Validation"),
 *   type = "string"
 * )
 */
class FutureDateWhenPublished extends Constraint {

  /**
   * Error message.
   *
   * @var string
   */
  public $message = '%field field has to be in the future.';

}
