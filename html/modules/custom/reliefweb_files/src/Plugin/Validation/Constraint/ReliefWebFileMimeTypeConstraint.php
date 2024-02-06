<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * File extension constraint.
 *
 * @Constraint(
 *   id = "ReliefWebFileMimeType",
 *   label = @Translation("ReliefWeb File MimeType", context = "Validation"),
 *   type = "file"
 * )
 */
class ReliefWebFileMimeTypeConstraint extends Constraint {

  /**
   * The error message.
   *
   * @var string
   */
  public string $message = 'The file mime type is not %mimetype';

  /**
   * The allowed mime type.
   *
   * @var string
   */
  public string $mimetype;

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): string {
    return 'mimetype';
  }

}
