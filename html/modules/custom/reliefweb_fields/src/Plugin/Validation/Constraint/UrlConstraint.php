<?php

namespace Drupal\reliefweb_fields\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraints\Url;

/**
 * Url constraint.
 *
 * @Constraint(
 *   id = "Url",
 *   label = @Translation("Link data valid for link type.", context = "Validation"),
 * )
 */
class UrlConstraint extends Url {

  /**
   * The error message.
   *
   * @var string
   */
  public $message = "The url is not a valid external Url.";

  /**
   * {@inheritdoc}
   */
  public function validatedBy() {
    return '\Symfony\Component\Validator\Constraints\UrlValidator';
  }

}
