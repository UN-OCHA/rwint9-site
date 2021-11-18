<?php

namespace Drupal\extended_field_description;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for the Description entity entity.
 *
 * @see \Drupal\extended_field_description\Entity\DescriptionEntity.
 */
class DescriptionEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\extended_field_description\Entity\DescriptionEntityInterface $entity */

    switch ($operation) {

      case 'view':

        if (!$entity->isPublished()) {
          return AccessResult::allowedIfHasPermission($account, 'view unpublished description entity entities');
        }


        return AccessResult::allowedIfHasPermission($account, 'view published description entity entities');

      case 'update':

        return AccessResult::allowedIfHasPermission($account, 'edit description entity entities');

      case 'delete':

        return AccessResult::allowedIfHasPermission($account, 'delete description entity entities');
    }

    // Unknown operation, no opinion.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'add description entity entities');
  }


}
