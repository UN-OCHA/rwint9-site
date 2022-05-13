<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Session\AccountInterface;

/**
 * Helper to retrieve information about users.
 */
class UserHelper {

  /**
   * Check if the account has the current role.
   *
   * @param array $roles
   *   Role machine names.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   User account. Defaults to the current user if NULL.
   * @param bool $all
   *   If TRUE, then check that the user has all the given role otherwise
   *   only check if the user has one of the roles.
   *
   * @return bool
   *   TRUE if the user has the given role.
   */
  public static function userHasRoles(array $roles, ?AccountInterface $account = NULL, $all = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    // Always return TRUE for the administrator user.
    if ($account->id() == 1) {
      return TRUE;
    }
    // Check the roles.
    elseif (!empty($roles)) {
      $roles = array_map('strtolower', $roles);
      $account_roles = array_map('strtolower', $account->getRoles());
      $intersection = count(array_intersect($roles, $account_roles));
      return $all ? count($roles) === $intersection : $intersection > 0;
    }
    return FALSE;
  }

}
