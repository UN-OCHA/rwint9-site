<?php

declare(strict_types=1);

namespace Drupal\reliefweb_users\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\user\UserInterface;

/**
 * Service for managing user role assignments.
 */
class UserRoleAssignment {

  /**
   * Constructs a UserRoleAssignment object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $userPostingRightsManager
   *   The user posting rights manager service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly UserPostingRightsManagerInterface $userPostingRightsManager,
  ) {}

  /**
   * Assign roles to a user based on their eligibility.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to assign roles to.
   *
   * @return array
   *   Array of roles that were assigned. Empty array if no roles were assigned.
   */
  public function assignEligibleRoles(UserInterface $user): array {
    $assigned_roles = [];

    // Skip anonymous and system users.
    if ($user->isAnonymous() || $user->id() <= 2) {
      return $assigned_roles;
    }

    // Check and assign submitter role.
    if ($this->shouldAssignSubmitterRole($user)) {
      $user->addRole('submitter');
      $assigned_roles[] = 'submitter';
    }

    // Check and assign advertiser role.
    if ($this->shouldAssignAdvertiserRole($user)) {
      $user->addRole('advertiser');
      $assigned_roles[] = 'advertiser';
    }

    // Save the user once with all role changes.
    if (!empty($assigned_roles)) {
      return $this->saveUserWithRoles($user, $assigned_roles);
    }

    // No roles were assigned.
    return [];
  }

  /**
   * Check if the submitter role should be assigned to the user.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   *
   * @return bool
   *   TRUE if the submitter role should be assigned, FALSE otherwise.
   */
  public function shouldAssignSubmitterRole(UserInterface $user): bool {
    // Skip if the submitter role does not exist.
    if (!$this->roleExists('submitter')) {
      return FALSE;
    }

    // Skip if the user already has an editing role.
    if ($user->hasRole('editor') || $user->hasRole('contributor') || $user->hasRole('submitter')) {
      return FALSE;
    }

    // Skip if the user previously had the role assigned (per history table).
    if ($this->wasRolePreviouslyAssigned($user, 'submitter')) {
      return FALSE;
    }

    // Support for legacy accounts.
    $legacy = $this->state->get('reliefweb_users_submitter_support_legacy_accounts', TRUE);

    // Check if the user's email domain is privileged, allowing automatic
    // assignment of the submitter role, or has posting rights for reports or
    // has posted reports.
    return $this->isUserEmailDomainPrivileged($user) ||
      $this->hasPostingRights($user, ['report']) ||
      ($legacy && $this->hasPostedContent($user, ['report']));
  }

  /**
   * Check if the advertiser role should be assigned to the user.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   *
   * @return bool
   *   TRUE if the advertiser role should be assigned, FALSE otherwise.
   */
  public function shouldAssignAdvertiserRole(UserInterface $user): bool {
    // Skip if the advertiser role does not exist.
    if (!$this->roleExists('advertiser')) {
      return FALSE;
    }

    // Skip if the user already has the advertiser role or is an editor.
    if ($user->hasRole('editor') || $user->hasRole('advertiser')) {
      return FALSE;
    }

    // Skip if the user previously had the role assigned (per history table).
    if ($this->wasRolePreviouslyAssigned($user, 'advertiser')) {
      return FALSE;
    }

    // Support for legacy accounts.
    $legacy = $this->state->get('reliefweb_users_advertiser_support_legacy_accounts', TRUE);

    // Check if the user's email domain is privileged, allowing automatic
    // assignment of the advertiser role, or has job/training posting rights for
    // any source, or has already posted jobs or training.
    return $this->isUserEmailDomainPrivileged($user) ||
      $this->hasPostingRights($user, ['job', 'training']) ||
      ($legacy && $this->hasPostedContent($user, ['job', 'training']));
  }

  /**
   * Check if user's email domain is privileged.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   *
   * @return bool
   *   TRUE if the user's email domain is privileged, FALSE otherwise.
   */
  public function isUserEmailDomainPrivileged(UserInterface $user): bool {
    // Retrieve the user email address.
    $email = $user->getEmail();
    if (empty($email) || strpos($email, '@') === FALSE) {
      return FALSE;
    }

    // Retrieve the list of privileged domains for automatic assignment of the
    // submitter role.
    $domains = $this->state->get('reliefweb_users_privileged_domains', ['un.org']);
    if (empty($domains)) {
      return FALSE;
    }

    // Normalize the domains to lowercase.
    $domains = array_map('mb_strtolower', $domains);

    // Extract the email domain and normalize it to lowercase.
    [, $domain] = explode('@', $email, 2);
    $domain = mb_strtolower(trim($domain));

    // Check if the email domain is allowed.
    if (!in_array($domain, $domains)) {
      return FALSE;
    }

    // Check if the user has a connected Entra ID account.
    $check_entraid = $this->state->get('reliefweb_users_submitter_check_entraid_for_assignment', TRUE);
    if ($check_entraid) {
      $uid = $this->database
        ->select('authmap', 'am')
        ->fields('am', ['uid'])
        ->condition('am.provider', 'openid_connect.entraid', '=')
        ->condition('am.uid', $user->id(), '=')
        ->execute()
        ?->fetchField();
      return !empty($uid);
    }

    return TRUE;
  }

