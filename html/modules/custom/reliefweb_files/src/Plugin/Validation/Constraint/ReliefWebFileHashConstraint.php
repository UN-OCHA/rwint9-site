<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * File hash constraint.
 */
#[Constraint(
  id: 'ReliefWebFileHash',
  label: new TranslatableMarkup('ReliefWeb File Hash', [], ['context' => 'Validation']),
  type: 'file'
)]
class ReliefWebFileHashConstraint extends SymfonyConstraint {

  /**
   * Duplicate file error message.
   *
   * @var string
   */
  public string $duplicateFileError = 'Duplicate detected: file "@uuid" is already attached to "@label" (:url).';

  /**
   * Duplicate file form error message.
   *
   * @var string
   */
  public string $duplicateFileFormError = 'Duplicate detected: this file is already attached to <a href=":url" target="_blank">@label</a>.';

  /**
   * Missing file error message.
   *
   * @var string
   */
  public string $missingFileError = 'Unable to validate missing file %uri.';

  /**
   * Empty hash error message.
   *
   * @var string
   */
  public string $emptyHashError = 'Unable to calculate the hash of the file %uri.';

  /**
   * The field item the file is attached to.
   *
   * @var \Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile
   */
  public ReliefWebFile $fieldItem;

  /**
   * The entity the file is attached to.
   *
   * @var ?\Drupal\Core\Entity\EntityInterface
   */
  public ?EntityInterface $entity;

  /**
   * Whether to the constraint is applied in a form a or not.
   *
   * @var bool
   */
  public bool $inForm = TRUE;

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): string {
    return 'fieldItem';
  }

}
