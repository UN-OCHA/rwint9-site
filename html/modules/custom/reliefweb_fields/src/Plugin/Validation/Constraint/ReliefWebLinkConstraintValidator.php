<?php

namespace Drupal\reliefweb_fields\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Constraint validator for links receiving data allowed by its settings.
 */
class ReliefWebLinkConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    if (isset($value)) {
      $uri_is_valid = TRUE;

      /** @var \Drupal\reliefweb_fields\Plugin\Field\FieldType\ReliefWebLinks $link_item */
      $link_item = $value;
      $internal = $link_item->getFieldDefinition()->getSetting('internal');

      if (!empty($link_item->url)) {
        if (!$internal) {
          $uri_is_valid = filter_var($link_item->url, FILTER_VALIDATE_URL);
        }

        if (!$uri_is_valid) {
          $this->context->addViolation($constraint->message, ['@url' => $link_item->url]);
        }
      }
    }
  }

}
