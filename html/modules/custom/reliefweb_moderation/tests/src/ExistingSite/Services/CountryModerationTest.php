<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_moderation\ModerationServiceInterface;

/**
 * Test the country moderation service.
 */
class CountryModerationTest extends TermModerationServiceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getModerationService(): ModerationServiceInterface {
    return \Drupal::service('reliefweb_moderation.country.moderation');
  }

  /**
   * Test country-specific status behavior.
   */
  public function testCountrySpecificStatusBehavior(): void {
    $ongoing_term = $this->testTerms['ongoing'];
    $normal_term = $this->testTerms['normal'];

    // Test that both ongoing and normal statuses are viewable.
    $this->setCurrentUser($this->testUsers['access_content']);
    $this->assertTrue($this->moderationService->entityAccess($ongoing_term, 'view')->isAllowed(),
      'Access content user can view ongoing country terms');
    $this->assertTrue($this->moderationService->entityAccess($normal_term, 'view')->isAllowed(),
      'Access content user can view normal country terms');

    // Test that both statuses are published.
    $this->assertTrue($this->moderationService->isPublishedStatus('ongoing'),
      'Ongoing status is published');
    $this->assertTrue($this->moderationService->isPublishedStatus('normal'),
      'Normal status is published');
  }

  /**
   * Test country status transitions.
   */
  public function testCountryStatusTransitions(): void {
    $ongoing_term = $this->testTerms['ongoing'];
    $normal_term = $this->testTerms['normal'];

    // Test that both statuses are editable.
    $this->setCurrentUser($this->testUsers['edit_terms']);
    $this->assertTrue($this->moderationService->entityAccess($ongoing_term, 'update')->isAllowed(),
      'Edit terms user can update ongoing country terms');
    $this->assertTrue($this->moderationService->entityAccess($normal_term, 'update')->isAllowed(),
      'Edit terms user can update normal country terms');

    // Test that both statuses are not deletable without delete permission.
    $this->assertFalse($this->moderationService->entityAccess($ongoing_term, 'delete')->isAllowed(),
      'Edit terms user cannot delete ongoing country terms without delete permission');
    $this->assertFalse($this->moderationService->entityAccess($normal_term, 'delete')->isAllowed(),
      'Edit terms user cannot delete normal country terms without delete permission');
  }

}
