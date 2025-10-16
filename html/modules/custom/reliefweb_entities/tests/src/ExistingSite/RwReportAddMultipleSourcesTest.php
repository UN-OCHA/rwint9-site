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
class RwReportAddMultipleSourcesTest extends RwReportBase {

  /**
   * Contributor.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $contributor;

  /**
   * Sources.
   *
   * @var \Drupal\taxonomy\TermInterface[]
   */
  protected $sources;

  /**
   * Create terms and assign permissions.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->contributor = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    $rights = [
      0 => 'unverified',
      1 => 'blocked',
      2 => 'allowed',
      3 => 'trusted',
    ];

    // Create sources for each right.
    foreach ($rights as $right) {
      $this->sources[$right] = $this->createReportSource($this->contributor, $right);
    }

    // Create a random source without posting rights.
    $this->sources['random'] = $this->createReportSource($this->contributor, NULL);
  }

  /**
   * Test adding a report - blocked.
   */
  public function testAddReportAsContributorBlockedWithoutRandom() {
    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($this->randomMachineName(32));
    $edit['field_source[]'] = [
      $this->sources['unverified']->id(),
      $this->sources['blocked']->id(),
      $this->sources['allowed']->id(),
      $this->sources['trusted']->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains(strtr('Publications from "@source" are not allowed.', [
      '@source' => $this->sources['blocked']->getName(),
    ]));
  }

  /**
   * Test adding a report - unverified.
   */
  public function testAddReportAsContributorUnverifiedWithoutRandom() {
    $title = $this->randomMachineName(32);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->sources['unverified']->id(),
      $this->sources['allowed']->id(),
      $this->sources['trusted']->id(),
    ];

    $this->drupalLogin($this->contributor);

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $expected_moderation_status = $this->getModerationStatusForScenario('trusted_some_unverified');
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $expected_response_status = $this->getExpectedResponseStatusAsAnonymous($expected_moderation_status);
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
  }

  /**
   * Test adding a report - allowed.
   */
  public function testAddReportAsContributorAllowedWithoutRandom() {
    $title = $this->randomMachineName(32);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->sources['allowed']->id(),
      $this->sources['trusted']->id(),
    ];

    $this->drupalLogin($this->contributor);

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $expected_moderation_status = $this->getModerationStatusForScenario('trusted_some_allowed');
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $expected_response_status = $this->getExpectedResponseStatusAsAnonymous($expected_moderation_status);
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
  }

  /**
   * Test adding a report - trusted.
   */
  public function testAddReportAsContributorTrustedWithoutRandom() {
    $title = $this->randomMachineName(32);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->sources['trusted']->id(),
    ];

    $this->drupalLogin($this->contributor);

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $expected_moderation_status = $this->getModerationStatusForScenario('trusted_all');
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $expected_response_status = $this->getExpectedResponseStatusAsAnonymous($expected_moderation_status);
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
  }

  /**
   * Test adding a report - blocked.
   */
  public function testAddReportAsContributorBlockedWithRandom() {
    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($this->randomMachineName(32));
    $edit['field_source[]'] = [
      $this->sources['unverified']->id(),
      $this->sources['blocked']->id(),
      $this->sources['allowed']->id(),
      $this->sources['trusted']->id(),
      $this->sources['random']->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains(strtr('Publications from "@source" are not allowed.', [
      '@source' => $this->sources['blocked']->label(),
    ]));
  }

  /**
   * Test adding a report - unverified.
   */
  public function testAddReportAsContributorUnverifiedWithRandom() {
    $title = $this->randomMachineName(32);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->sources['unverified']->id(),
      $this->sources['allowed']->id(),
      $this->sources['trusted']->id(),
      $this->sources['random']->id(),
    ];

    $this->drupalLogin($this->contributor);

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $expected_moderation_status = $this->getModerationStatusForScenario('trusted_some_unverified');
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $expected_response_status = $this->getExpectedResponseStatusAsAnonymous($expected_moderation_status);
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
  }

  /**
   * Test adding a report - allowed.
   */
  public function testAddReportAsContributorAllowedWithRandom() {
    $title = $this->randomMachineName(32);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->sources['allowed']->id(),
      $this->sources['trusted']->id(),
      $this->sources['random']->id(),
    ];

    $this->drupalLogin($this->contributor);

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $expected_moderation_status = $this->getModerationStatusForScenario('trusted_some_unverified');
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $expected_response_status = $this->getExpectedResponseStatusAsAnonymous($expected_moderation_status);
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
  }

  /**
   * Test adding a report - trusted.
   */
  public function testAddReportAsContributorTrustedWithRandom() {
    $title = $this->randomMachineName(32);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->sources['trusted']->id(),
      $this->sources['random']->id(),
    ];

    $this->drupalLogin($this->contributor);

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check moderation status.
    $expected_moderation_status = $this->getModerationStatusForScenario('trusted_some_unverified');
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $expected_response_status = $this->getExpectedResponseStatusAsAnonymous($expected_moderation_status);
    $this->drupalGet('user/logout');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
  }

}
