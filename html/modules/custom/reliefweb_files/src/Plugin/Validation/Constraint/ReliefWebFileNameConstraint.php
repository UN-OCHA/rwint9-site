<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * File extension constraint.
 *
 * @Constraint(
 *   id = "ReliefWebFileName",
 *   label = @Translation("ReliefWeb File MimeType", context = "Validation"),
 *   type = "file"
 * )
 */
class ReliefWebFileNameConstraint extends Constraint {

  /**
   * The expected file extension.
   *
   * @var string
   */
  public string $expectedExtension = '';

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): ?string {
    return 'expectedExtension';
  }

}
