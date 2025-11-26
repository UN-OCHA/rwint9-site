<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Base test class for taxonomy term moderation services.
 */
abstract class TermModerationServiceTestBase extends ExistingSiteBase {

  /**
   * The moderation service.
   */
  protected ModerationServiceInterface $moderationService;

  /**
   * Test users with different roles.
   */
  protected array $testUsers = [];

  /**
   * Test roles created for testing.
   */
  protected array $testRoles = [];

  /**
   * Test terms with different statuses.
   */
  protected array $testTerms = [];

  /**
   * Test vocabulary.
   */
  protected Vocabulary $testVocabulary;

  /**
   * Original permissions for the authenticated role.
   */
  protected array $originalAuthenticatedPermissions = [];

  /**
   * Original permissions for the anonymous role.
   */
  protected array $originalAnonymousPermissions = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalPermissions();
    $this->clearDefaultPermissions();
    $this->moderationService = $this->getModerationService();
    $this->createTestRoles();
    $this->createTestUsers();
    $this->createTestVocabulary();
    $this->createTestTerms();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreOriginalPermissions();
    parent::tearDown();
  }

  /**
   * Get the moderation service instance.
   *
   * @return \Drupal\reliefweb_moderation\ModerationServiceInterface
   *   The moderation service.
   */
  abstract protected function getModerationService(): ModerationServiceInterface;

  /**
   * Get the bundle name.
   *
   * @return string
   *   The bundle name.
   */
  protected function getBundle(): string {
    return $this->getModerationService()->getBundle();
  }

  /**
   * Get the statuses that should be tested.
   *
   * @return array
   *   Array of status names.
   */
  protected function getTestStatuses(): array {
    return array_keys($this->getModerationService()->getStatuses());
  }

  /**
   * Get the viewable statuses for testing.
   *
   * @return array
   *   Array of viewable status names.
   */
  protected function getViewableStatuses(): array {
    $statuses = $this->getTestStatuses();
    $viewable_statuses = array_filter($statuses, function ($status) {
      return $this->getModerationService()->isViewableStatus($status);
    });
    return $viewable_statuses;
  }

  /**
   * Get the editable statuses for testing.
   *
   * @return array
   *   Array of editable status names.
   */
  protected function getEditableStatuses(): array {
    $statuses = $this->getTestStatuses();
    $editable_statuses = array_filter($statuses, function ($status) {
      return $this->getModerationService()->isEditableStatus($status);
    });
    return $editable_statuses;
  }

  /**
   * Get the deletable statuses for testing.
   *
   * @return array
   *   Array of deletable status names.
   */
  protected function getDeletableStatuses(): array {
    $statuses = $this->getTestStatuses();
    $deletable_statuses = array_filter($statuses, function ($status) {
      return $this->getModerationService()->isDeletableStatus($status);
    });
    return $deletable_statuses;
  }

  /**
   * Get the statuses that require special permissions for editing.
   *
   * @return array
   *   Array of status names that require special permissions.
   */
  protected function getSpecialPermissionStatuses(): array {
    $bundle = $this->getBundle();
    return match ($bundle) {
      // Source doesn't have special permission statuses.
      'source' => [],
      'country' => [],
      'disaster' => ['external', 'external-archive'],
      default => [],
    };
  }

  /**
   * Save the original permissions for authenticated and anonymous roles.
   */
  protected function saveOriginalPermissions(): void {
    // Save authenticated role permissions.
    $authenticated_role = Role::load('authenticated');
    if ($authenticated_role) {
      $this->originalAuthenticatedPermissions = $authenticated_role->getPermissions();
    }

    // Save anonymous role permissions.
    $anonymous_role = Role::load('anonymous');
    if ($anonymous_role) {
      $this->originalAnonymousPermissions = $anonymous_role->getPermissions();
    }
  }

  /**
   * Restore the original permissions for authenticated and anonymous roles.
   */
  protected function restoreOriginalPermissions(): void {
    // Restore authenticated role permissions.
    $authenticated_role = Role::load('authenticated');
    if ($authenticated_role && !empty($this->originalAuthenticatedPermissions)) {
      $authenticated_role->set('permissions', $this->originalAuthenticatedPermissions);
      $authenticated_role->save();
    }

    // Restore anonymous role permissions.
    $anonymous_role = Role::load('anonymous');
    if ($anonymous_role && !empty($this->originalAnonymousPermissions)) {
      $anonymous_role->set('permissions', $this->originalAnonymousPermissions);
      $anonymous_role->save();
    }
  }

  /**
   * Clear permissions from authenticated and anonymous roles for testing.
   */
  protected function clearDefaultPermissions(): void {
    // Clear authenticated role permissions.
    $authenticated_role = Role::load('authenticated');
    if ($authenticated_role) {
      $authenticated_role->set('permissions', []);
      $authenticated_role->save();
    }

    // Clear anonymous role permissions.
    $anonymous_role = Role::load('anonymous');
    if ($anonymous_role) {
      $anonymous_role->set('permissions', []);
      $anonymous_role->save();
    }
  }

  /**
   * Create test roles with specific permissions.
   */
  protected function createTestRoles(): void {
    $bundle = $this->getBundle();

    // Mapping of role ID to permissions.
    $roles = [
      'view_any' => [
        'access content',
        'view any content',
      ],
      'access_content' => [
        'access content',
      ],
      'edit_terms' => [
        'access content',
        'edit terms in ' . $bundle,
      ],
      'delete_terms' => [
        'access content',
        'edit terms in ' . $bundle,
        'delete terms in ' . $bundle,
      ],
      'edit_and_delete_terms' => [
        'access content',
        'edit terms in ' . $bundle,
        'delete terms in ' . $bundle,
      ],
      'administer_taxonomy' => [
        'administer taxonomy',
      ],
      'view_moderation_info' => [
        'view moderation information',
      ],
      'no_access' => [],
    ];

    // Add special permission roles for specific bundles.
    if ($bundle === 'disaster') {
      $roles['edit_external_disasters'] = [
        'access content',
        'edit terms in ' . $bundle,
      ];
    }

    foreach ($roles as $id => $permissions) {
      $this->testRoles[$id] = $this->createRole($permissions);
    }
  }

  /**
   * Create test users with different roles.
   */
  protected function createTestUsers(): void {
    // User IDs.
    $users = [
      'view_any',
      'access_content',
      'edit_terms',
      'delete_terms',
      'edit_and_delete_terms',
      'administer_taxonomy',
      'view_moderation_info',
      'no_access',
    ];

    // Add special users for specific bundles.
    if ($this->getBundle() === 'disaster') {
      $users[] = 'edit_external_disasters';
    }

    foreach ($users as $id) {
      $this->testUsers[$id] = $this->createUser(values: [
        'roles' => [$this->testRoles[$id]],
      ]);
    }

    // Add anonymous user.
    $this->testUsers['anonymous'] = User::getAnonymousUser();
  }

  /**
   * Create test vocabulary.
   */
  protected function createTestVocabulary(): void {
    $bundle = $this->getBundle();
    $this->testVocabulary = Vocabulary::load($bundle);
    if (!$this->testVocabulary) {
      // Create the vocabulary if it doesn't exist.
      $this->testVocabulary = Vocabulary::create([
        'vid' => $bundle,
        'name' => ucfirst($bundle),
      ]);
      $this->markEntityForCleanup($this->testVocabulary);
      $this->testVocabulary->save();
    }
  }

  /**
   * Create test terms with different moderation statuses.
   */
  protected function createTestTerms(): void {
    $statuses = $this->getTestStatuses();

    foreach ($statuses as $status) {
      $this->testTerms[$status] = $this->createTerm($this->testVocabulary, [
        'name' => 'Test ' . ucfirst($this->getBundle()) . ' ' . ucfirst($status),
        'moderation_status' => $status,
      ]);
    }
  }

  /**
   * Test view access for different roles and term statuses.
   */
  public function testViewAccess(): void {
    $viewable_statuses = $this->getViewableStatuses();
    $non_viewable_statuses = array_diff($this->getTestStatuses(), $viewable_statuses);

    // Test case 1: view any content - can view any content regardless of
    // status.
    $this->setCurrentUser($this->testUsers['view_any']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertTrue($this->moderationService->entityAccess($term, 'view')->isAllowed(),
        'View any user can view ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test case 2: access content + viewable status - can view published
    // content only.
    $this->setCurrentUser($this->testUsers['access_content']);
    foreach ($viewable_statuses as $status) {
      $term = $this->testTerms[$status];
      $this->assertTrue($this->moderationService->entityAccess($term, 'view')->isAllowed(),
        'Access content user can view viewable ' . $status . ' ' . $this->getBundle() . ' terms');
    }
    foreach ($non_viewable_statuses as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'view')->isAllowed(),
        'Access content user cannot view non-viewable ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'view')->isAllowed(),
        'No access user cannot view ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'view')->isAllowed(),
        'Anonymous user cannot view ' . $status . ' ' . $this->getBundle() . ' terms');
    }
  }

  /**
   * Test create access for different roles.
   */
  public function testCreateAccess(): void {
    // Test case 1: edit terms - can create terms.
    $this->setCurrentUser($this->testUsers['edit_terms']);
    $this->assertTrue($this->moderationService->entityCreateAccess($this->testUsers['edit_terms'])->isAllowed(),
      'Edit terms user can create ' . $this->getBundle() . ' terms');

    // Test case 2: administer taxonomy - cannot create terms without edit
    // permission.
    $this->setCurrentUser($this->testUsers['administer_taxonomy']);
    $this->assertFalse($this->moderationService->entityCreateAccess($this->testUsers['administer_taxonomy'])->isAllowed(),
      'Administer taxonomy user cannot create ' . $this->getBundle() . ' terms without edit permission');

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    $this->assertFalse($this->moderationService->entityCreateAccess($this->testUsers['no_access'])->isAllowed(),
      'No access user cannot create ' . $this->getBundle() . ' terms');

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    $this->assertFalse($this->moderationService->entityCreateAccess($this->testUsers['anonymous'])->isAllowed(),
      'Anonymous user cannot create ' . $this->getBundle() . ' terms');
  }

  /**
   * Test update access for different roles and term statuses.
   */
  public function testUpdateAccess(): void {
    $editable_statuses = $this->getEditableStatuses();
    $non_editable_statuses = array_diff($this->getTestStatuses(), $editable_statuses);
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    // Test case 1: edit terms - can edit editable terms.
    $edit_user_key = method_exists($this, 'getTestUserForEditOperations') ? $this->getTestUserForEditOperations() : 'edit_terms';
    $this->setCurrentUser($this->testUsers[$edit_user_key]);
    foreach ($editable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $term = $this->testTerms[$status];
        $this->assertTrue($this->moderationService->entityAccess($term, 'update')->isAllowed(),
          ucfirst($edit_user_key) . ' user can update editable ' . $status . ' ' . $this->getBundle() . ' terms');
      }
    }
    foreach ($non_editable_statuses as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'update')->isAllowed(),
        ucfirst($edit_user_key) . ' user cannot update non-editable ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test case 2: administer taxonomy - can edit any terms.
    $this->setCurrentUser($this->testUsers['administer_taxonomy']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertTrue($this->moderationService->entityAccess($term, 'update')->isAllowed(),
        'Administer taxonomy user can update ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test special permission statuses.
    foreach ($special_permission_statuses as $status) {
      if (isset($this->testTerms[$status])) {
        $term = $this->testTerms[$status];
        $special_user = $this->getSpecialPermissionUser($status);
        if ($special_user) {
          $this->setCurrentUser($special_user);
          $this->assertTrue($this->moderationService->entityAccess($term, 'update')->isAllowed(),
            'Special permission user can update ' . $status . ' ' . $this->getBundle() . ' terms');
        }
      }
    }

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'update')->isAllowed(),
        'No access user cannot update ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'update')->isAllowed(),
        'Anonymous user cannot update ' . $status . ' ' . $this->getBundle() . ' terms');
    }
  }

  /**
   * Test delete access for different roles and term statuses.
   */
  public function testDeleteAccess(): void {
    $deletable_statuses = $this->getDeletableStatuses();
    $non_deletable_statuses = array_diff($this->getTestStatuses(), $deletable_statuses);
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    // Test case 1: delete terms - can delete deletable terms.
    $this->setCurrentUser($this->testUsers['delete_terms']);
    foreach ($deletable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $term = $this->testTerms[$status];
        $this->assertTrue($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
          'Delete terms user can delete deletable ' . $status . ' ' . $this->getBundle() . ' terms');
      }
    }
    foreach ($non_deletable_statuses as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
        'Delete terms user cannot delete non-deletable ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test case 2: edit and delete terms - can delete deletable terms.
    $this->setCurrentUser($this->testUsers['edit_and_delete_terms']);
    foreach ($deletable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $term = $this->testTerms[$status];
        $this->assertTrue($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
          'Edit and delete terms user can delete deletable ' . $status . ' ' . $this->getBundle() . ' terms');
      }
    }

    // Test case 3: administer taxonomy - can delete any terms.
    $this->setCurrentUser($this->testUsers['administer_taxonomy']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertTrue($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
        'Administer taxonomy user can delete ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test special permission statuses.
    foreach ($special_permission_statuses as $status) {
      if (isset($this->testTerms[$status])) {
        $term = $this->testTerms[$status];
        $special_user = $this->getSpecialPermissionUser($status);
        if ($special_user) {
          $this->setCurrentUser($special_user);
          $this->assertTrue($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
            'Special permission user can delete ' . $status . ' ' . $this->getBundle() . ' terms');
        }
      }
    }

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
        'No access user cannot delete ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
        'Anonymous user cannot delete ' . $status . ' ' . $this->getBundle() . ' terms');
    }
  }

  /**
   * Test view moderation information access.
   */
  public function testViewModerationInformationAccess(): void {
    // Test case 1: view moderation information - can view moderation info for
    // any content.
    $this->setCurrentUser($this->testUsers['view_moderation_info']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertTrue($this->moderationService->entityAccess($term, 'view_moderation_information')->isAllowed(),
        'View moderation info user can view moderation information for ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test case 2: administer taxonomy - can view moderation info for any
    // content.
    $this->setCurrentUser($this->testUsers['administer_taxonomy']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertTrue($this->moderationService->entityAccess($term, 'view_moderation_information')->isAllowed(),
        'Administer taxonomy user can view moderation information for ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'view_moderation_information')->isAllowed(),
        'No access user cannot view moderation information for ' . $status . ' ' . $this->getBundle() . ' terms');
    }

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    foreach ($this->getTestStatuses() as $status) {
      $term = $this->testTerms[$status];
      $this->assertFalse($this->moderationService->entityAccess($term, 'view_moderation_information')->isAllowed(),
        'Anonymous user cannot view moderation information for ' . $status . ' ' . $this->getBundle() . ' terms');
    }
  }

  /**
   * Test isEditableStatus method.
   */
  public function testIsEditableStatus(): void {
    $edit_user_key = method_exists($this, 'getTestUserForEditOperations') ? $this->getTestUserForEditOperations() : 'edit_terms';
    $edit_terms = $this->testUsers[$edit_user_key];
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    $editable_statuses = $this->getEditableStatuses();

    // Test with edit terms user (has basic edit permissions but no special
    // status permissions).
    foreach ($editable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $this->assertTrue($this->moderationService->isEditableStatus($status, $edit_terms),
          ucfirst($status) . ' status is editable for ' . $edit_user_key . ' user');
      }
    }

    // Test that special permission statuses are not editable without
    // permissions.
    foreach ($special_permission_statuses as $status) {
      $this->assertFalse($this->moderationService->isEditableStatus($status, $edit_terms),
        ucfirst($status) . ' status is not editable for ' . $edit_user_key . ' user without special permission');
    }
  }

  /**
   * Test isDeletableStatus method.
   */
  public function testIsDeletableStatus(): void {
    $delete_terms = $this->testUsers['delete_terms'];
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    $deletable_statuses = $this->getDeletableStatuses();

    // Test with delete terms user (has delete permissions but no special
    // status permissions).
    foreach ($deletable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $this->assertTrue($this->moderationService->isDeletableStatus($status, $delete_terms),
          ucfirst($status) . ' status is deletable for delete terms user');
      }
    }

    // Test that special permission statuses are not deletable without
    // permissions.
    foreach ($special_permission_statuses as $status) {
      $this->assertFalse($this->moderationService->isDeletableStatus($status, $delete_terms),
        ucfirst($status) . ' status is not deletable for delete terms user without special permission');
    }
  }

  /**
   * Test isViewableStatus method.
   */
  public function testIsViewableStatus(): void {
    $view_any = $this->testUsers['view_any'];

    $viewable_statuses = $this->getViewableStatuses();
    $non_viewable_statuses = array_diff($this->getTestStatuses(), $viewable_statuses);

    // Test published statuses (should be viewable)
    foreach ($viewable_statuses as $status) {
      $this->assertTrue($this->moderationService->isViewableStatus($status, $view_any),
        ucfirst($status) . ' status is viewable');
    }

    // Test non-published statuses (should not be viewable by default)
    foreach ($non_viewable_statuses as $status) {
      $this->assertFalse($this->moderationService->isViewableStatus($status, $view_any),
        ucfirst($status) . ' status is not viewable');
    }
  }

  /**
   * Test access with different moderation statuses.
   */
  public function testAccessWithDifferentStatuses(): void {
    $statuses = $this->getTestStatuses();
    $administer_taxonomy = $this->testUsers['administer_taxonomy'];
    $edit_user_key = method_exists($this, 'getTestUserForEditOperations') ? $this->getTestUserForEditOperations() : 'edit_terms';
    $edit_terms = $this->testUsers[$edit_user_key];
    $edit_and_delete_terms = $this->testUsers['edit_and_delete_terms'];
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    foreach ($statuses as $status) {
      $term = $this->testTerms[$status];

      // Test administer taxonomy user access (has all necessary permissions).
      $this->setCurrentUser($administer_taxonomy);
      $this->assertTrue($this->moderationService->entityAccess($term, 'view')->isAllowed(),
        "Administer taxonomy user can view {$status} " . $this->getBundle() . ' terms');
      $this->assertTrue($this->moderationService->entityAccess($term, 'update')->isAllowed(),
        "Administer taxonomy user can update {$status} " . $this->getBundle() . ' terms');
      $this->assertTrue($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
        "Administer taxonomy user can delete {$status} " . $this->getBundle() . ' terms');

      // Test edit terms user access.
      $this->setCurrentUser($edit_terms);
      $view_access = $this->moderationService->entityAccess($term, 'view');
      $update_access = $this->moderationService->entityAccess($term, 'update');
      $delete_access = $this->moderationService->entityAccess($term, 'delete');

      // Test edit and delete terms user access for delete operations.
      $this->setCurrentUser($edit_and_delete_terms);
      $edit_and_delete_access = $this->moderationService->entityAccess($term, 'delete');

      $viewable_statuses = $this->getViewableStatuses();
      if (in_array($status, $viewable_statuses)) {
        $this->assertTrue($view_access->isAllowed(),
          ucfirst($edit_user_key) . " user can view {$status} " . $this->getBundle() . ' terms');
      }
      else {
        $this->assertFalse($view_access->isAllowed(),
          ucfirst($edit_user_key) . " user cannot view {$status} " . $this->getBundle() . ' terms');
      }

      $editable_statuses = $this->getEditableStatuses();
      $deletable_statuses = $this->getDeletableStatuses();
      if (in_array($status, $special_permission_statuses) && !in_array($status, $editable_statuses)) {
        $this->assertFalse($update_access->isAllowed(),
          ucfirst($edit_user_key) . " user cannot update {$status} " . $this->getBundle() . ' terms without permission');
        $this->assertFalse($delete_access->isAllowed(),
          ucfirst($edit_user_key) . " user cannot delete {$status} " . $this->getBundle() . ' terms without permission');
      }
      else {
        $this->assertTrue($update_access->isAllowed(),
          ucfirst($edit_user_key) . " user can update {$status} " . $this->getBundle() . ' terms');
        $this->assertFalse($delete_access->isAllowed(),
          ucfirst($edit_user_key) . " user cannot delete {$status} " . $this->getBundle() . ' terms without delete permission');

        // Test delete access with proper permissions.
        if (in_array($status, $deletable_statuses)) {
          $this->assertTrue($edit_and_delete_access->isAllowed(),
            "Edit and delete terms user can delete {$status} " . $this->getBundle() . ' terms');
        }
        else {
          $this->assertFalse($edit_and_delete_access->isAllowed(),
            "Edit and delete terms user cannot delete non-deletable {$status} " . $this->getBundle() . ' terms');
        }
      }
    }
  }

  /**
   * Test unknown operation returns neutral.
   */
  public function testUnknownOperationReturnsNeutral(): void {
    $term = $this->testTerms[array_key_first($this->testTerms)];

    // Test that unknown operations return neutral access result.
    $this->setCurrentUser($this->testUsers['edit_terms']);
    $access_result = $this->moderationService->entityAccess($term, 'unknown_operation');
    $this->assertTrue($access_result->isNeutral(), 'Unknown operation returns neutral access result');
  }

  /**
   * Test access to special permission statuses with appropriate users.
   */
  public function testSpecialPermissionStatusesAccess(): void {
    $special_permission_statuses = $this->getSpecialPermissionStatuses();
    $bundle = $this->getBundle();

    foreach ($special_permission_statuses as $status) {
      if (!isset($this->testTerms[$status])) {
        continue;
      }

      $term = $this->testTerms[$status];

      // Test user without special permissions cannot edit special
      // statuses.
      $edit_user_key = method_exists($this, 'getTestUserForEditOperations') ? $this->getTestUserForEditOperations() : 'edit_terms';
      $this->setCurrentUser($this->testUsers[$edit_user_key]);
      $this->assertFalse($this->moderationService->entityAccess($term, 'update')->isAllowed(),
        ucfirst($edit_user_key) . " user cannot update {$status} {$bundle} terms without special permission");
      $this->assertFalse($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
        ucfirst($edit_user_key) . " user cannot delete {$status} {$bundle} terms without special permission");

      // Test user with appropriate special permission can edit special
      // statuses.
      $special_user = $this->getSpecialPermissionUser($status);
      if ($special_user) {
        $this->setCurrentUser($special_user);
        $this->assertTrue($this->moderationService->entityAccess($term, 'update')->isAllowed(),
          "User with {$status} permission can update {$status} {$bundle} terms");
        $this->assertTrue($this->moderationService->entityAccess($term, 'delete')->isAllowed(),
          "User with {$status} permission can delete {$status} {$bundle} terms");
      }
    }
  }

  /**
   * Get the appropriate test user for a special permission status.
   *
   * @param string $status
   *   The status requiring special permission.
   *
   * @return \Drupal\user\Entity\User|null
   *   The test user with the appropriate permission, or NULL if not found.
   */
  protected function getSpecialPermissionUser(string $status): ?User {
    return match ($status) {
      'external', 'external-archive' => $this->testUsers['manage_external_disasters'] ?? NULL,
      default => NULL,
    };
  }

}
