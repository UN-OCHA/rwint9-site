<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Add a report using browser.
 */
class RwReportAddTest extends ExistingSiteBase {

  /**
   * Test adding a report as admin, published.
   */
  public function testAddReportAsAdminPublished() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(8);

    $admin = User::load(1);
    $this->drupalLogin($admin);

    $term_language = $this->createTermIfNeeded('language', 267, 'English');
    $term_country = $this->createTermIfNeeded('country', 34, 'Belgium');
    $term_format = $this->createTermIfNeeded('content_format', 267, 'UN Document');
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color');

    // Create a node.
    $edit = [];
    $edit['title[0][value]'] = $title;
    $edit['field_language[' . $term_language->id() . ']'] = $term_language->id();
    $edit['field_country[]'] = [$term_country->id()];
    $edit['field_primary_country'] = $term_country->id();
    $edit['field_content_format'] = $term_format->id();
    $edit['field_origin_notes[0][value]'] = 'https://example.com/' . $title;
    $edit['field_source[]'] = [$term_source->id()];

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
   * Create terms.
   */
  protected function createTermIfNeeded($vocabulary, $id, $title) : Term {
    if ($term = Term::load($id)) {
      return $term;
    }

    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $title,
      'id' => $id,
    ]);
    $term->save();
    return $term;
  }
}
