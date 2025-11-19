<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Base test class for moderation services.
 */
abstract class NodeModerationServiceTestBase extends ExistingSiteBase {

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
   * Test nodes with different statuses.
   */
  protected array $testNodes = [];

  /**
   * Source vocabulary.
   */
  protected Vocabulary $sourceVocabulary;

  /**
   * Test source with posting rights for affiliated user.
   */
  protected Term $testSource;

  /**
   * Original permissions for the authenticated role.
   */
  protected array $originalAuthenticatedPermissions = [];

  /**
   * Original permissions for the anonymous role.
   */
  protected array $originalAnonymousPermissions = [];

  /**
   * Original privileged domains state.
   */
  protected ?array $originalPrivilegedDomains = NULL;

  /**
   * Original default domain posting rights state.
   */
  protected array|string|null $originalDefaultDomainPostingRights = NULL;

  /**
   * Test user with privileged domain but no explicit posting rights.
   */
  protected User $privilegedDomainUser;

  /**
   * Test source without posting rights for privileged domain user.
   */
  protected Term $noRightsSource;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalPermissions();
    $this->saveOriginalPrivilegedDomainsState();
    $this->clearDefaultPermissions();
    $this->moderationService = $this->getModerationService();
    $this->createTestRoles();
    $this->createTestUsers();
    $this->createSourceVocabulary();
    $this->createTestSources();
    $this->createTestNodes();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreOriginalPermissions();
    $this->restoreOriginalPrivilegedDomainsState();
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
      'job', 'training' => ['refused', 'duplicate'],
      'report' => ['refused', 'archive'],
      default => ['refused'],
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
      'view_any_bundle' => [
        'access content',
        'view any ' . $bundle . ' content',
      ],
      'view_published' => [
        'access content',
      ],
      'view_own_unpublished' => [
        'access content',
        'view own unpublished content',
        'view own unpublished ' . $bundle . ' content',
      ],
      'view_affiliated_unpublished' => [
        'access content',
        'view affiliated unpublished ' . $bundle . ' content',
        'apply posting rights',
      ],
      'create_bundle' => [
        'create ' . $bundle . ' content',
      ],
      'edit_any_bundle' => [
        'access content',
        'view any content',
        'edit any ' . $bundle . ' content',
      ],
      'edit_own_bundle' => [
        'access content',
        'view own unpublished content',
        'view own unpublished ' . $bundle . ' content',
        'edit own ' . $bundle . ' content',
        'delete own ' . $bundle . ' content',
      ],
      'edit_affiliated_bundle' => [
        'access content',
        'view affiliated unpublished ' . $bundle . ' content',
        'edit affiliated ' . $bundle . ' content',
        'delete affiliated ' . $bundle . ' content',
        'apply posting rights',
      ],
      'delete_any_bundle' => [
        'access content',
        'view any content',
        'delete any ' . $bundle . ' content',
      ],
      'delete_own_bundle' => [
        'access content',
        'view own unpublished content',
        'view own unpublished ' . $bundle . ' content',
        'delete own ' . $bundle . ' content',
      ],
      'delete_affiliated_bundle' => [
        'access content',
        'view affiliated unpublished ' . $bundle . ' content',
        'delete affiliated ' . $bundle . ' content',
        'apply posting rights',
      ],
      'view_moderation_info' => [
        'view moderation information',
      ],
      'view_bundle_moderation_info' => [
        'view ' . $bundle . ' moderation information',
        // Required to pass the edit access check.
        'edit any ' . $bundle . ' content',
      ],
      'edit_refused' => [
        'access content',
        'view any content',
        'edit any ' . $bundle . ' content',
        'delete any ' . $bundle . ' content',
        'edit refused content',
      ],
      'edit_duplicate' => [
        'access content',
        'view any content',
        'edit any ' . $bundle . ' content',
        'delete any ' . $bundle . ' content',
        'edit duplicate content',
      ],
      'edit_archived' => [
        'access content',
        'view any content',
        'edit any ' . $bundle . ' content',
        'delete any ' . $bundle . ' content',
        'edit archived content',
      ],
      'edit_all_special' => [
        'access content',
        'view any content',
        'edit any ' . $bundle . ' content',
        'delete any ' . $bundle . ' content',
        'edit refused content',
        'edit archived content',
        'edit duplicate content',
      ],
      'bypass_node_access' => [
        'bypass node access',
      ],
      'administer_nodes' => [
        'administer nodes',
      ],
      'no_access' => [],
    ];

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
      'view_any_bundle',
      'view_published',
      'view_own_unpublished',
      'view_affiliated_unpublished',
      'create_bundle',
      'edit_any_bundle',
      'edit_own_bundle',
      'edit_affiliated_bundle',
      'delete_any_bundle',
      'delete_own_bundle',
      'delete_affiliated_bundle',
      'view_moderation_info',
      'view_bundle_moderation_info',
      'edit_refused',
      'edit_duplicate',
      'edit_archived',
      'edit_all_special',
      'bypass_node_access',
      'administer_nodes',
      'no_access',
    ];

    foreach ($users as $id) {
      $this->testUsers[$id] = $this->createUser(values: [
        'roles' => [$this->testRoles[$id]],
      ]);
    }

    // Add anonymous user.
    $this->testUsers['anonymous'] = User::getAnonymousUser();

    // Create a user with privileged domain email but no explicit posting
    // rights.
    // This user has the "edit affiliated" permission but should not be able to
    // access affiliated content without explicit posting rights.
    $this->privilegedDomainUser = $this->createUser(values: [
      'mail' => 'privileged@example.com',
      'roles' => [$this->testRoles['edit_affiliated_bundle']],
    ]);
  }

  /**
   * Create source vocabulary.
   */
  protected function createSourceVocabulary(): void {
    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      // Create the source vocabulary if it doesn't exist.
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->markEntityForCleanup($this->sourceVocabulary);
      $this->sourceVocabulary->save();
    }
  }

  /**
   * Create test sources with posting rights.
   */
  protected function createTestSources(): void {
    // Create a test source with posting rights for the affiliated user.
    $this->testSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source for Affiliated User',
      'field_allowed_content_types' => [
        // Allow job (0), report (1) and training (2) content.
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $this->testUsers['edit_affiliated_bundle']->id(),
          // User is allowed for all content types.
          'report' => 2,
          'job' => 2,
          'training' => 2,
        ],
        [
          'id' => $this->testUsers['view_affiliated_unpublished']->id(),
          // User is allowed for all content types.
          'report' => 2,
          'job' => 2,
          'training' => 2,
        ],
        [
          'id' => $this->testUsers['delete_affiliated_bundle']->id(),
          // User is allowed for all content types.
          'report' => 2,
          'job' => 2,
          'training' => 2,
        ],
      ],
      'moderation_status' => 'active',
    ]);

    // Create a source without posting rights for testing privileged domain
    // users.
    $this->noRightsSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source Without Posting Rights',
      'field_allowed_content_types' => [
        // Allow job (0), report (1) and training (2) content.
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
      'moderation_status' => 'active',
    ]);
  }

  /**
   * Create test nodes with different moderation statuses.
   */
  protected function createTestNodes(): void {
    $statuses = $this->getTestStatuses();

    foreach ($statuses as $status) {
      $this->testNodes[$status] = $this->createNode([
        'type' => $this->getBundle(),
        'title' => 'Test ' . ucfirst($this->getBundle()) . ' Own ' . ucfirst($status),
        'uid' => $this->testUsers['edit_own_bundle']->id(),
        'moderation_status' => $status,
        'field_source' => [
          ['target_id' => $this->testSource->id()],
        ],
      ]);
    }

    $this->testNodes['affiliated_draft'] = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Test ' . ucfirst($this->getBundle()) . ' Affiliated Draft',
      'uid' => $this->testUsers['edit_affiliated_bundle']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);
    $this->testNodes['affiliated_published'] = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Test ' . ucfirst($this->getBundle()) . ' Affiliated Published',
      'uid' => $this->testUsers['edit_affiliated_bundle']->id(),
      'moderation_status' => 'published',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);
  }

  /**
   * Test view access for different roles and node statuses.
   */
  public function testViewAccess(): void {
    $published_node = $this->testNodes['published'];
    $draft_node = $this->testNodes['draft'];
    $refused_node = $this->testNodes['refused'] ?? $this->testNodes['draft'];

    // Test case 1: view any content - can view any content regardless of
    // status.
    $this->setCurrentUser($this->testUsers['view_any']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view')->isAllowed(),
      'View any user can view published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(),
      'View any user can view draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(),
      'View any user can view refused ' . $this->getBundle() . 's');

    // Test case 2: view any bundle content - can view any bundle content
    // regardless of status.
    $this->setCurrentUser($this->testUsers['view_any_bundle']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view')->isAllowed(),
      'View any ' . $this->getBundle() . ' user can view published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(),
      'View any ' . $this->getBundle() . ' user can view draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(),
      'View any ' . $this->getBundle() . ' user can view refused ' . $this->getBundle() . 's');

    // Test case 3: access content + viewable status - can view published
    // content only.
    $this->setCurrentUser($this->testUsers['view_published']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view')->isAllowed(),
      'View published user can view published ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(),
      'View published user cannot view draft ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(),
      'View published user cannot view refused ' . $this->getBundle() . 's');

    // Test case 4: view own unpublished content + owner - can view own
    // unpublished content.
    $this->setCurrentUser($this->testUsers['view_own_unpublished']);
    // Create a node owned by this user for testing.
    $own_draft_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Own Draft ' . ucfirst($this->getBundle()),
      'uid' => $this->testUsers['view_own_unpublished']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view')->isAllowed(),
      'View own unpublished user can view published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($own_draft_node, 'view')->isAllowed(),
      'View own unpublished user can view own draft ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(),
      'View own unpublished user cannot view others draft ' . $this->getBundle() . 's');

    // Test case 5: view affiliated unpublished content + posting rights - can
    // view affiliated unpublished content.
    $this->setCurrentUser($this->testUsers['view_affiliated_unpublished']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view')->isAllowed(),
      'View affiliated unpublished user can view published ' . $this->getBundle() . 's (has access content permission)');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(),
      'View affiliated unpublished user can view affiliated draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(),
      'View affiliated unpublished user can view refused ' . $this->getBundle() . 's (has posting rights)');

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'view')->isAllowed(), 'No access user cannot view published ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(), 'No access user cannot view draft ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(), 'No access user cannot view refused ' . $this->getBundle() . 's');

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'view')->isAllowed(), 'Anonymous user cannot view published ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(), 'Anonymous user cannot view draft ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(), 'Anonymous user cannot view refused ' . $this->getBundle() . 's');
  }

  /**
   * Test create access for different roles.
   */
  public function testCreateAccess(): void {
    // Test case 1: create bundle content - can create bundle content.
    $this->setCurrentUser($this->testUsers['create_bundle']);
    $this->assertTrue($this->moderationService->entityCreateAccess($this->testUsers['create_bundle'])->isAllowed(), 'Create ' . $this->getBundle() . ' user can create ' . $this->getBundle() . 's');

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    $this->assertFalse($this->moderationService->entityCreateAccess($this->testUsers['no_access'])->isAllowed(), 'No access user cannot create ' . $this->getBundle() . 's');

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    $this->assertFalse($this->moderationService->entityCreateAccess($this->testUsers['anonymous'])->isAllowed(), 'Anonymous user cannot create ' . $this->getBundle() . 's');
  }

  /**
   * Test update access for different roles and node statuses.
   */
  public function testUpdateAccess(): void {
    $published_node = $this->testNodes['published'];
    $draft_node = $this->testNodes['draft'];
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    // Test case 1: edit any bundle content - can edit regular content but not
    // special permission statuses.
    $this->setCurrentUser($this->testUsers['edit_any_bundle']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'update')->isAllowed(),
      'Edit any ' . $this->getBundle() . ' user can update published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'update')->isAllowed(),
      'Edit any ' . $this->getBundle() . ' user can update draft ' . $this->getBundle() . 's');
    // Test special permission statuses - should be denied without permissions.
    foreach ($special_permission_statuses as $status) {
      $node = $this->testNodes[$status] ?? NULL;
      if ($node) {
        $this->assertFalse($this->moderationService->entityAccess($node, 'update')->isAllowed(),
          'Edit any ' . $this->getBundle() . ' user cannot update ' . $status . ' ' . $this->getBundle() . 's without special permission');
      }
    }

    // Test case 2: edit own bundle content + editable + owner - can edit own
    // content.
    $this->setCurrentUser($this->testUsers['edit_own_bundle']);
    // Create a node owned by this user for testing.
    $own_draft_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Own Draft ' . ucfirst($this->getBundle()) . ' for Edit',
      'uid' => $this->testUsers['edit_own_bundle']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);
    // Create a node owned by a different user for testing.
    $other_draft_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Other User Draft ' . ucfirst($this->getBundle()),
      'uid' => $this->testUsers['no_access']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);
    $this->assertTrue($this->moderationService->entityAccess($own_draft_node, 'update')->isAllowed(),
      'Edit own ' . $this->getBundle() . ' user can update own draft ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($other_draft_node, 'update')->isAllowed(),
      'Edit own ' . $this->getBundle() . ' user cannot update others draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'update')->isAllowed(),
      'Edit own ' . $this->getBundle() . ' user can update own published ' . $this->getBundle() . 's');

    // Test case 3: edit affiliated bundle content + editable + posting rights -
    // can edit affiliated content.
    $this->setCurrentUser($this->testUsers['edit_affiliated_bundle']);
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'update')->isAllowed(),
      'Edit affiliated ' . $this->getBundle() . ' user can update affiliated draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'update')->isAllowed(),
      'Edit affiliated ' . $this->getBundle() . ' user can update affiliated published ' . $this->getBundle() . 's');

    // Test case 4: users with special permissions can edit special statuses.
    $this->setCurrentUser($this->testUsers['edit_all_special']);
    foreach ($special_permission_statuses as $status) {
      $node = $this->testNodes[$status] ?? NULL;
      if ($node) {
        $this->assertTrue($this->moderationService->entityAccess($node, 'update')->isAllowed(),
          'Edit all special user can update ' . $status . ' ' . $this->getBundle() . 's');
      }
    }

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'update')->isAllowed(), 'No access user cannot update ' . $this->getBundle() . 's');

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'update')->isAllowed(), 'Anonymous user cannot update ' . $this->getBundle() . 's');
  }

  /**
   * Test delete access for different roles and node statuses.
   */
  public function testDeleteAccess(): void {
    $published_node = $this->testNodes['published'];
    $draft_node = $this->testNodes['draft'];
    $refused_node = $this->testNodes['refused'] ?? $this->testNodes['draft'];

    // Test case 1: delete any bundle content + deletable - can delete any
    // content.
    $this->setCurrentUser($this->testUsers['edit_refused']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'delete')->isAllowed(),
      'Edit refused user can delete published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'delete')->isAllowed(),
      'Edit refused user can delete draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'delete')->isAllowed(),
      'Edit refused user can delete refused ' . $this->getBundle() . 's');

    // Test case 2: delete own bundle content + deletable + owner - can delete
    // own content.
    $this->setCurrentUser($this->testUsers['delete_own_bundle']);
    // Create a node owned by this user for testing.
    $own_draft_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Own Draft ' . ucfirst($this->getBundle()) . ' for Delete',
      'uid' => $this->testUsers['delete_own_bundle']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);
    $this->assertTrue($this->moderationService->entityAccess($own_draft_node, 'delete')->isAllowed(),
      'Delete own ' . $this->getBundle() . ' user can delete own draft ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($draft_node, 'delete')->isAllowed(),
      'Delete own ' . $this->getBundle() . ' user cannot delete others draft ' . $this->getBundle() . 's');
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'delete')->isAllowed(),
      'Delete own ' . $this->getBundle() . ' user cannot delete others published ' . $this->getBundle() . 's');

    // Test case 3: delete affiliated bundle content + deletable + posting
    // rights - can delete affiliated content.
    $this->setCurrentUser($this->testUsers['delete_affiliated_bundle']);
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'delete')->isAllowed(),
      'Delete affiliated ' . $this->getBundle() . ' user can delete affiliated draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'delete')->isAllowed(),
      'Delete affiliated ' . $this->getBundle() . ' user can delete affiliated published ' . $this->getBundle() . 's');

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'delete')->isAllowed(), 'No access user cannot delete ' . $this->getBundle() . 's');

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'delete')->isAllowed(), 'Anonymous user cannot delete ' . $this->getBundle() . 's');
  }

  /**
   * Test view moderation information access.
   */
  public function testViewModerationInformationAccess(): void {
    $published_node = $this->testNodes['published'];
    $draft_node = $this->testNodes['draft'];

    // Test case 1: view moderation information - can view moderation info for
    // any content.
    $this->setCurrentUser($this->testUsers['view_moderation_info']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view_moderation_information')->isAllowed(),
      'View moderation info user can view moderation information');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'view_moderation_information')->isAllowed(),
      'View moderation info user can view moderation information for draft ' . $this->getBundle() . 's');

    // Test case 2: view bundle moderation information + can edit - can view
    // moderation info for bundle content.
    $this->setCurrentUser($this->testUsers['view_bundle_moderation_info']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view_moderation_information')->isAllowed(),
      'View ' . $this->getBundle() . ' moderation info user can view moderation information');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'view_moderation_information')->isAllowed(),
      'View ' . $this->getBundle() . ' moderation info user can view moderation information for draft ' . $this->getBundle() . 's');

    // Test no access user.
    $this->setCurrentUser($this->testUsers['no_access']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'view_moderation_information')->isAllowed(), 'No access user cannot view moderation information');

    // Test anonymous user.
    $this->setCurrentUser($this->testUsers['anonymous']);
    $this->assertFalse($this->moderationService->entityAccess($published_node, 'view_moderation_information')->isAllowed(), 'Anonymous user cannot view moderation information');
  }

  /**
   * Test isEditableStatus method.
   */
  public function testIsEditableStatus(): void {
    $edit_any_bundle = $this->testUsers['edit_any_bundle'];
    $edit_own_bundle = $this->testUsers['edit_own_bundle'];
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    $editable_statuses = $this->getEditableStatuses();

    // Test with edit any bundle user (has basic edit permissions but no special
    // status permissions).
    foreach ($editable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $this->assertTrue($this->moderationService->isEditableStatus($status, $edit_any_bundle),
          ucfirst($status) . ' status is editable for edit any ' . $this->getBundle() . ' user');
      }
    }

    // Test with edit own bundle user (no special permissions for special
    // statuses).
    foreach ($editable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $this->assertTrue($this->moderationService->isEditableStatus($status, $edit_own_bundle),
          ucfirst($status) . ' status is editable for edit own ' . $this->getBundle() . ' user');
      }
    }

    // Test that special permission statuses are not editable without
    // permissions.
    foreach ($special_permission_statuses as $status) {
      $this->assertFalse($this->moderationService->isEditableStatus($status, $edit_any_bundle),
        ucfirst($status) . ' status is not editable for edit any ' . $this->getBundle() . ' user without special permission');
      $this->assertFalse($this->moderationService->isEditableStatus($status, $edit_own_bundle),
        ucfirst($status) . ' status is not editable for edit own ' . $this->getBundle() . ' user without special permission');
    }
  }

  /**
   * Test isDeletableStatus method.
   */
  public function testIsDeletableStatus(): void {
    $edit_all_special = $this->testUsers['edit_all_special'];
    $delete_own_bundle = $this->testUsers['delete_own_bundle'];
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    $deletable_statuses = $this->getDeletableStatuses();

    // Test with edit all special user (has all necessary permissions).
    foreach ($deletable_statuses as $status) {
      $this->assertTrue($this->moderationService->isDeletableStatus($status, $edit_all_special),
        ucfirst($status) . ' status is deletable for edit all special user');
    }

    // Test with delete own bundle user (has delete own bundle content
    // permission but no special status permissions).
    foreach ($deletable_statuses as $status) {
      if (!in_array($status, $special_permission_statuses)) {
        $this->assertTrue($this->moderationService->isDeletableStatus($status, $delete_own_bundle),
          ucfirst($status) . ' status is deletable for delete own ' . $this->getBundle() . ' user');
      }
    }

    // Test that special permission statuses are not deletable without
    // permissions.
    foreach ($special_permission_statuses as $status) {
      $this->assertFalse($this->moderationService->isDeletableStatus($status, $delete_own_bundle),
        ucfirst($status) . ' status is not deletable for delete own ' . $this->getBundle() . ' user without special permission');
    }
  }

  /**
   * Test isViewableStatus method.
   */
  public function testIsViewableStatus(): void {
    $view_any_bundle = $this->testUsers['view_any_bundle'];

    $viewable_statuses = $this->getViewableStatuses();
    $non_viewable_statuses = array_diff($this->getTestStatuses(), $viewable_statuses);

    // Test published statuses (should be viewable)
    foreach ($viewable_statuses as $status) {
      $this->assertTrue($this->moderationService->isViewableStatus($status, $view_any_bundle),
        ucfirst($status) . ' status is viewable');
    }

    // Test non-published statuses (should not be viewable by default)
    foreach ($non_viewable_statuses as $status) {
      $this->assertFalse($this->moderationService->isViewableStatus($status, $view_any_bundle),
        ucfirst($status) . ' status is not viewable');
    }
  }

  /**
   * Test access with different moderation statuses.
   */
  public function testAccessWithDifferentStatuses(): void {
    $statuses = $this->getTestStatuses();
    $edit_all_special = $this->testUsers['edit_all_special'];
    $edit_own_bundle = $this->testUsers['edit_own_bundle'];
    $special_permission_statuses = $this->getSpecialPermissionStatuses();

    foreach ($statuses as $status) {
      $node = $this->testNodes[$status];
      // Test edit all special user access (has all necessary permissions).
      $this->setCurrentUser($edit_all_special);
      $this->assertTrue($this->moderationService->entityAccess($node, 'view')->isAllowed(), "Edit all special user can view {$status} " . $this->getBundle() . 's');
      $this->assertTrue($this->moderationService->entityAccess($node, 'update')->isAllowed(), "Edit all special user can update {$status} " . $this->getBundle() . 's');
      $this->assertTrue($this->moderationService->entityAccess($node, 'delete')->isAllowed(), "Edit all special user can delete {$status} " . $this->getBundle() . 's');

      // Test edit own bundle user access to own content.
      $this->setCurrentUser($edit_own_bundle);
      $view_access = $this->moderationService->entityAccess($node, 'view');
      $update_access = $this->moderationService->entityAccess($node, 'update');
      $delete_access = $this->moderationService->entityAccess($node, 'delete');

      $viewable_statuses = $this->getViewableStatuses();
      if (in_array($status, $viewable_statuses)) {
        $this->assertTrue($view_access->isAllowed(), "Edit own " . $this->getBundle() . " user can view own {$status} " . $this->getBundle() . 's');
      }
      else {
        $this->assertTrue($view_access->isAllowed(), "Edit own " . $this->getBundle() . " user can view own {$status} " . $this->getBundle() . 's (has view own unpublished permission)');
      }

      $editable_statuses = $this->getEditableStatuses();
      if (in_array($status, $special_permission_statuses) && !in_array($status, $editable_statuses)) {
        $this->assertFalse($update_access->isAllowed(), "Edit own " . $this->getBundle() . " user cannot update own {$status} " . $this->getBundle() . 's without permission');
        $this->assertFalse($delete_access->isAllowed(), "Edit own " . $this->getBundle() . " user cannot delete own {$status} " . $this->getBundle() . 's without permission');
      }
      else {
        $this->assertTrue($update_access->isAllowed(), "Edit own " . $this->getBundle() . " user can update own {$status} " . $this->getBundle() . 's');
        $this->assertTrue($delete_access->isAllowed(), "Edit own " . $this->getBundle() . " user can delete own {$status} " . $this->getBundle() . 's');
      }
    }
  }

  /**
   * Test affiliated access with posting rights.
   *
   * This test verifies that users with only "affiliated" permission (without
   * "own" permission) can still view, edit, and delete their own content,
   * as the "affiliated" permission includes access to own content.
   */
  public function testAffiliatedAccessWithPostingRights(): void {
    $affiliated_draft = $this->testNodes['affiliated_draft'];
    $affiliated_published = $this->testNodes['affiliated_published'];
    $author_draft = $this->testNodes['draft'];

    // Test affiliated user access to their own content with posting rights.
    // This user only has "affiliated" permission, not "own" permission.
    $this->setCurrentUser($this->testUsers['edit_affiliated_bundle']);

    // Can view their own content (affiliated permission includes own content).
    $this->assertTrue($this->moderationService->entityAccess($affiliated_draft, 'view')->isAllowed(), 'Affiliated user (without own permission) can view own draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($affiliated_published, 'view')->isAllowed(), 'Affiliated user (without own permission) can view own published ' . $this->getBundle() . 's');

    // Can edit their own content (affiliated permission includes own content).
    $this->assertTrue($this->moderationService->entityAccess($affiliated_draft, 'update')->isAllowed(), 'Affiliated user (without own permission) can update own draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($affiliated_published, 'update')->isAllowed(), 'Affiliated user (without own permission) can update own published ' . $this->getBundle() . 's');

    // Can delete their own content (affiliated permission includes own
    // content).
    $this->assertTrue($this->moderationService->entityAccess($affiliated_draft, 'delete')->isAllowed(), 'Affiliated user (without own permission) can delete own draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($affiliated_published, 'delete')->isAllowed(), 'Affiliated user (without own permission) can delete own published ' . $this->getBundle() . 's');

    // Can edit affiliated content (content from other users with same source).
    $this->assertTrue($this->moderationService->entityAccess($author_draft, 'update')->isAllowed(), 'Affiliated user can update affiliated draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($author_draft, 'delete')->isAllowed(), 'Affiliated user can delete affiliated draft ' . $this->getBundle() . 's');

    // Test affiliated user cannot access content without posting rights.
    // Create a node without source field.
    $no_source_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'No Source ' . ucfirst($this->getBundle()),
      'uid' => $this->testUsers['edit_own_bundle']->id(),
      'moderation_status' => 'draft',
    ]);

    $this->assertFalse($this->moderationService->entityAccess($no_source_node, 'update')->isAllowed(), 'Affiliated user cannot update ' . $this->getBundle() . 's without source field');
    $this->assertFalse($this->moderationService->entityAccess($no_source_node, 'delete')->isAllowed(), 'Affiliated user cannot delete ' . $this->getBundle() . 's without source field');
  }

  /**
   * Test that users with only "affiliated" permission can act on own content.
   *
   * This test explicitly verifies the permissions hierarchy where "affiliated"
   * permission includes access to own content, without needing separate "own"
   * permission.
   */
  public function testAffiliatedPermissionIncludesOwnContent(): void {
    // Create a node owned by the user with only "affiliated" permission.
    $affiliated_user = $this->testUsers['edit_affiliated_bundle'];
    $own_draft_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Own Draft ' . ucfirst($this->getBundle()) . ' for Affiliated User',
      'uid' => $affiliated_user->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);
    $own_published_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Own Published ' . ucfirst($this->getBundle()) . ' for Affiliated User',
      'uid' => $affiliated_user->id(),
      'moderation_status' => 'published',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);

    $this->setCurrentUser($affiliated_user);

    // User with only "affiliated" permission can view own unpublished content.
    $this->assertTrue($this->moderationService->entityAccess($own_draft_node, 'view')->isAllowed(),
      'User with only affiliated permission can view own draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($own_published_node, 'view')->isAllowed(),
      'User with only affiliated permission can view own published ' . $this->getBundle() . 's');

    // User with only "affiliated" permission can edit own content.
    $this->assertTrue($this->moderationService->entityAccess($own_draft_node, 'update')->isAllowed(),
      'User with only affiliated permission can update own draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($own_published_node, 'update')->isAllowed(),
      'User with only affiliated permission can update own published ' . $this->getBundle() . 's');

    // User with only "affiliated" permission can delete own content.
    $this->assertTrue($this->moderationService->entityAccess($own_draft_node, 'delete')->isAllowed(),
      'User with only affiliated permission can delete own draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($own_published_node, 'delete')->isAllowed(),
      'User with only affiliated permission can delete own published ' . $this->getBundle() . 's');

    // Verify the same for delete_affiliated_bundle user.
    $delete_affiliated_user = $this->testUsers['delete_affiliated_bundle'];
    $own_delete_node = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Own Draft ' . ucfirst($this->getBundle()) . ' for Delete Affiliated User',
      'uid' => $delete_affiliated_user->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
    ]);

    $this->setCurrentUser($delete_affiliated_user);
    $this->assertTrue($this->moderationService->entityAccess($own_delete_node, 'delete')->isAllowed(),
      'User with only delete affiliated permission can delete own draft ' . $this->getBundle() . 's');
  }

  /**
   * Test view access for affiliated unpublished content.
   *
   * This test verifies that users with only "view affiliated unpublished"
   * permission (without "view own unpublished" permission) can still view
   * their own unpublished content, as the "affiliated" permission includes
   * access to own content.
   */
  public function testAffiliatedViewUnpublishedAccess(): void {
    $affiliated_draft = $this->testNodes['affiliated_draft'];
    $author_draft = $this->testNodes['draft'];

    // Test affiliated user can view affiliated unpublished content.
    // This user only has "view affiliated unpublished" permission, not
    // "view own unpublished" permission.
    $this->setCurrentUser($this->testUsers['view_affiliated_unpublished']);
    $this->assertTrue($this->moderationService->entityAccess($affiliated_draft, 'view')->isAllowed(),
      'Affiliated user (without own unpublished permission) can view own unpublished ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($author_draft, 'view')->isAllowed(),
      'Affiliated user can view affiliated unpublished ' . $this->getBundle() . 's');

    // Test other users cannot view affiliated unpublished content.
    $this->setCurrentUser($this->testUsers['edit_own_bundle']);
    $this->assertFalse($this->moderationService->entityAccess($affiliated_draft, 'view')->isAllowed(),
      'Author cannot view affiliated unpublished ' . $this->getBundle() . 's');
  }

  /**
   * Test bypass node access permission.
   */
  public function testBypassNodeAccess(): void {
    $published_node = $this->testNodes['published'];
    $draft_node = $this->testNodes['draft'];
    $refused_node = $this->testNodes['refused'] ?? $this->testNodes['draft'];

    // Test bypass node access user can access any content with any operation.
    $this->setCurrentUser($this->testUsers['bypass_node_access']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view')->isAllowed(), 'Bypass node access user can view published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(), 'Bypass node access user can view draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(), 'Bypass node access user can view refused ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'update')->isAllowed(), 'Bypass node access user can update published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'update')->isAllowed(), 'Bypass node access user can update draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'update')->isAllowed(), 'Bypass node access user can update refused ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'delete')->isAllowed(), 'Bypass node access user can delete published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'delete')->isAllowed(), 'Bypass node access user can delete draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'delete')->isAllowed(), 'Bypass node access user can delete refused ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view_moderation_information')->isAllowed(), 'Bypass node access user can view moderation information');
  }

  /**
   * Test administer nodes permission.
   */
  public function testAdministerNodes(): void {
    $published_node = $this->testNodes['published'];
    $draft_node = $this->testNodes['draft'];
    $refused_node = $this->testNodes['refused'] ?? $this->testNodes['draft'];

    // Test administer nodes user can access any content with any operation.
    $this->setCurrentUser($this->testUsers['administer_nodes']);
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view')->isAllowed(), 'Administer nodes user can view published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'view')->isAllowed(), 'Administer nodes user can view draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'view')->isAllowed(), 'Administer nodes user can view refused ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'update')->isAllowed(), 'Administer nodes user can update published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'update')->isAllowed(), 'Administer nodes user can update draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'update')->isAllowed(), 'Administer nodes user can update refused ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'delete')->isAllowed(), 'Administer nodes user can delete published ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($draft_node, 'delete')->isAllowed(), 'Administer nodes user can delete draft ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($refused_node, 'delete')->isAllowed(), 'Administer nodes user can delete refused ' . $this->getBundle() . 's');
    $this->assertTrue($this->moderationService->entityAccess($published_node, 'view_moderation_information')->isAllowed(), 'Administer nodes user can view moderation information');
  }

  /**
   * Test unknown operation returns neutral.
   */
  public function testUnknownOperationReturnsNeutral(): void {
    $published_node = $this->testNodes['published'];

    // Test that unknown operations return neutral access result.
    $this->setCurrentUser($this->testUsers['edit_any_bundle']);
    $access_result = $this->moderationService->entityAccess($published_node, 'unknown_operation');
    $this->assertTrue($access_result->isNeutral(), 'Unknown operation returns neutral access result');
  }

  /**
   * Test access to special permission statuses with appropriate users.
   */
  public function testSpecialPermissionStatusesAccess(): void {
    $special_permission_statuses = $this->getSpecialPermissionStatuses();
    $bundle = $this->getBundle();

    foreach ($special_permission_statuses as $status) {
      if (!isset($this->testNodes[$status])) {
        continue;
      }

      $node = $this->testNodes[$status];

      // Test user without special permissions cannot edit special statuses.
      $this->setCurrentUser($this->testUsers['edit_own_bundle']);
      $this->assertFalse($this->moderationService->entityAccess($node, 'update')->isAllowed(),
        "Edit own {$bundle} user cannot update {$status} {$bundle}s without special permission");
      $this->assertFalse($this->moderationService->entityAccess($node, 'delete')->isAllowed(),
        "Edit own {$bundle} user cannot delete {$status} {$bundle}s without special permission");

      // Test user with appropriate special permission can edit special
      // statuses.
      $special_user = $this->getSpecialPermissionUser($status);
      if ($special_user) {
        $this->setCurrentUser($special_user);
        $this->assertTrue($this->moderationService->entityAccess($node, 'update')->isAllowed(),
          "User with {$status} permission can update {$status} {$bundle}s");
        $this->assertTrue($this->moderationService->entityAccess($node, 'delete')->isAllowed(),
          "User with {$status} permission can delete {$status} {$bundle}s");
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
      'refused' => $this->testUsers['edit_refused'] ?? $this->testUsers['edit_all_special'] ?? NULL,
      'duplicate' => $this->testUsers['edit_duplicate'] ?? $this->testUsers['edit_all_special'] ?? NULL,
      'archive' => $this->testUsers['edit_archived'] ?? $this->testUsers['edit_all_special'] ?? NULL,
      default => $this->testUsers['edit_all_special'] ?? NULL,
    };
  }

  /**
   * Save the original privileged domains state.
   */
  protected function saveOriginalPrivilegedDomainsState(): void {
    $state = \Drupal::service('state');
    $this->originalPrivilegedDomains = $state->get('reliefweb_users_privileged_domains', NULL);
    $this->originalDefaultDomainPostingRights = $state->get('reliefweb_users_privileged_domains_default_posting_rights', NULL);
  }

  /**
   * Restore the original privileged domains state.
   */
  protected function restoreOriginalPrivilegedDomainsState(): void {
    $state = \Drupal::service('state');
    if ($this->originalPrivilegedDomains !== NULL) {
      $state->set('reliefweb_users_privileged_domains', $this->originalPrivilegedDomains);
    }
    else {
      $state->delete('reliefweb_users_privileged_domains');
    }
    if ($this->originalDefaultDomainPostingRights !== NULL) {
      $state->set('reliefweb_users_privileged_domains_default_posting_rights', $this->originalDefaultDomainPostingRights);
    }
    else {
      $state->delete('reliefweb_users_privileged_domains_default_posting_rights');
    }
  }

  /**
   * Test that privileged domain users without explicit posting rights.
   *
   * Test that privileged domain users without explicit posting rights cannot
   * access affiliated content.
   *
   * This test verifies that privileged domain defaults are not used when
   * checking posting rights for access control, as userHasPostingRights
   * calls getUserPostingRights with check_privileged_domains = FALSE.
   */
  public function testPrivilegedDomainUserWithoutExplicitRights(): void {
    $state = \Drupal::service('state');
    $bundle = $this->getBundle();

    // Set up privileged domain with default "allowed" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'allowed');

    // Reset the posting rights cache to ensure new state values are used.
    $userPostingRightsManager = \Drupal::service('reliefweb_moderation.user_posting_rights');
    $userPostingRightsManager->resetCache();

    // Create a node with the source that has no posting rights for the
    // privileged domain user.
    $affiliated_node = $this->createNode([
      'type' => $bundle,
      'title' => 'Affiliated ' . ucfirst($bundle) . ' for Privileged Domain User',
      'uid' => $this->testUsers['edit_own_bundle']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    // Set the privileged domain user as current user.
    $this->setCurrentUser($this->privilegedDomainUser);

    // User should NOT be able to access affiliated content even though they
    // have a privileged domain, because there are no explicit posting rights
    // and privileged domain defaults are not checked for access control.
    $this->assertFalse($this->moderationService->entityAccess($affiliated_node, 'view')->isAllowed(),
      'Privileged domain user without explicit posting rights cannot view affiliated ' . $bundle . 's');
    $this->assertFalse($this->moderationService->entityAccess($affiliated_node, 'update')->isAllowed(),
      'Privileged domain user without explicit posting rights cannot update affiliated ' . $bundle . 's');
    $this->assertFalse($this->moderationService->entityAccess($affiliated_node, 'delete')->isAllowed(),
      'Privileged domain user without explicit posting rights cannot delete affiliated ' . $bundle . 's');

    // User should be able to access their own content (owner permission).
    $own_node = $this->createNode([
      'type' => $bundle,
      'title' => 'Own ' . ucfirst($bundle) . ' for Privileged Domain User',
      'uid' => $this->privilegedDomainUser->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    $this->assertTrue($this->moderationService->entityAccess($own_node, 'view')->isAllowed(),
      'Privileged domain user can view own ' . $bundle . 's (owner permission)');
    $this->assertTrue($this->moderationService->entityAccess($own_node, 'update')->isAllowed(),
      'Privileged domain user can update own ' . $bundle . 's (owner permission)');
    $this->assertTrue($this->moderationService->entityAccess($own_node, 'delete')->isAllowed(),
      'Privileged domain user can delete own ' . $bundle . 's (owner permission)');
  }

  /**
   * Test that privileged domain users with explicit posting rights.
   *
   * Test that privileged domain users with explicit posting rights can access
   * affiliated content.
   *
   * This test verifies that explicit posting rights (user or domain) still
   * work correctly for privileged domain users.
   */
  public function testPrivilegedDomainUserWithExplicitRights(): void {
    $state = \Drupal::service('state');
    $bundle = $this->getBundle();

    // Set up privileged domain with default "allowed" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'allowed');

    // Create a source with explicit user posting rights for the privileged
    // domain user.
    $sourceWithUserRights = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source with User Posting Rights',
      'field_allowed_content_types' => [
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $this->privilegedDomainUser->id(),
          'report' => 2,
          'job' => 2,
          'training' => 2,
        ],
      ],
      'moderation_status' => 'active',
    ]);

    // Create a source with explicit domain posting rights for example.com.
    $sourceWithDomainRights = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source with Domain Posting Rights',
      'field_allowed_content_types' => [
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          'report' => 2,
          'job' => 2,
          'training' => 2,
        ],
      ],
      'moderation_status' => 'active',
    ]);

    // Reset the posting rights cache.
    $userPostingRightsManager = \Drupal::service('reliefweb_moderation.user_posting_rights');
    $userPostingRightsManager->resetCache();

    // Create nodes with these sources.
    $nodeWithUserRights = $this->createNode([
      'type' => $bundle,
      'title' => 'Node with User Posting Rights',
      'uid' => $this->testUsers['edit_own_bundle']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $sourceWithUserRights->id()],
      ],
    ]);

    $nodeWithDomainRights = $this->createNode([
      'type' => $bundle,
      'title' => 'Node with Domain Posting Rights',
      'uid' => $this->testUsers['edit_own_bundle']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $sourceWithDomainRights->id()],
      ],
    ]);

    // Set the privileged domain user as current user.
    $this->setCurrentUser($this->privilegedDomainUser);

    // User SHOULD be able to access affiliated content with explicit user
    // posting rights.
    $this->assertTrue($this->moderationService->entityAccess($nodeWithUserRights, 'view')->isAllowed(),
      'Privileged domain user with explicit user posting rights can view affiliated ' . $bundle . 's');
    $this->assertTrue($this->moderationService->entityAccess($nodeWithUserRights, 'update')->isAllowed(),
      'Privileged domain user with explicit user posting rights can update affiliated ' . $bundle . 's');
    $this->assertTrue($this->moderationService->entityAccess($nodeWithUserRights, 'delete')->isAllowed(),
      'Privileged domain user with explicit user posting rights can delete affiliated ' . $bundle . 's');

    // User SHOULD be able to access affiliated content with explicit domain
    // posting rights.
    $this->assertTrue($this->moderationService->entityAccess($nodeWithDomainRights, 'view')->isAllowed(),
      'Privileged domain user with explicit domain posting rights can view affiliated ' . $bundle . 's');
    $this->assertTrue($this->moderationService->entityAccess($nodeWithDomainRights, 'update')->isAllowed(),
      'Privileged domain user with explicit domain posting rights can update affiliated ' . $bundle . 's');
    $this->assertTrue($this->moderationService->entityAccess($nodeWithDomainRights, 'delete')->isAllowed(),
      'Privileged domain user with explicit domain posting rights can delete affiliated ' . $bundle . 's');
  }

}
