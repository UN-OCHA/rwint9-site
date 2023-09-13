<?php

namespace Drupal\reliefweb_entities\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Date not in the past constraint validator.
 */
class DateNotInPastConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new JobClosingDateConstraintValidator.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($item, Constraint $constraint) {
    if ($item instanceof FieldItemListInterface && $item->getEntity() instanceof EntityModeratedInterface) {
      // Conditions to perform the validation.
      $check = TRUE;
      if (!empty($constraint->permission)) {
        $check = $check && !$this->currentUser->hasPermission($constraint->permission);
      }
      if (!empty($constraint->statuses)) {
        $check = $check && in_array($item->getEntity()->getModerationStatus(), $constraint->statuses);
      }

      // Check that the date properties are in the future.
      if ($check) {
        $field_definition = $item->getFieldDefinition();
        $label = $field_definition->getLabel();
        $storage_definition = $field_definition->getFieldStorageDefinition();

        $message = $constraint->mustNotBeInPast;
        if ($field_definition->getType() === 'daterange') {
          $message = $constraint->mustNotBeInPastProperty;
        }

        foreach ($item->getValue() as $delta => $field_item) {
          foreach (['value', 'end_value'] as $property) {
            if (array_key_exists($property, $field_item) && $this->dateIsInPast($field_item[$property])) {
              $property_label = $storage_definition
                ->getPropertyDefinition($property)
                ->getLabel();

              $this->context
                ->buildViolation($message)
                ->setParameter('%field', $label)
                ->setParameter('%property', $property_label)
                ->atPath($delta . '.' . $property)
                ->addViolation();
            }
          }
        }
      }
    }
  }

  /**
   * Check if the given date is in the past.
   *
   * @param mixed $date
   *   Date value (can be a DateTime object, a string etc.)
   *
   * @return bool
   *   TRUE if the date is in the past.
   */
  protected function dateIsInPast($date) {
    $timestamp = DateHelper::getDateTimeStamp($date);
    return empty($timestamp) || ($timestamp < gmmktime(0, 0, 0));
  }

}
