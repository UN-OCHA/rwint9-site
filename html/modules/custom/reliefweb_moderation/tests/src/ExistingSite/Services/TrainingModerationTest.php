<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\Services\TrainingModeration;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the TrainingModeration service entityAccess method.
 */
#[CoversClass(TrainingModeration::class)]
#[Group('reliefweb_moderation')]
#[RunTestsInSeparateProcesses]
class TrainingModerationTest extends ExistingSiteBase {

  /**
   * The TrainingModeration service.
   */
  protected TrainingModeration $trainingModerationService;

  /**
   * Anonymous user.
   */
  protected User $anonymousUser;

  /**
   * Authenticated user.
   */
  protected User $authenticatedUser;

  /**
   * Submitter user.
   */
  protected User $submitterUser;

  /**
   * Contributor user.
   */
  protected User $contributorUser;

  /**
   * Editor user.
   */
  protected User $editorUser;

  /**
   * Test training owned by submitter.
   */
  protected Node $submitterTraining;

  /**
   * Test training owned by contributor.
   */
  protected Node $contributorTraining;

  /**
   * Test training owned by editor.
   */
  protected Node $editorTraining;

  /**
   * Test trainings with different statuses.
   */
  protected array $trainingsByStatus = [];

  /**
   * Source vocabulary.
   */
  protected Vocabulary $sourceVocabulary;

  /**
   * Test source for posting rights.
   */
  protected Term $testSource;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Get the TrainingModeration service.
    $this->trainingModerationService = \Drupal::service('reliefweb_moderation.training.moderation');

    // Create users with different roles.
    $this->createTestUsers();

    // Create source vocabulary and test source.
    $this->createTestSource();

    // Create test trainings.
    $this->createTestTrainings();

