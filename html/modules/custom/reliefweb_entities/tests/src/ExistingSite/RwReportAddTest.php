<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\user\Entity\User;

/**
 * Add a report using browser.
 */
class RwReportAddTest extends RwReportBase {

  /**
   * Test adding a report as admin, published.
   */
  public function testAddReportAsAdminPublished() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(8);

    $admin = User::load(1);
    $this->drupalLogin($admin);

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Publish');

    // Check that the Basic page has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');
    $this->assertSession()->elementTextEquals('css', '.rw-moderation-information__status.rw-moderation-status', 'Published');
  }

  /**
   * Test adding a report as admin, draft.
   */
  public function testAddReportAsAdminDraft() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(8);

    $admin = User::load(1);
    $this->drupalLogin($admin);

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the Basic page has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');
    $this->assertSession()->elementTextEquals('css', '.rw-moderation-information__status.rw-moderation-status', 'Draft');
  }

  protected function getEditFields($title) {
    $term_language = $this->createTermIfNeeded('language', 267, 'English');
    $term_country = $this->createTermIfNeeded('country', 34, 'Belgium');
    $term_format = $this->createTermIfNeeded('content_format', 11, 'UN Document');
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Create a node.
    $edit = [];
    $edit['title[0][value]'] = $title;
    $edit['field_language[' . $term_language->id() . ']'] = $term_language->id();
    $edit['field_country[]'] = [$term_country->id()];
    $edit['field_primary_country'] = $term_country->id();
    $edit['field_content_format'] = $term_format->id();
    $edit['field_origin_notes[0][value]'] = 'https://example.com/' . $title;
    $edit['field_source[]'] = [$term_source->id()];

    return $edit;
  }
}
