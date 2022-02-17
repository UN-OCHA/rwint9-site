<?php

namespace Drupal\reliefweb_users\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Check access to system user pages.
 */
class SystemUserAccessCheck implements AccessInterface {

  /**
   * Check access to system user pages.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user entity for the user form.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, AccountInterface $user) {
    return AccessResult::allowedIf($user->id() > 2 || $account->id() === $user->id());
  }

}
