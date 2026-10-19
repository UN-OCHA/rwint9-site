<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_entities\Entity\TaxonomyTermBase;
use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Test the career category moderation service.
 */
class CareerCategoryModerationTest extends TermModerationServiceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getModerationService(): ModerationServiceInterface {
    return \Drupal::service('reliefweb_moderation.career_category.moderation');
  }

  /**
   * Test form submit buttons include draft and published.
   */
  public function testEntityFormSubmitButtons(): void {
    $entity = $this->testTerms['published'];
    $buttons = $this->getModerationService()->getEntityFormSubmitButtons('published', $entity);
    $this->assertArrayHasKey('draft', $buttons);
    $this->assertArrayHasKey('published', $buttons);
  }

  /**
   * Test new TaxonomyTermBase terms default to published.
   */
  public function testDefaultModerationStatus(): void {
    $term = Term::create([
      'vid' => 'career_category',
      'name' => 'Test default status career category',
    ]);
    $this->assertInstanceOf(TaxonomyTermBase::class, $term);
    $this->assertEquals('published', $term->getDefaultModerationStatus());
    $this->assertEquals('published', $term->getModerationStatus());
  }

}
