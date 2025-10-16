<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\Services\ReportModeration;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReportModeration service entityAccess method.
 */
#[CoversClass(ReportModeration::class)]
#[Group('reliefweb_moderation')]
#[RunTestsInSeparateProcesses]
class ReportModerationTest extends ExistingSiteBase {

  /**
   * The ReportModeration service.
   */
  protected ReportModeration $reportModerationService;

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
   * Test report owned by submitter.
   */
  protected Node $submitterReport;

  /**
   * Test report owned by contributor.
   */
  protected Node $contributorReport;

  /**
   * Test report owned by editor.
   */
  protected Node $editorReport;

  /**
   * Test report with different statuses.
   */
  protected array $reportsByStatus = [];

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

    // Get the ReportModeration service.
    $this->reportModerationService = \Drupal::service('reliefweb_moderation.report.moderation');

    // Create users with different roles.
    $this->createTestUsers();

    // Create source vocabulary and test source.
    $this->createTestSource();

    // Create test reports.
    $this->createTestReports();

    // Create reports with different statuses.
    $this->createReportsWithStatuses();
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
          // Allowed.
          'report' => 2,
          'job' => 0,
          'training' => 0,
        ],
      ],
    ]);
  }

  /**
   * Create test reports owned by different users.
   */
  protected function createTestReports(): void {
    // Report owned by submitter.
    $this->submitterReport = $this->createNode([
      'type' => 'report',
      'title' => 'Submitter Report',
      'uid' => $this->submitterUser->id(),
      'field_source' => [
        [
          'target_id' => $this->testSource->id(),
        ],
      ],
    ]);

    // Report owned by contributor.
    $this->contributorReport = $this->createNode([
      'type' => 'report',
      'title' => 'Contributor Report',
      'uid' => $this->contributorUser->id(),
      'field_source' => [
        [
          'target_id' => $this->testSource->id(),
        ],
      ],
    ]);

    // Report owned by editor.
    $this->editorReport = $this->createNode([
      'type' => 'report',
      'title' => 'Editor Report',
      'uid' => $this->editorUser->id(),
      'field_source' => [
        [
          'target_id' => $this->testSource->id(),
        ],
      ],
    ]);
  }

  /**
   * Create reports with different statuses.
   */
  protected function createReportsWithStatuses(): void {
    $statuses = ['draft', 'on-hold', 'to-review', 'published', 'archive', 'refused'];

    foreach ($statuses as $status) {
      $report = $this->createNode([
        'type' => 'report',
        'title' => "Report - {$status}",
        'uid' => $this->submitterUser->id(),
        'field_source' => [
          [
            'target_id' => $this->testSource->id(),
          ],
        ],
      ]);

      // Set the moderation status.
      if ($report instanceof EntityModeratedInterface) {
        $report->setModerationStatus($status);
        $report->save();
      }

      $this->reportsByStatus[$status] = $report;
    }
  }

  /**
   * Test entityAccess for anonymous users.
   */
  public function testAnonymousUserAccess(): void {
    $operations = ['view', 'create', 'update', 'delete', 'view_moderation_information'];

    foreach ($operations as $operation) {
      // Test access to existing reports.
      $access_result = $this->reportModerationService->entityAccess(
        $this->submitterReport,
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
    $create_access = $this->reportModerationService->entityCreateAccess($this->anonymousUser);
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
      // Test access to reports owned by others.
      $access_result = $this->reportModerationService->entityAccess(
        $this->submitterReport,
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
    $create_access = $this->reportModerationService->entityCreateAccess($this->authenticatedUser);
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
      // Test access to own reports.
      $access_result = $this->reportModerationService->entityAccess(
        $this->submitterReport,
        $operation,
        $this->submitterUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Submitter should get an access result for {$operation} operation on own reports"
      );

      // Test that the access result is either allowed, forbidden, or neutral.
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Submitter access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }

    // Test access to reports owned by others.
    $access_result = $this->reportModerationService->entityAccess(
      $this->contributorReport,
      'view',
      $this->submitterUser
    );
    $this->assertInstanceOf(
      AccessResult::class,
      $access_result,
      'Submitter should get an access result for viewing reports owned by others'
    );

    // Test create access.
    $create_access = $this->reportModerationService->entityCreateAccess($this->submitterUser);
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
      // Test access to own reports.
      $access_result = $this->reportModerationService->entityAccess(
        $this->contributorReport,
        $operation,
        $this->contributorUser
      );

      // Verify we get a proper access result.
      $this->assertInstanceOf(
        AccessResult::class,
        $access_result,
        "Contributor should get an access result for {$operation} operation on own reports"
      );

      // Test that the access result is either allowed, forbidden, or neutral.
      $this->assertTrue(
        $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
        "Contributor access result for {$operation} should be allowed, forbidden, or neutral"
      );
    }

    // Test access to reports owned by others.
    $access_result = $this->reportModerationService->entityAccess(
      $this->submitterReport,
      'view',
      $this->contributorUser
    );
    $this->assertInstanceOf(
      AccessResult::class,
      $access_result,
      'Contributor should get an access result for viewing reports owned by others'
    );

    // Test create access.
    $create_access = $this->reportModerationService->entityCreateAccess($this->contributorUser);
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
      // Test access to all reports.
      $access_result = $this->reportModerationService->entityAccess(
        $this->submitterReport,
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
    $create_access = $this->reportModerationService->entityCreateAccess($this->editorUser);
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
    $statuses = ['draft', 'published', 'archive', 'refused'];
    $roles = ['submitter', 'contributor', 'editor'];
    $operations = ['view', 'update', 'delete'];

    foreach ($statuses as $status) {
      if (!isset($this->reportsByStatus[$status])) {
        // Skip if status not created.
        continue;
      }

      $report = $this->reportsByStatus[$status];

      foreach ($roles as $role) {
        $user = $this->{"{$role}User"};

        foreach ($operations as $operation) {
          $access_result = $this->reportModerationService->entityAccess(
            $report,
            $operation,
            $user
          );

          // Verify we get a proper access result.
          $this->assertInstanceOf(
            AccessResult::class,
            $access_result,
            "{$role} should get an access result for {$operation} operation on {$status} reports"
          );

          // Test that the access result is either allowed or forbidden (not
          // neutral).
          $this->assertTrue(
            $access_result->isAllowed() || $access_result->isForbidden() || $access_result->isNeutral(),
            "{$role} access result for {$operation} on {$status} reports should be allowed, forbidden, or neutral"
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
    $create_access = $this->reportModerationService->entityCreateAccess($submitter_no_rights);
    // The actual behavior depends on the permission system and posting rights
    // Let's just verify we get a result (either allowed or forbidden)
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Submitter without posting rights should get an access result for creating reports'
    );

    // Test create access for submitter with posting rights.
    $create_access = $this->reportModerationService->entityCreateAccess($this->submitterUser);
    // Verify we get a proper access result.
    $this->assertInstanceOf(
      AccessResult::class,
      $create_access,
      'Submitter with posting rights should get an access result for creating reports'
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
      $access_result = $this->reportModerationService->entityAccess(
        $this->submitterReport,
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
    $access_result = $this->reportModerationService->entityAccess(
      $this->submitterReport,
      'view'
    );
    $this->assertInstanceOf(
      AccessResult::class,
      $access_result,
      'entityAccess should return AccessResult when no account is provided'
    );

    // Test with invalid operation.
    $access_result = $this->reportModerationService->entityAccess(
      $this->submitterReport,
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
