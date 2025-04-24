<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * File extension constraint.
 */
#[Constraint(
  id: 'ReliefWebFileRealMimeType',
  label: new TranslatableMarkup('ReliefWeb File Real MimeType', [], ['context' => 'Validation']),
  type: 'file'
)]
class ReliefWebFileRealMimeTypeConstraint extends SymfonyConstraint {

  /**
   * Mime type mismatch error message.
   *
   * @var string
   */
  public string $mimetypeMismatchError = 'Content type mismatch: This file claims to be %current_mimetype but is actually %content_mimetype.';

  /**
   * File missing error message.
   *
   * @var string
   */
  public string $missingFileError = 'Unable to validate missing file %uri.';

}
