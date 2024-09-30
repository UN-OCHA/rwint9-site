<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\node\Entity\Node;
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

    // Create a node.
    $edit = [];
    $edit['title[0][value]'] = $title;
    $edit['field_language[267]'] = 'en';
    $edit['field_country[]'] = ['34'];
    $edit['field_primary_country'] = '34';
    $edit['field_content_format'] = '11';
    $edit['field_origin_notes[0][value]'] = 'https://example.com/' . $title;
    $edit['field_source[]'] = ['43679'];
    $edit['field_source[]'] = ['43679'];

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

}
