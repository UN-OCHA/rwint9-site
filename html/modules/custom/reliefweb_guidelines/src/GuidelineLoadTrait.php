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
    $storage = $this->entityTypeManager()->getStorage('guideline');

    // Retrieve the guideline lists accessible to the current user.
    $guideline_list_query = $storage
      ->getQuery()
      ->condition('status', 1, '=')
      ->condition('type', 'guideline_list', '=')
      ->sort('type', 'DESC')
      ->sort('weight', 'ASC')
      ->accessCheck(TRUE);

    // Filter on the role(s) of the current user.
    $user_roles = $user->getRoles();

    // Skip the role filtering for admins.
    if ($user->id() != 1 && !in_array('adminitrator', $user_roles)) {
      $role_conditions = $guideline_list_query->orConditionGroup();
      $role_conditions->condition('field_role', $user_roles, 'IN');
      // Consider guideline lists without a role, guidelines for editors.
      if (in_array('editor', $user_roles)) {
        $role_conditions->notExists('field_role');
      }
      $guideline_list_query->condition($role_conditions);
    }

    // Retrieve the IDs of the guideline lists matching the criteria.
    $guideline_list_ids = $guideline_list_query->execute();

    return $guideline_list_ids;
  }

}
