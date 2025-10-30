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
  public function testAddReportAsEditorPublished(): void {
    $this->runTestAddReportAsEditor('published', 'Publish', 'Published', 200);
  }

  /**
   * Test adding a report as an editor, draft.
   */
  public function testAddReportAsEditorDraft(): void {
    $this->runTestAddReportAsEditor('draft', 'Save as draft', 'Draft', 404);
  }

  /**
   * Test adding a report as contributor, draft, unverified.
   */
  public function testAddReportAsContributorDraftUnverified(): void {
    $this->runTestAddReportAsContributorWithSuccess('draft', 'unverified', 'draft', 404);
  }

  /**
   * Test adding a report as contributor, submit, unverified.
   */
  public function testAddReportAsContributorSubmitUnverified(): void {
    $this->runTestAddReportAsContributorWithSuccess('submit', 'unverified', 'pending', 404);
  }

  /**
   * Test adding a report as contributor, draft, blocked.
   */
  public function testAddReportAsContributorDraftBlocked(): void {
    $this->runTestAddReportAsContributorWithError('draft', 'blocked');
  }

  /**
   * Test adding a report as contributor, submit, blocked.
   */
  public function testAddReportAsContributorSubmitBlocked(): void {
    $this->runTestAddReportAsContributorWithError('submit', 'blocked');
  }

  /**
   * Test adding a report as contributor, draft, allowed.
   */
  public function testAddReportAsContributorDraftAllowed(): void {
    $this->runTestAddReportAsContributorWithSuccess('draft', 'allowed', 'draft', 404);
  }

  /**
   * Test adding a report as contributor, submit, allowed.
   */
  public function testAddReportAsContributorSubmitAllowed(): void {
    $this->runTestAddReportAsContributorWithSuccess('submit', 'allowed', 'to-review', 200);
  }

  /**
   * Test adding a report as contributor, draft, trusted.
   */
  public function testAddReportAsContributorDraftTrusted(): void {
    $this->runTestAddReportAsContributorWithSuccess('draft', 'trusted', 'draft', 404);
  }

  /**
   * Test adding a report as contributor, submit, trusted.
   */
  public function testAddReportAsContributorSubmitTrusted(): void {
    $this->runTestAddReportAsContributorWithSuccess('submit', 'trusted', 'published', 200);
  }

  /**
   * Run test for adding a report as editor.
   *
   * @param string $moderation_status
   *   The expected moderation status.
   * @param string $button_text
   *   The button text to click.
   * @param string $status_label
   *   The moderation status label to check.
   * @param int $anonymous_status_code
   *   The expected status code for anonymous users.
   */
  protected function runTestAddReportAsEditor(
    string $moderation_status,
    string $button_text,
    string $status_label,
    int $anonymous_status_code,
  ): void {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['editor'],
    ]);

    $source = $this->createReportSource($user, 'allowed');

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $edit['field_origin_notes[0][value]'] = 'https://example.com/' . $title;
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, $button_text);

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');
    $this->assertSession()->elementTextEquals('css', '.rw-moderation-information__status.rw-moderation-status', $status_label);

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $moderation_status);

    // Check as anonymous.
    $this->drupalLogout();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($anonymous_status_code);
    if ($anonymous_status_code === 200) {
      $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
      $this->assertSession()->pageTextContains('Belgium');
      $this->assertSession()->pageTextContains($source->getName());
      $this->assertSession()->pageTextContains('UN Document');
      $this->assertSession()->pageTextContains('English');
    }
  }

  /**
   * Run test for adding a report as contributor that should result in an error.
   *
   * @param string $action
   *   The action to perform ('draft' or 'submit').
   * @param string $posting_right
   *   The posting right of the source.
   */
  protected function runTestAddReportAsContributorWithError(string $action, string $posting_right): void {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    $source = $this->createReportSource($user, $posting_right);

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $button_text = $action === 'draft' ? 'Save as draft' : 'Submit';
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, $button_text);

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains('Publications from "' . $source->getName() . '" are not allowed.');
  }

  /**
   * Run test for adding a report as contributor that should succeed.
   *
   * @param string $action
   *   The action to perform ('draft' or 'submit').
   * @param string $posting_right
   *   The posting right of the source.
   * @param string $expected_moderation_status
   *   The expected moderation status.
   * @param int $anonymous_status_code
   *   The expected status code for anonymous users.
   */
  protected function runTestAddReportAsContributorWithSuccess(
    string $action,
    string $posting_right,
    string $expected_moderation_status,
    int $anonymous_status_code,
  ): void {
    $title = $this->randomMachineName(32);

    $user = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    $source = $this->createReportSource($user, $posting_right);

    $this->drupalLogin($user);

    $edit = $this->getEditFields($title, $source);
    $button_text = $action === 'draft' ? 'Save as draft' : 'Submit';
    $this->drupalGet('node/add/report');
    $this->submitForm($edit, $button_text);

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains($source->getName());
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $this->drupalLogout();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($anonymous_status_code);
    if ($anonymous_status_code === 200) {
      $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
      $this->assertSession()->pageTextContains('Belgium');
      $this->assertSession()->pageTextContains($source->getName());
      $this->assertSession()->pageTextContains('UN Document');
      $this->assertSession()->pageTextContains('English');
    }
  }

}
