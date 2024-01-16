<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Drupal\file\Plugin\Validation\Constraint\BaseFileConstraintValidator;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ReliefWeb file mimetype constraint.
 */
class ReliefWebFileNameConstraintValidator extends BaseFileConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    $file = $this->assertValueIsFile($value);
    if (!$constraint instanceof ReliefWebFileNameConstraint) {
      throw new UnexpectedTypeException($constraint, ReliefWebFileNameConstraint::class);
    }

    $error = ReliefWebFile::validateFileName($file->getFileName(), $constraint->expectedExtension);
    if (!empty($error)) {
      $this->context->addViolation($error);
    }
  }

}