  /**
   * Check if the user has posting rights for the given bundles.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   * @param array $bundles
   *   Bundles to check. Defaults to all bundles.
   *
   * @return bool
   *   TRUE if the user has posting rights for the given bundles, FALSE
   *   otherwise.
   */
  public function hasPostingRights(UserInterface $user, array $bundles = []): bool {
    // Default to all bundles.
    if (empty($bundles)) {
      $bundles = ['job', 'training', 'report'];
    }

    // Map the bundles to the bundle rights (2 = allowed, 3 = trusted).
    $bundle_rights = [];
    foreach ($bundles as $bundle) {
      $bundle_rights[$bundle] = [2, 3];
    }

    // Check if the user has job/training/report posting rights for any source.
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser($user, $bundle_rights, 'OR', 1);
    return !empty($sources);
  }

  /**
   * Check if the user has posted content for the given bundles.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   * @param array $bundles
   *   Bundles to check. Defaults to all bundles.
   *
   * @return bool
   *   TRUE if the user has posted content for the given bundles, FALSE
   *   otherwise.
   */
  public function hasPostedContent(UserInterface $user, array $bundles = []): bool {
    // Default to all bundles.
    if (empty($bundles)) {
      $bundles = ['job', 'training', 'report'];
    }

    // Check if the user has already posted content for the given bundles.
    $entity_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundles, 'IN')
      ->condition('uid', $user->id(), '=')
      ->execute();
    return !empty($entity_ids);
  }

  /**
   * Check if a role exists.
   *
   * @param string $role
   *   Role to check.
   *
   * @return bool
   *   TRUE if the role exists, FALSE otherwise.
   */
  public function roleExists(string $role): bool {
    $role_entity = $this->entityTypeManager->getStorage('user_role')->load($role);
    return !empty($role_entity);
  }

  /**
   * Save user with assigned roles using system user context.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   * @param array $assigned_roles
   *   Array of roles that were assigned.
   *
   * @return array
   *   Array of roles that were assigned. Empty array if no roles were assigned.
   */
  protected function saveUserWithRoles(UserInterface $user, array $assigned_roles): array {
    // Load the system user.
    $system_user = $this->entityTypeManager->getStorage('user')->load(2);
    if (empty($system_user) || !($system_user instanceof UserInterface)) {
      $logger = $this->loggerFactory->get('reliefweb_users');
      $logger->error(strtr('System user not found. Unable to assign role(s): @roles to user @id.', [
        '@roles' => implode(', ', $assigned_roles),
        '@id' => $user->id(),
      ]));

      // No roles were assigned.
      return [];
    }

    // Switch the current user to the system user temporarily so that the
    // user history record shows the system user as modification user.
    // @see reliefweb_user_history_user_entity_to_record().
    $this->accountSwitcher->switchTo($system_user);

    try {
      // Save the user with all role changes.
      $user->save();

      $logger = $this->loggerFactory->get('reliefweb_users');
      $logger->info(strtr('Assigned role(s): @roles to user @id.', [
        '@roles' => implode(', ', $assigned_roles),
        '@id' => $user->id(),
      ]));
    }
    catch (\Exception $exception) {
      $logger = $this->loggerFactory->get('reliefweb_users');
      $logger->error(strtr('Unable to assign role(s): @roles to user @id: @error.', [
        '@roles' => implode(', ', $assigned_roles),
        '@id' => $user->id(),
        '@error' => $exception->getMessage(),
      ]));

      // No roles were assigned.
      return [];
    }
    finally {
      // Make sure we switch back to the current user.
      $this->accountSwitcher->switchBack();
    }

    return $assigned_roles;
  }

  /**
   * Check if a role was previously assigned (recorded in history table).
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   * @param string $role
   *   Role to check.
   *
   * @return bool
   *   TRUE if the role was previously assigned, FALSE otherwise.
   */
  public function wasRolePreviouslyAssigned(UserInterface $user, string $role): bool {
    $query = $this->database
      ->select('reliefweb_user_history', 'h')
      ->fields('h', ['modification_id'])
      ->condition('h.uid', $user->id(), '=')
      ->condition('h.roles', NULL, 'IS NOT NULL');

    // Roles are stored as comma-separated values. Use a safe contains check
    // that matches full role names only.
    $query->where("CONCAT(',', h.roles, ',') LIKE :role", [
      ':role' => '%,' . $role . ',%',
    ]);

    $found = $query->execute()?->fetchField();
    return !empty($found);
  }

}
