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
    $title = $this->randomMachineName(32);

    $admin = User::load(1);
    $this->drupalLogin($admin);

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Publish');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');
    $this->assertSession()->elementTextEquals('css', '.rw-moderation-information__status.rw-moderation-status', 'Published');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
  }

  /**
   * Test adding a report as admin, draft.
   */
  public function testAddReportAsAdminDraft() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $admin = User::load(1);
    $this->drupalLogin($admin);

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');
    $this->assertSession()->elementTextEquals('css', '.rw-moderation-information__status.rw-moderation-status', 'Draft');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test adding a report as contributor, draft, unverified.
   */
  public function testAddReportAsContributorDraftUnverified() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report unverified');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '0', // Unverified.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);

    // Check moderation status.
    $this->assertEquals($node->moderation_status->value, 'draft');
  }

  /**
   * Test adding a report as contributor, submit, unverified.
   */
  public function testAddReportAsContributorSubmitUnverified() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report unverified');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '0', // Unverified.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);

    // Check moderation status.
    $this->assertEquals($node->moderation_status->value, 'on-hold');
  }

  /**
   * Test adding a report as contributor, draft, blocked.
   */
  public function testAddReportAsContributorDraftBlocked() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report blocked');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '1', // Blocked.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains('Publications from "ABC Color" are not allowed.');
  }

  /**
   * Test adding a report as contributor, submit, blocked.
   */
  public function testAddReportAsContributorSubmitBlocked() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report blocked');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '1', // Blocked.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains('Publications from "ABC Color" are not allowed.');
  }

  /**
   * Test adding a report as contributor, draft, allowed.
   */
  public function testAddReportAsContributorDraftAllowed() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report allowed');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '2', // Allowed.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);

    // Check moderation status.
    $this->assertEquals($node->moderation_status->value, 'draft');
  }

  /**
   * Test adding a report as contributor, submit, allowed.
   */
  public function testAddReportAsContributorSubmitAllowed() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report allowed');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '2', // Allowed.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // Check moderation status.
    $this->assertEquals($node->moderation_status->value, 'to-review');
  }

  /**
   * Test adding a report as contributor, draft, trusted.
   */
  public function testAddReportAsContributorDraftTrusted() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report trusted');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '3', // Trusted.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);

    // Check moderation status.
    $this->assertEquals($node->moderation_status->value, 'draft');
  }

  /**
   * Test adding a report as contributor, submit, trusted.
   */
  public function testAddReportAsContributorSubmitTrusted() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->createUserIfNeeded(2884910, 'report trusted');
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }
    $this->drupalLogin($user);

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' => $user->id(),
        'job' => '0',
        'training' => '0',
        'report' => '3', // Trusted.
        'notes' => '',
      ],
    ]);
    $term_source->save();

    $edit = $this->getEditFields($title);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('ABC Color');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);

    // Check moderation status.
    $this->assertEquals($node->moderation_status->value, 'published');
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
