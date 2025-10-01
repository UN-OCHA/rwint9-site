<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_users\ExistingSite\Service;

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\reliefweb_users\Service\UserRoleAssignment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the UserRoleAssignment service.
 */
#[CoversClass(UserRoleAssignment::class)]
#[Group('reliefweb_users')]
#[RunTestsInSeparateProcesses]
class UserRoleAssignmentTest extends ExistingSiteBase {

  /**
   * Source vocabulary.
   */
  protected Vocabulary $sourceVocabulary;

  /**
   * Test user for role assignment tests.
   */
  protected User $testUser;

  /**
   * Test user with un.org domain.
   */
  protected User $unUser;

  /**
   * Test user with different domain.
   */
  protected User $otherDomainUser;

  /**
   * Test source entity for posting rights tests.
   */
  protected Term $testSource;

  /**
   * Test source with only user posting rights.
   */
  protected Term $userOnlySource;

  /**
   * Test source with only domain posting rights.
   */
  protected Term $domainOnlySource;

  /**
   * Test source with no posting rights.
   */
  protected Term $noRightsSource;

  /**
   * UserRoleAssignment service.
   */
  protected UserRoleAssignment $userRoleAssignment;

  /**
   * Original state values to restore after tests.
   */
  protected array $originalStateValues = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create system user if it doesn't exist.
    $this->createSystemUserIfNotExists();

    // Create test users.
    $this->testUser = $this->createUser([], 'test_user', FALSE, [
      'mail' => 'test@example.com',
      'status' => 1,
    ]);

    $this->unUser = $this->createUser([], 'un_user', FALSE, [
      'mail' => 'user@un.org',
      'status' => 1,
    ]);

    $this->otherDomainUser = $this->createUser([], 'other_domain_user', FALSE, [
      'mail' => 'user@other.com',
      'status' => 1,
    ]);

    // Create roles if they don't exist.
    $this->createRoleIfNotExists('submitter');
    $this->createRoleIfNotExists('advertiser');
    $this->createRoleIfNotExists('editor');
    $this->createRoleIfNotExists('contributor');

