<?php

declare(strict_types=1);

namespace Drupal\reliefweb_guidelines;

use Drupal\Core\Session\AccountInterface;

/**
 * Guideline load trait.
 */
trait GuidelineLoadTrait {

  /**
   * Get the IDs of the guideline lists accessible to the user.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   User.
   *
   * @return array
   *   IDs of the guideline lists accessible to the user.
   */
  public function getAccessibleGuidelineListIds(AccountInterface $user): array {
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');

    $guideline_list_query = $storage
      ->getQuery()
      ->condition('status', 1, '=')
      ->condition('vid', 'guideline_list', '=')
      ->sort('weight', 'ASC')
      ->accessCheck(TRUE);

    $user_roles = $user->getRoles();

    // If the user is not an administrator, filter the guideline lists based on
    // the user's roles.
    if (!$this->isUserAdmin($user)) {
      $role_conditions = $guideline_list_query->orConditionGroup();
      $role_conditions->condition('field_role', $user_roles, 'IN');
      // If the user has the editor role, allow access to guideline lists
      // without a role.
      if (in_array('editor', $user_roles)) {
        $role_conditions->notExists('field_role');
      }
      $guideline_list_query->condition($role_conditions);
    }

    return $guideline_list_query->execute();
  }

  /**
   * Check if a user is an administrator.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   User.
   *
   * @return bool
   *   TRUE if administrator.
   */
  public function isUserAdmin(AccountInterface $user): bool {
    return $user->id() == 1 || in_array('administrator', $user->getRoles());
  }

}
