<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Add a report using browser.
 */
class RwReportBase extends ExistingSiteBase {

  /**
   * Create terms.
   */
  protected function createTermIfNeeded($vocabulary, $id, $title, array $extra = []) : Term {
    if ($term = Term::load($id)) {
      return $term;
    }

    $term = Term::create([
      'vid' => $vocabulary,
      'tid' => $id,
      'name' => $title,
    ] + $extra);
    $term->save();

    return $term;
  }

  /**
   * Create user if needed.
   */
  protected function createUserIfNeeded($id, $name, array $extra = []) : User {
    if ($user = User::load($id)) {
      return $user;
    }

    $user = User::create([
      'uid' => $id,
      'name' => $name,
      'mail' => $this->randomMachineName(32) . '@localhost.localdomain',
      'status' => 1,
    ] + $extra);
    $user->save();

    return $user;
  }

  /**
   * Logs in a user using the Mink controlled browser.
   *
   * If a user is already logged in, then the current user is logged out before
   * logging in the specified user.
   *
   * Note that neither the current user nor the passed-in user object is
   * populated with data of the logged in user. If you need full access to the
   * user object after logging in, it must be updated manually. If you also need
   * access to the plain-text password of the user (set by drupalCreateUser()),
   * e.g. to log in the same user again, then it must be re-assigned manually.
   * For example:
   * @code
   *   // Create a user.
   *   $account = $this->drupalCreateUser([]);
   *   $this->drupalLogin($account);
   *   // Load real user object.
   *   $pass_raw = $account->passRaw;
   *   $account = User::load($account->id());
   *   $account->passRaw = $pass_raw;
   * @endcode
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User object representing the user to log in.
   *
   * @see drupalCreateUser()
   */
  protected function drupalLogin(AccountInterface $account) {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    if ($this->useOneTimeLoginLinks) {
      // Reload to get latest login timestamp.
      $storage = \Drupal::entityTypeManager()->getStorage('user');
      /** @var \Drupal\user\UserInterface $accountUnchanged */
      $accountUnchanged = $storage->loadUnchanged($account->id());
      $login = user_pass_reset_url($accountUnchanged) . '/login?destination=user/' . $account->id();
      $this->drupalGet($login);
    }
    else {
      $this->drupalGet(Url::fromRoute('user.login'));
      $this->submitForm([
        'name' => $account->getAccountName(),
        'pass' => $account->passRaw,
      ], 'Log in');
    }

    // @see ::drupalUserIsLoggedIn()
    $account->sessionId = $this->getSession()->getCookie(\Drupal::service('session_configuration')->getOptions(\Drupal::request())['name']);

    $this->loggedInUser = $account;
    $this->container->get('current_user')->setAccount($account);
  }
}