    // Source vocabulary.
    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      // Create the source vocabulary if it doesn't exist.
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->sourceVocabulary->save();
      // Mark the vocabulary for cleanup.
      $this->markEntityForCleanup($this->sourceVocabulary);
    }

    // Create a test source with both user and domain posting rights.
    $this->testSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source - Mixed Rights',
      'field_user_posting_rights' => [
        [
          'id' => $this->testUser->id(),
          // Allowed for job.
          'job' => 2,
          // Trusted for training.
          'training' => 3,
          // Blocked for report.
          'report' => 1,
        ],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Allowed for job.
          'job' => 2,
          // Allowed for training.
          'training' => 2,
          // Unverified for report.
          'report' => 0,
        ],
      ],
    ]);

    // Create a source with only user posting rights.
    $this->userOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'User Only Source',
      'field_user_posting_rights' => [
        [
          'id' => $this->testUser->id(),
          // Trusted for all content types.
          'job' => 3,
          'training' => 3,
          'report' => 3,
        ],
      ],
    ]);

    // Create a source with only domain posting rights.
    $this->domainOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Domain Only Source',
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Allowed for all content types.
          'job' => 2,
          'training' => 2,
          'report' => 2,
        ],
      ],
    ]);

    // Create a source with no posting rights.
    $this->noRightsSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'No Rights Source',
    ]);

    // Get the service.
    $this->userRoleAssignment = \Drupal::service('reliefweb_users.user_role_assignment');

    // Store original state values and set up test configuration.
    $this->storeOriginalStateValues();
    $this->configureState();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Reset static cache.
    drupal_static_reset('reliefweb_moderation_getUserPostingRights');

    // Restore original state values.
    $this->restoreOriginalStateValues();

    parent::tearDown();
  }

  /**
   * Store original state values before tests.
   */
  protected function storeOriginalStateValues(): void {
    $state = \Drupal::state();
    $state_keys = [
      'reliefweb_users_submitter_support_legacy_accounts',
      'reliefweb_users_submitter_allowed_domains',
      'reliefweb_users_submitter_check_entraid_for_assignment',
      'reliefweb_users_advertiser_support_legacy_accounts',
    ];

    foreach ($state_keys as $key) {
      $value = $state->get($key);
      if ($value !== NULL) {
        $this->originalStateValues[$key] = $value;
      }
    }
  }

  /**
   * Restore original state values after tests.
   */
  protected function restoreOriginalStateValues(): void {
    $state = \Drupal::state();
    $state_keys = [
      'reliefweb_users_submitter_support_legacy_accounts',
      'reliefweb_users_submitter_allowed_domains',
      'reliefweb_users_submitter_check_entraid_for_assignment',
      'reliefweb_users_advertiser_support_legacy_accounts',
    ];

    foreach ($state_keys as $key) {
      if (array_key_exists($key, $this->originalStateValues)) {
        // Restore original value.
        $state->set($key, $this->originalStateValues[$key]);
      }
      else {
        // Delete the key if it didn't exist originally.
        $state->delete($key);
      }
    }
  }

  /**
   * Configure state for testing.
   */
  protected function configureState(): void {
    $state = \Drupal::state();

    // Configure submitter role settings.
    $state->set('reliefweb_users_submitter_support_legacy_accounts', TRUE);
    $state->set('reliefweb_users_submitter_allowed_domains', ['un.org']);
    $state->set('reliefweb_users_submitter_check_entraid_for_assignment', FALSE);

    // Configure advertiser role settings.
    $state->set('reliefweb_users_advertiser_support_legacy_accounts', TRUE);
  }

  /**
   * Create a role if it doesn't exist.
   *
   * @param string $role_id
   *   The role ID.
   */
  protected function createRoleIfNotExists(string $role_id): void {
    $role = Role::load($role_id);
    if (!$role) {
      $role = Role::create([
        'id' => $role_id,
        'label' => ucfirst($role_id),
      ]);
      $role->save();
      // Mark the role for cleanup.
      $this->markEntityForCleanup($role);
    }
  }

  /**
   * Create the system user if it doesn't exist.
   */
  protected function createSystemUserIfNotExists(): void {
    $system_user = User::load(2);
    if (!$system_user) {
      $system_user = User::create([
        'uid' => 2,
        'name' => 'system',
        'mail' => 'system@reliefweb.int',
        'status' => 1,
      ]);
      $system_user->save();
      // Mark the system user for cleanup.
      $this->markEntityForCleanup($system_user);
    }
  }

  /**
   * Test assignEligibleRoles method.
   */
  public function testAssignEligibleRoles(): void {
    // Test with user who should get both roles.
    $user = $this->createUser([], 'eligible_user', FALSE, [
      'mail' => 'eligible@un.org',
      'status' => 1,
    ]);

    // Create some content for the user to make them eligible for advertiser
    // role.
    $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'uid' => $user->id(),
    ]);

    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($user);

    $this->assertContains('submitter', $assigned_roles, 'User should be assigned submitter role');
    $this->assertContains('advertiser', $assigned_roles, 'User should be assigned advertiser role');

    // Verify the roles were actually assigned.
    $user = User::load($user->id());
    $this->assertTrue($user->hasRole('submitter'), 'User should have submitter role');
    $this->assertTrue($user->hasRole('advertiser'), 'User should have advertiser role');
  }

  /**
   * Test assignEligibleRoles with user who should get no roles.
   */
  public function testAssignEligibleRolesNoRoles(): void {
    // Test with user who should get no roles.
    $user = $this->createUser([], 'no_roles_user', FALSE, [
      'mail' => 'no_roles@other.com',
      'status' => 1,
    ]);

    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($user);

    $this->assertEmpty($assigned_roles, 'User should not be assigned any roles');
  }

  /**
   * Test assignEligibleRoles with anonymous user.
   */
  public function testAssignEligibleRolesAnonymous(): void {
    $anonymous_user = User::getAnonymousUser();
    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($anonymous_user);

    $this->assertEmpty($assigned_roles, 'Anonymous user should not be assigned any roles');
  }

  /**
   * Test assignEligibleRoles with system user.
   */
  public function testAssignEligibleRolesSystemUser(): void {
    $system_user = User::load(1);
    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($system_user);

    $this->assertEmpty($assigned_roles, 'System user should not be assigned any roles');
  }

  /**
   * Test shouldAssignSubmitterRole method.
   */
  public function testShouldAssignSubmitterRole(): void {
    // Test with user who should get submitter role (domain-based).
    $this->assertTrue(
      $this->userRoleAssignment->shouldAssignSubmitterRole($this->unUser),
      'UN user should be eligible for submitter role'
    );

    // Test with user who should not get submitter role (wrong domain).
    $this->assertFalse(
      $this->userRoleAssignment->shouldAssignSubmitterRole($this->otherDomainUser),
      'Other domain user should not be eligible for submitter role'
    );

    // Test with user who already has submitter role.
    $this->testUser->addRole('submitter');
    $this->testUser->save();
    $this->assertFalse(
      $this->userRoleAssignment->shouldAssignSubmitterRole($this->testUser),
      'User with existing submitter role should not be eligible again'
    );

    // Test with user who has editor role.
    $editor_user = $this->createUser([], 'editor_user', FALSE, [
      'mail' => 'editor@un.org',
      'status' => 1,
      'roles' => ['editor'],
    ]);
    $this->assertFalse(
      $this->userRoleAssignment->shouldAssignSubmitterRole($editor_user),
      'User with editor role should not be eligible for submitter role'
    );
  }

  /**
   * Test shouldAssignSubmitterRole with posting rights.
   */
  public function testShouldAssignSubmitterRoleWithPostingRights(): void {
    // Test with user who has posting rights for reports.
    $this->assertTrue(
      $this->userRoleAssignment->shouldAssignSubmitterRole($this->testUser),
      'User with posting rights should be eligible for submitter role'
    );
  }

  /**
   * Test shouldAssignSubmitterRole with legacy content.
   */
  public function testShouldAssignSubmitterRoleWithLegacyContent(): void {
    // Create a user with no domain rights and no posting rights.
    $legacy_user = $this->createUser([], 'legacy_user', FALSE, [
      'mail' => 'legacy@other.com',
      'status' => 1,
    ]);

    // Create some content for the user.
    $this->createNode([
      'type' => 'report',
      'title' => 'Legacy Report',
      'uid' => $legacy_user->id(),
    ]);

    $this->assertTrue(
      $this->userRoleAssignment->shouldAssignSubmitterRole($legacy_user),
      'User with legacy content should be eligible for submitter role'
    );
  }

  /**
   * Test shouldAssignAdvertiserRole method.
   */
  public function testShouldAssignAdvertiserRole(): void {
    // Test with user who has posting rights for jobs/training.
    $this->assertTrue(
      $this->userRoleAssignment->shouldAssignAdvertiserRole($this->testUser),
      'User with job/training posting rights should be eligible for advertiser role'
    );

    // Test with user who already has advertiser role.
    $this->testUser->addRole('advertiser');
    $this->testUser->save();
    $this->assertFalse(
      $this->userRoleAssignment->shouldAssignAdvertiserRole($this->testUser),
      'User with existing advertiser role should not be eligible again'
    );
  }

  /**
   * Test shouldAssignAdvertiserRole with legacy content.
   */
  public function testShouldAssignAdvertiserRoleWithLegacyContent(): void {
    // Create a user with no posting rights.
    $legacy_user = $this->createUser([], 'legacy_advertiser', FALSE, [
      'mail' => 'legacy@other.com',
      'status' => 1,
    ]);

    // Create some job content for the user.
    $this->createNode([
      'type' => 'job',
      'title' => 'Legacy Job',
      'uid' => $legacy_user->id(),
    ]);

    $this->assertTrue(
      $this->userRoleAssignment->shouldAssignAdvertiserRole($legacy_user),
      'User with legacy job content should be eligible for advertiser role'
    );
  }

  /**
   * Test hasDomainAllowedForSubmitter method.
   */
  public function testHasDomainAllowedForSubmitter(): void {
    // Test with UN domain user.
    $this->assertTrue(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($this->unUser),
      'UN domain user should be allowed for submitter role'
    );

    // Test with other domain user.
    $this->assertFalse(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($this->otherDomainUser),
      'Other domain user should not be allowed for submitter role'
    );

    // Test with user without email.
    $no_email_user = $this->createUser([], 'no_email_user', FALSE, [
      'mail' => '',
      'status' => 1,
    ]);
    $this->assertFalse(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($no_email_user),
      'User without email should not be allowed for submitter role'
    );

    // Test with user with invalid email.
    $invalid_email_user = $this->createUser([], 'invalid_email_user', FALSE, [
      'mail' => 'invalid-email',
      'status' => 1,
    ]);
    $this->assertFalse(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($invalid_email_user),
      'User with invalid email should not be allowed for submitter role'
    );
  }

  /**
   * Test hasDomainAllowedForSubmitter with Entra ID check.
   */
  public function testHasDomainAllowedForSubmitterWithEntraId(): void {
    // Enable Entra ID check.
    \Drupal::state()->set('reliefweb_users_submitter_check_entraid_for_assignment', TRUE);

    // Test with UN user without Entra ID connection.
    $this->assertFalse(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($this->unUser),
      'UN user without Entra ID should not be allowed for submitter role'
    );

    // Mock Entra ID connection for UN user.
    \Drupal::database()->insert('authmap')
      ->fields([
        'uid' => $this->unUser->id(),
        'provider' => 'openid_connect.entraid',
        'authname' => 'test_authname',
      ])
      ->execute();

    $this->assertTrue(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($this->unUser),
      'UN user with Entra ID should be allowed for submitter role'
    );
  }

  /**
   * Test hasPostingRights method.
   */
  public function testHasPostingRights(): void {
    // Test with user who has posting rights.
    $this->assertTrue(
      $this->userRoleAssignment->hasPostingRights($this->testUser, ['job']),
      'User with job posting rights should return true'
    );

    $this->assertTrue(
      $this->userRoleAssignment->hasPostingRights($this->testUser, ['training']),
      'User with training posting rights should return true'
    );

    $this->assertTrue(
      $this->userRoleAssignment->hasPostingRights($this->testUser, ['report']),
      'User with trusted report posting rights should return true'
    );

    // Test with user who has no posting rights.
    $this->assertFalse(
      $this->userRoleAssignment->hasPostingRights($this->otherDomainUser, ['job']),
      'User with no posting rights should return false'
    );

    // Test with default bundles.
    $this->assertTrue(
      $this->userRoleAssignment->hasPostingRights($this->testUser),
      'User with posting rights should return true for default bundles'
    );
  }

  /**
   * Test hasPostedContent method.
   */
  public function testHasPostedContent(): void {
    // Create some content for the test user.
    $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'uid' => $this->testUser->id(),
    ]);

    $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'uid' => $this->testUser->id(),
    ]);

    // Test with specific bundles.
    $this->assertTrue(
      $this->userRoleAssignment->hasPostedContent($this->testUser, ['job']),
      'User with job content should return true'
    );

    $this->assertTrue(
      $this->userRoleAssignment->hasPostedContent($this->testUser, ['training']),
      'User with training content should return true'
    );

    $this->assertFalse(
      $this->userRoleAssignment->hasPostedContent($this->testUser, ['report']),
      'User without report content should return false'
    );

    // Test with default bundles.
    $this->assertTrue(
      $this->userRoleAssignment->hasPostedContent($this->testUser),
      'User with content should return true for default bundles'
    );

    // Test with user who has no content.
    $this->assertFalse(
      $this->userRoleAssignment->hasPostedContent($this->otherDomainUser),
      'User with no content should return false'
    );
  }

  /**
   * Test roleExists method.
   */
  public function testRoleExists(): void {
    // Test with existing role.
    $this->assertTrue(
      $this->userRoleAssignment->roleExists('submitter'),
      'Existing role should return true'
    );

    $this->assertTrue(
      $this->userRoleAssignment->roleExists('advertiser'),
      'Existing role should return true'
    );

    // Test with non-existing role.
    $this->assertFalse(
      $this->userRoleAssignment->roleExists('nonexistent_role'),
      'Non-existing role should return false'
    );
  }

  /**
   * Test edge cases and error conditions.
   */
  public function testEdgeCases(): void {
    // Test with user who has no email.
    $no_email_user = $this->createUser([], 'no_email_user', FALSE, [
      'mail' => '',
      'status' => 1,
    ]);

    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($no_email_user);
    $this->assertEmpty($assigned_roles, 'User without email should not be assigned any roles');

    // Test with user who has invalid email.
    $invalid_email_user = $this->createUser([], 'invalid_email_user', FALSE, [
      'mail' => 'invalid-email',
      'status' => 1,
    ]);

    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($invalid_email_user);
    $this->assertEmpty($assigned_roles, 'User with invalid email should not be assigned any roles');

    // Test with user who has multiple @ symbols in email.
    $multi_at_user = $this->createUser([], 'multi_at_user', FALSE, [
      'mail' => 'user@sub@example.com',
      'status' => 1,
    ]);

    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($multi_at_user);
    $this->assertEmpty($assigned_roles, 'User with multiple @ symbols should not be assigned any roles');
  }

  /**
   * Test state configuration changes.
   */
  public function testStateConfigurationChanges(): void {
    // Disable legacy support for submitter role.
    \Drupal::state()->set('reliefweb_users_submitter_support_legacy_accounts', FALSE);

    // Create a user with legacy content but no domain rights.
    $legacy_user = $this->createUser([], 'legacy_user', FALSE, [
      'mail' => 'legacy@other.com',
      'status' => 1,
    ]);

    $this->createNode([
      'type' => 'report',
      'title' => 'Legacy Report',
      'uid' => $legacy_user->id(),
    ]);

    $this->assertFalse(
      $this->userRoleAssignment->shouldAssignSubmitterRole($legacy_user),
      'User should not be eligible for submitter role when legacy support is disabled'
    );

    // Disable legacy support for advertiser role.
    \Drupal::state()->set('reliefweb_users_advertiser_support_legacy_accounts', FALSE);

    $this->assertFalse(
      $this->userRoleAssignment->shouldAssignAdvertiserRole($legacy_user),
      'User should not be eligible for advertiser role when legacy support is disabled'
    );
  }

  /**
   * Test domain configuration changes.
   */
  public function testDomainConfigurationChanges(): void {
    // Change allowed domains.
    \Drupal::state()->set('reliefweb_users_submitter_allowed_domains', ['example.com']);

    $this->assertTrue(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($this->testUser),
      'User with example.com domain should be allowed for submitter role'
    );

    $this->assertFalse(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($this->unUser),
      'User with un.org domain should not be allowed for submitter role'
    );

    // Clear allowed domains.
    \Drupal::state()->set('reliefweb_users_submitter_allowed_domains', []);

    $this->assertFalse(
      $this->userRoleAssignment->hasDomainAllowedForSubmitter($this->testUser),
      'User should not be allowed for submitter role when no domains are configured'
    );
  }

  /**
   * Test performance with multiple role assignments.
   */
  public function testPerformanceMultipleRoleAssignments(): void {
    // Create multiple users who should get roles.
    $users = [];
    for ($i = 0; $i < 5; $i++) {
      $user = $this->createUser([], "perf_user_$i", FALSE, [
        'mail' => "perf_user_$i@un.org",
        'status' => 1,
      ]);

      // Create some content for advertiser role eligibility.
      $this->createNode([
        'type' => 'job',
        'title' => "Performance Test Job $i",
        'uid' => $user->id(),
      ]);

      $users[] = $user;
    }

    // Assign roles to all users.
    foreach ($users as $user) {
      $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($user);
      $this->assertNotEmpty($assigned_roles, 'User should be assigned roles');
    }

    // Clear the static cache for the users.
    \Drupal::entityTypeManager()->getStorage('user')->resetCache();

    // Verify all users have the correct roles.
    foreach ($users as $user) {
      $user = User::load($user->id());
      $this->assertTrue($user->hasRole('submitter'), 'User should have submitter role');
      $this->assertTrue($user->hasRole('advertiser'), 'User should have advertiser role');
    }
  }

  /**
   * Test that roles are not assigned multiple times.
   */
  public function testNoDuplicateRoleAssignments(): void {
    // Assign roles to user.
    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($this->unUser);
    $this->assertNotEmpty($assigned_roles, 'User should be assigned roles');

    // Try to assign roles again.
    $assigned_roles_again = $this->userRoleAssignment->assignEligibleRoles($this->unUser);
    $this->assertEmpty($assigned_roles_again, 'User should not be assigned roles again');

    // Verify user still has the roles.
    $user = User::load($this->unUser->id());
    $this->assertTrue($user->hasRole('submitter'), 'User should still have submitter role');
  }

  /**
   * Test error handling in saveUserWithRoles when system user doesn't exist.
   */
  public function testSaveUserWithRolesSystemUserNotFound(): void {
    // Delete the system user to simulate it not existing.
    $system_user = User::load(2);
    if ($system_user) {
      $system_user->delete();
    }

    // Create a user who should get roles.
    $user = $this->createUser([], 'error_test_user', FALSE, [
      'mail' => 'error@un.org',
      'status' => 1,
    ]);

    // This should not throw an exception but should return empty array when
    // save fails.
    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($user);

    // The method should return empty array when system user is not found.
    $this->assertEmpty($assigned_roles, 'Should return empty array when system user is not found');

    // Verify the user doesn't actually have the role since save failed.
    $user = User::load($user->id());
    $this->assertFalse($user->hasRole('submitter'), 'User should not have submitter role due to save failure');
  }

  /**
   * Test error handling in saveUserWithRoles when user save fails.
   */
  public function testSaveUserWithRolesUserSaveFailure(): void {
    // Create a user with invalid email from the beginning to avoid email
    // sending.
    $user = $this->createUser([], 'error_test_user3', FALSE, [
      'mail' => 'invalid-email-format',
      'status' => 1,
    ]);

    // The method should handle the exception gracefully and return empty array.
    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($user);

    // The method should return empty array when save fails.
    $this->assertEmpty($assigned_roles, 'Should return empty array when user save fails');

    // Verify the user doesn't actually have the role since save failed.
    $user = User::load($user->id());
    $this->assertFalse($user->hasRole('submitter'), 'User should not have submitter role due to save failure');
  }

  /**
   * Test error handling in saveUserWithRoles with multiple role assignments.
   */
  public function testSaveUserWithRolesMultipleRolesFailure(): void {
    // Create a user with invalid email from the beginning to avoid email
    // sending.
    $user = $this->createUser([], 'error_test_user6', FALSE, [
      'mail' => 'invalid-email-format',
      'status' => 1,
    ]);

    // Set and invalidate the name to trigger a save failure.
    $user->set('name', '');

    // Create some content for the user to make them eligible for advertiser
    // role.
    $this->createNode([
      'type' => 'job',
      'title' => 'Test Job for Error Test',
      'uid' => $user->id(),
    ]);

    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($user);

    // The method should return empty array when save fails.
    $this->assertEmpty($assigned_roles, 'Should return empty array when user save fails with multiple roles');

    // Verify the user doesn't actually have the roles since save failed.
    $user = User::load($user->id());
    $this->assertFalse($user->hasRole('submitter'), 'User should not have submitter role due to save failure');
    $this->assertFalse($user->hasRole('advertiser'), 'User should not have advertiser role due to save failure');
  }

  /**
   * Test error handling in saveUserWithRoles with empty role list.
   */
  public function testSaveUserWithRolesEmptyRoles(): void {
    // Create a user who should not get any roles.
    $user = $this->createUser([], 'error_test_user7', FALSE, [
      'mail' => 'error7@other.com',
      'status' => 1,
    ]);

    $assigned_roles = $this->userRoleAssignment->assignEligibleRoles($user);

    // The method should return empty array.
    $this->assertEmpty($assigned_roles, 'User should not be assigned any roles');

    // Verify the user doesn't have any roles.
    $user = User::load($user->id());
    $this->assertFalse($user->hasRole('submitter'), 'User should not have submitter role');
    $this->assertFalse($user->hasRole('advertiser'), 'User should not have advertiser role');
  }

}
