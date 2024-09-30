<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests reports.
 */
class RwReportCreateTest extends ExistingSiteBase {

  /**
   * Test report.
   */
  public function testReport() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = 'My report';

    $report = Node::create([
      'type' => 'report',
      'title' => $title,
      'field_origin' => 0,
      'field_origin_notes' => 'https://www.example.com/my-report',
    ]);

    // Report will be saved as draft.
    $report->setPublished()->save();

    // 404 for anonymous.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->statusCodeEquals(404);

    // OK for admins.
    $admin = User::load(1);
    $this->drupalLogin($admin);

    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($title . ' | ' . $site_name);
    $this->assertSession()->elementTextEquals('css', '.rw-article__title.rw-page-title', $title);
  }

}
