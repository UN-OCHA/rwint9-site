<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Plugin\Validation\Constraint\BaseFileConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ReliefWeb file hash constraint.
 */
class ReliefWebFileHashConstraintValidator extends BaseFileConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    $file = $this->assertValueIsFile($value);
    if (!$constraint instanceof ReliefWebFileHashConstraint) {
      throw new UnexpectedTypeException($constraint, ReliefWebFileHashConstraint::class);
    }

    $uri = $file->getFileUri();
    if (!file_exists($uri)) {
      $this->context->addViolation($constraint->missingFileError, [
        '%uri' => $uri,
      ]);
      return;
    }

    $field_item = $constraint->fieldItem;
    $field_definition = $field_item->getFieldDefinition();
    $field_name = $field_definition->getName();
    $entity_type_id = $field_definition->getTargetEntityTypeId();

    // Compute the file hash.
    $hash = $field_item->calculateFileHashFromUri($uri);
    if (empty($hash)) {
      $this->context->addViolation($constraint->emptyHashError, [
        '%uri' => $uri,
      ]);
      return;
    }

    // Retrieve entities with the same file hash.
    $entities = $this->entityTypeManager
      ->getStorage($entity_type_id)
      ->loadByProperties([
        $field_name . '.file_hash' => $hash,
      ]);

    if (empty($entities)) {
      return;
    }

    $entity_id = $constraint->entity?->id();
    $url_options = ['absolute' => TRUE];

    // If there is an entity other than the one the file is attached to,
    // then flag this as a duplication error.
    ksort($entities);
    foreach ($entities as $entity) {
      if ($entity->id() !== $entity_id) {
        $this->context->setConstraint($constraint);
        if ($constraint->inForm) {
          $this->context->addViolation($constraint->duplicateFileFormError, [
            '@label' => $entity->label(),
            ':url' => $entity->toUrl(options: $url_options)->toString(),
          ]);
        }
        else {
          $this->context->addViolation($constraint->duplicateFileError, [
            '@uuid' => $field_item->getUuid(),
            '@label' => $entity->label(),
            ':url' => $entity->toUrl(options: $url_options)->toString(),
          ]);
        }
        return;
      }
    }
  }

}
