<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Drupal\Core\Entity\EntityPublishedInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\reliefweb_entities\EntityModeratedInterface;

/**
 * Validates the FutureDateWhenPublished constraint.
 */
class FutureDateWhenPublishedValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items =& $value;
    /** @var \Drupal\node\Entity\Node $entity */
    $entity = $this->context->getRoot()->getValue();

    if ($entity instanceof EntityPublishedInterface) {
      if ($entity instanceof EntityModeratedInterface) {
        if ($entity->getModerationStatus() === 'published' || $entity->getModerationStatus() === 'pending') {
          $date = $items->value;
          if (empty($date) || strtotime($date) < gmmktime(0, 0, 0)) {
            $this->context->addViolation($constraint->message, [
              '%field' => $items->getFieldDefinition()->getLabel(),
            ]);
          }
        }
      }
    }
  }

}
