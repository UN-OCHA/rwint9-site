<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Drupal\file\Plugin\Validation\Constraint\BaseFileConstraintValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ReliefWeb file mimetype constraint.
 */
class ReliefWebFileMimeTypeConstraintValidator extends BaseFileConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    $file = $this->assertValueIsFile($value);
    if (!$constraint instanceof ReliefWebFileMimeTypeConstraint) {
      throw new UnexpectedTypeException($constraint, ReliefWebFileMimeTypeConstraint::class);
    }

    if ($file->getMimeType() !== $constraint->mimetype) {
      $this->context->addViolation($constraint->message, [
        '%mimetype' => $mimetype,
      ]);
    }
  }

}
