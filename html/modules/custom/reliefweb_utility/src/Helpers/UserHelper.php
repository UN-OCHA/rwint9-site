<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

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
  public static function userHasRoles(array $roles, ?AccountInterface $account = NULL, bool $all = FALSE): bool {
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

  /**
   * Get role names keyed by role ID.
   *
   * Replacement for deprecated user_role_names() function.
   *
   * @param bool $membersonly
   *   If TRUE, exclude the anonymous role.
   * @param string|null $permission
   *   If specified, only return roles that have this permission.
   *
   * @return array
   *   An array of role names keyed by role ID.
   */
  public static function getRoleNames(bool $membersonly = FALSE, ?string $permission = NULL): array {
    $roles = self::getRoles($membersonly, $permission);
    return array_map(function (RoleInterface $role): string {
      return $role->label();
    }, $roles);
  }

  /**
   * Get role entities.
   *
   * Replacement for deprecated user_roles() function.
   *
   * @param bool $membersonly
   *   If TRUE, exclude the anonymous role.
   * @param string|null $permission
   *   If specified, only return roles that have this permission.
   *
   * @return \Drupal\user\RoleInterface[]
   *   An array of role entities keyed by role ID.
   */
  public static function getRoles(bool $membersonly = FALSE, ?string $permission = NULL): array {
    $roles = Role::loadMultiple();

    if ($membersonly) {
      unset($roles[RoleInterface::ANONYMOUS_ID]);
    }

    if (!empty($permission)) {
      $roles = array_filter($roles, function (RoleInterface $role) use ($permission): bool {
        return $role->hasPermission($permission);
      });
    }

    return $roles;
  }

}
