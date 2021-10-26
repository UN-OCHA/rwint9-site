<?php

namespace Drupal\reliefweb_fields\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validation constraint for links receiving data allowed by its settings.
 *
 * @Constraint(
 *   id = "ReliefWebLink",
 *   label = @Translation("Link data valid for link type.", context = "Validation"),
 * )
 */
class ReliefWebLinkConstraint extends Constraint {

  /**
   * The error message.
   *
   * @var string
   */
  public $message = "The path '@url' is invalid.";

}
