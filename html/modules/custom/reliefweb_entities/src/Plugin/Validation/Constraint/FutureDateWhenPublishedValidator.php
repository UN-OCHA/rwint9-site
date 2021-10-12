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

    \Drupal::logger('my_module')->notice('called');

    if ($entity instanceof EntityPublishedInterface) {
      if ($entity instanceof EntityModeratedInterface) {
        \Drupal::logger('my_status')->notice($entity->getModerationStatus());
        if ($entity->getModerationStatus() === 'published' || $entity->getModerationStatus() === 'pending') {
          $date = $items->value;
          \Drupal::logger('my_date')->notice($date);
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