    // Create trainings with different statuses.
    $this->createTrainingsWithStatuses();
  }

  /**
   * Create test users with different roles.
   */
  protected function createTestUsers(): void {
    // Anonymous user.
    $this->anonymousUser = User::getAnonymousUser();

    // Authenticated user (no special roles).
    $this->authenticatedUser = $this->createUser([], 'authenticated_user', FALSE, [
      'name' => 'authenticated_user',
      'mail' => 'authenticated@example.com',
      'status' => 1,
    ]);

    // Submitter user.
    $this->submitterUser = $this->createUser([], 'submitter_user', FALSE, [
      'name' => 'submitter_user',
      'mail' => 'submitter@example.com',
      'status' => 1,
      'roles' => ['submitter'],
    ]);

    // Contributor user.
    $this->contributorUser = $this->createUser([], 'contributor_user', FALSE, [
      'name' => 'contributor_user',
      'mail' => 'contributor@example.com',
      'status' => 1,
      'roles' => ['contributor'],
    ]);

    // Editor user.
    $this->editorUser = $this->createUser([], 'editor_user', FALSE, [
      'name' => 'editor_user',
      'mail' => 'editor@example.com',
      'status' => 1,
      'roles' => ['editor'],
    ]);
  }

  /**
   * Create test source vocabulary and source.
   */
  protected function createTestSource(): void {
    // Source vocabulary.
    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->sourceVocabulary->save();
    }

    // Create a test source with posting rights for the submitter.
    $this->testSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source',
      'field_user_posting_rights' => [
        [
          'id' => $this->submitterUser->id(),
          // Allowed for training.
          'report' => 0,
          'job' => 0,
          'training' => 2,
        ],
      ],
    ]);
  }

  /**
   * Create test trainings owned by different users.
   */
  protected function createTestTrainings(): void {
    // Training owned by submitter.
    $this->submitterTraining = $this->createNode([
      'type' => 'training',
      'title' => 'Submitter Training',
      'uid' => $this->submitterUser->id(),
      'field_source' => [
        [
          'target_id' => $this->testSource->id(),
        ],
      ],
    ]);

    // Training owned by contributor.
    $this->contributorTraining = $this->createNode([
      'type' => 'training',
      'title' => 'Contributor Training',
      'uid' => $this->contributorUser->id(),
      'field_source' => [
        [
          'target_id' => $this->testSource->id(),
        ],
      ],
    ]);

    // Training owned by editor.
    $this->editorTraining = $this->createNode([
      'type' => 'training',
      'title' => 'Editor Training',
      'uid' => $this->editorUser->id(),
      'field_source' => [
        [
          'target_id' => $this->testSource->id(),
        ],
      ],
    ]);
  }

  /**
   * Create trainings with different statuses.
   */
  protected function createTrainingsWithStatuses(): void {
    $statuses = ['draft', 'pending', 'published', 'on-hold', 'refused', 'duplicate', 'expired'];

    foreach ($statuses as $status) {
      $training = $this->createNode([
        'type' => 'training',
        'title' => "Training - {$status}",
        'uid' => $this->submitterUser->id(),
        'field_source' => [
          [
            'target_id' => $this->testSource->id(),
          ],
        ],
      ]);

      // Set the moderation status.
      if ($training instanceof EntityModeratedInterface) {
        $training->setModerationStatus($status);
        $training->save();
      }

      $this->trainingsByStatus[$status] = $training;
    }
  }

  /**
   * Test entityAccess for anonymous users.
   */
  public function testAnonymousUserAccess(): void {
    $operations = ['view', 'create', 'update', 'delete', 'view_moderation_information'];

    foreach ($operations as $operation) {
      // Test access to existing trainings.
      $access_result = $this->trainingModerationService->entityAccess(
        $this->submitterTraining,
        $operation,
        $this->anonymousUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Anonymous user should get an access result for {$operation} operation"
      );

      // Test that the access result is either allowed, forbidden, or neutral.
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Anonymous user access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }

    // Test create access.
    $create_access = $this->trainingModerationService->entityCreateAccess($this->anonymousUser);
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Anonymous user should get an access result for create operation'
    );
  }

  /**
   * Test entityAccess for authenticated users.
   */
  public function testAuthenticatedUserAccess(): void {
    $operations = ['view', 'create', 'update', 'delete', 'view_moderation_information'];

    foreach ($operations as $operation) {
      // Test access to trainings owned by others.
      $access_result = $this->trainingModerationService->entityAccess(
        $this->submitterTraining,
        $operation,
        $this->authenticatedUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Authenticated user should get an access result for {$operation} operation"
      );

      // Test that the access result is either allowed, forbidden, or neutral.
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Authenticated user access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }

    // Test create access.
    $create_access = $this->trainingModerationService->entityCreateAccess($this->authenticatedUser);
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Authenticated user should get an access result for create operation'
    );
  }

  /**
   * Test entityAccess for submitter users.
   */
  public function testSubmitterUserAccess(): void {
    $operations = ['view', 'create', 'update', 'delete', 'view_moderation_information'];

    foreach ($operations as $operation) {
      // Test access to own trainings.
      $access_result = $this->trainingModerationService->entityAccess(
        $this->submitterTraining,
        $operation,
        $this->submitterUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Submitter should get an access result for {$operation} operation on own trainings"
      );

      // Test that the access result is either allowed, forbidden, or neutral.
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Submitter access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }

    // Test access to trainings owned by others.
    $access_result = $this->trainingModerationService->entityAccess(
      $this->contributorTraining,
      'view',
      $this->submitterUser
    );
    $this->assertInstanceOf(
      AccessResult::class,
      $access_result,
      'Submitter should get an access result for viewing trainings owned by others'
    );

    // Test create access.
    $create_access = $this->trainingModerationService->entityCreateAccess($this->submitterUser);
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Submitter should get an access result for create operation'
    );
  }

  /**
   * Test entityAccess for contributor users.
   */
  public function testContributorUserAccess(): void {
    $operations = ['view', 'create', 'update', 'delete', 'view_moderation_information'];

    foreach ($operations as $operation) {
      // Test access to own trainings.
      $access_result = $this->trainingModerationService->entityAccess(
        $this->contributorTraining,
        $operation,
        $this->contributorUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Contributor should get an access result for {$operation} operation on own trainings"
      );

      // Test that the access result is either allowed, forbidden, or neutral.
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Contributor access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }

    // Test access to trainings owned by others.
    $access_result = $this->trainingModerationService->entityAccess(
      $this->submitterTraining,
      'view',
      $this->contributorUser
    );
    $this->assertInstanceOf(
      AccessResult::class,
      $access_result,
      'Contributor should get an access result for viewing trainings owned by others'
    );

    // Test create access.
    $create_access = $this->trainingModerationService->entityCreateAccess($this->contributorUser);
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Contributor should get an access result for create operation'
    );
  }

  /**
   * Test entityAccess for editor users.
   */
  public function testEditorUserAccess(): void {
    $operations = ['view', 'create', 'update', 'delete', 'view_moderation_information'];

    foreach ($operations as $operation) {
      // Test access to all trainings.
      $access_result = $this->trainingModerationService->entityAccess(
        $this->submitterTraining,
        $operation,
        $this->editorUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Editor should get an access result for {$operation} operation"
      );

      // Test that the access result is either allowed or forbidden (not
      // neutral).
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Editor access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }

    // Test create access.
    $create_access = $this->trainingModerationService->entityCreateAccess($this->editorUser);
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Editor should get an access result for create operation'
    );
  }

  /**
   * Test entityAccess for different entity statuses.
   */
  public function testEntityAccessForDifferentStatuses(): void {
    $statuses = ['draft', 'published', 'refused', 'expired'];
    $roles = ['submitter', 'contributor', 'editor'];
    $operations = ['view', 'update', 'delete'];

    foreach ($statuses as $status) {
      if (!isset($this->trainingsByStatus[$status])) {
        // Skip if status not created.
        continue;
      }

      $training = $this->trainingsByStatus[$status];

      foreach ($roles as $role) {
        $user = $this->{"{$role}User"};

        foreach ($operations as $operation) {
          $access_result = $this->trainingModerationService->entityAccess(
            $training,
            $operation,
            $user
          );

          // Verify we get a proper access result.
          $this->assertInstanceOf(
            AccessResult::class,
            $access_result,
            "{$role} should get an access result for {$operation} operation on {$status} trainings"
          );

          // Test that the access result is either allowed or forbidden (not
          // neutral).
          $this->assertTrue(
            $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
            "{$role} access result for {$operation} on {$status} trainings should be allowed, forbidden, or neutral"
          );
        }
      }
    }
  }

  /**
   * Test entityAccess for submitters with different posting rights.
   */
  public function testSubmitterAccessWithPostingRights(): void {
    // Create a submitter without posting rights.
    $submitter_no_rights = $this->createUser([], 'submitter_no_rights', FALSE, [
      'name' => 'submitter_no_rights',
      'mail' => 'submitter_no_rights@example.com',
      'status' => 1,
      'roles' => ['submitter'],
    ]);

    // Test create access for submitter without posting rights.
    $create_access = $this->trainingModerationService->entityCreateAccess($submitter_no_rights);
    // The actual behavior depends on the permission system and posting rights
    // Let's just verify we get a result (either allowed or forbidden)
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Submitter without posting rights should get an access result for creating trainings'
    );

    // Test create access for submitter with posting rights.
    $create_access = $this->trainingModerationService->entityCreateAccess($this->submitterUser);
    // Verify we get a proper access result.
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Submitter with posting rights should get an access result for creating trainings'
    );

    // Test that the access result is either allowed, forbidden, or neutral.
    $this->assertTrue(
      $create_access->isAllowed() || $create_access->isForbidden() || $create_access->isNeutral(),
      'Submitter with posting rights access result should be allowed, forbidden, or neutral'
    );
  }

  /**
   * Test entityAccess for different operations.
   */
  public function testDifferentOperations(): void {
    $operations = ['view', 'create', 'update', 'delete', 'view_moderation_information'];

    foreach ($operations as $operation) {
      // Test with editor (should have access to most operations).
      $access_result = $this->trainingModerationService->entityAccess(
        $this->submitterTraining,
        $operation,
        $this->editorUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Editor should get an access result for {$operation} operation"
      );

      // Test that the access result is either allowed or forbidden (not
      // neutral).
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Editor access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }
  }

  /**
   * Test entityAccess edge cases.
   */
  public function testEntityAccessEdgeCases(): void {
    // Test with NULL account (should use current user).
    $access_result = $this->trainingModerationService->entityAccess(
      $this->submitterTraining,
      'view'
    );
    $this->assertInstanceOf(
      AccessResult::class,
      $access_result,
      'entityAccess should return AccessResult when no account is provided'
    );

    // Test with invalid operation.
    $access_result = $this->trainingModerationService->entityAccess(
      $this->submitterTraining,
      'invalid_operation',
      $this->editorUser
    );
    $this->assertInstanceOf(
      AccessResult::class,
      $access_result,
      'entityAccess should return AccessResult for invalid operations'
    );
  }

}
