<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_moderation\ModerationServiceInterface;

/**
 * Test the disaster moderation service.
 */
class DisasterModerationTest extends TermModerationServiceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getModerationService(): ModerationServiceInterface {
    return \Drupal::service('reliefweb_moderation.disaster.moderation');
  }

  /**
   * {@inheritdoc}
   */
  protected function createTestRoles(): void {
    parent::createTestRoles();

    // Add manage external disasters role.
    $this->testRoles['manage_external_disasters'] = $this->createRole([
      'access content',
      'edit terms in ' . $this->getBundle(),
      'delete terms in ' . $this->getBundle(),
      'manage external disasters',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function createTestUsers(): void {
    parent::createTestUsers();

    // Create user with manage external disasters permission.
    $this->testUsers['manage_external_disasters'] = $this->createUser(values: [
      'roles' => [$this->testRoles['manage_external_disasters']],
    ]);
  }

  /**
   * Test disaster-specific status behavior.
   */
  public function testDisasterSpecificStatusBehavior(): void {
    $alert_term = $this->testTerms['alert'];
    $ongoing_term = $this->testTerms['ongoing'];
    $external_term = $this->testTerms['external'] ?? NULL;

    // Test that published statuses are viewable.
    $this->setCurrentUser($this->testUsers['access_content']);
    $this->assertTrue($this->moderationService->entityAccess($alert_term, 'view')->isAllowed(),
      'Access content user can view alert disaster terms');
    $this->assertTrue($this->moderationService->entityAccess($ongoing_term, 'view')->isAllowed(),
      'Access content user can view ongoing disaster terms');

    // Test that external disasters are only viewable by users with manage
    // external disasters permission.
    if ($external_term) {
      $this->setCurrentUser($this->testUsers['access_content']);
      $this->assertFalse($this->moderationService->entityAccess($external_term, 'view')->isAllowed(),
        'Access content user cannot view external disaster terms');

      $this->setCurrentUser($this->testUsers['manage_external_disasters']);
      $this->assertTrue($this->moderationService->entityAccess($external_term, 'view')->isAllowed(),
        'User with manage external disasters permission can view external disaster terms');
    }
  }

  /**
   * Test disaster status permissions.
   */
  public function testDisasterStatusPermissions(): void {
    $draft_term = $this->testTerms['draft'];
    $external_term = $this->testTerms['external'] ?? NULL;

    // Test that users with edit terms permission can edit regular disasters.
    $this->setCurrentUser($this->testUsers['edit_terms']);
    $this->assertTrue($this->moderationService->entityAccess($draft_term, 'update')->isAllowed(),
      'Edit terms user can update regular disaster terms');

    // Test that external disasters are only editable by users with manage
    // external disasters permission.
    if ($external_term) {
      $this->setCurrentUser($this->testUsers['edit_terms']);
      $this->assertFalse($this->moderationService->entityAccess($external_term, 'update')->isAllowed(),
        'Edit terms user cannot update external disaster terms without manage external disasters permission');

      $this->setCurrentUser($this->testUsers['manage_external_disasters']);
      $this->assertTrue($this->moderationService->entityAccess($external_term, 'update')->isAllowed(),
        'User with manage external disasters permission can update external disaster terms');
    }
  }

  /**
   * Test disaster status transitions.
   */
  public function testDisasterStatusTransitions(): void {
    // Test that published statuses are published.
    $this->assertTrue($this->moderationService->isPublishedStatus('alert'),
      'Alert status is published');
    $this->assertTrue($this->moderationService->isPublishedStatus('ongoing'),
      'Ongoing status is published');
    $this->assertTrue($this->moderationService->isPublishedStatus('past'),
      'Past status is published');

    // Test that draft status is not published.
    $this->assertFalse($this->moderationService->isPublishedStatus('draft'),
      'Draft status is not published');
  }

  /**
   * Test disaster archive functionality.
   */
  public function testDisasterArchiveFunctionality(): void {
    // Test that archive status is recognized as a valid status.
    $this->assertTrue($this->moderationService->hasStatus('archive'),
      'Archive status is recognized as valid status');

    // Test that archive status is not a direct status but used for transitions.
    $statuses = $this->getModerationService()->getStatuses();
    $this->assertArrayNotHasKey('archive', $statuses,
      'Archive is not a direct status but used for transitions');
  }

  /**
   * Test disaster notification behavior.
   */
  public function testDisasterNotificationBehavior(): void {
    $alert_term = $this->testTerms['alert'];
    $ongoing_term = $this->testTerms['ongoing'];
    $draft_term = $this->testTerms['draft'];

    // Test that notifications are disabled for certain statuses.
    $this->moderationService->disableNotifications($alert_term, 'alert');
    $this->assertFalse($alert_term->notifications_content_disable,
      'Notifications are enabled for alert status');

    $this->moderationService->disableNotifications($ongoing_term, 'ongoing');
    $this->assertFalse($ongoing_term->notifications_content_disable,
      'Notifications are enabled for ongoing status');

    $this->moderationService->disableNotifications($draft_term, 'draft');
    $this->assertTrue($draft_term->notifications_content_disable,
      'Notifications are disabled for draft status');
  }

}
