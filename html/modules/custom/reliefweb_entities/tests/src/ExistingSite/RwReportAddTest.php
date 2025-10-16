<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Add a report using browser.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class RwReportAddTest extends RwReportBase {

  /**
   * Test adding a report as an editor, published.
   */
  public function testAddReportAsEditorPublished() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['editor'],
    ]);

    $source = $this->createReportSource($user, 'allowed');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $edit['field_origin_notes[0][value]'] = 'https://example.com/' . $title;
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Publish');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');
    $this->assertSession()->elementTextEquals('css', '.rw-moderation-information__status.rw-moderation-status', 'Published');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'published');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
  }

  /**
   * Test adding a report as an editor, draft.
   */
  public function testAddReportAsEditorDraft() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['editor'],
    ]);

    $source = $this->createReportSource($user, 'allowed');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $edit['field_origin_notes[0][value]'] = 'https://example.com/' . $title;
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');
    $this->assertSession()->elementTextEquals('css', '.rw-moderation-information__status.rw-moderation-status', 'Draft');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'draft');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test adding a report as contributor, draft, unverified.
   */
  public function testAddReportAsContributorDraftUnverified() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'unverified');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'draft');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test adding a report as contributor, submit, unverified.
   */
  public function testAddReportAsContributorSubmitUnverified() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'unverified');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'pending');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test adding a report as contributor, draft, blocked.
   */
  public function testAddReportAsContributorDraftBlocked() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'blocked');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains('Publications from "' . $source->getName() . '" are not allowed.');
  }

  /**
   * Test adding a report as contributor, submit, blocked.
   */
  public function testAddReportAsContributorSubmitBlocked() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'blocked');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains('Publications from "' . $source->getName() . '" are not allowed.');
  }

  /**
   * Test adding a report as contributor, draft, allowed.
   */
  public function testAddReportAsContributorDraftAllowed() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'allowed');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'draft');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test adding a report as contributor, submit, allowed.
   */
  public function testAddReportAsContributorSubmitAllowed() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'allowed');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'to-review');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test adding a report as contributor, draft, trusted.
   */
  public function testAddReportAsContributorDraftTrusted() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'trusted');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Save as draft');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'draft');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test adding a report as contributor, submit, trusted.
   */
  public function testAddReportAsContributorSubmitTrusted() {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    // Create term first so we can assign posting rights.
    $source = $this->createReportSource($user, 'trusted');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, 'published');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
  }

}
