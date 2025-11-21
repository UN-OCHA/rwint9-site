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
  public function testAddReportAsContributorBlockedWithoutRandom(): void {
    $this->runTestAddReportWithError([
      'unverified',
      'blocked',
      'allowed',
      'trusted',
    ], 'blocked');
  }

  /**
   * Test adding a report - unverified.
   */
  public function testAddReportAsContributorUnverifiedWithoutRandom(): void {
    $this->runTestAddReportWithSuccess([
      'unverified',
      'allowed',
      'trusted',
    ], 'trusted_some_unverified');
  }

  /**
   * Test adding a report - allowed.
   */
  public function testAddReportAsContributorAllowedWithoutRandom(): void {
    $this->runTestAddReportWithSuccess([
      'allowed',
      'trusted',
    ], 'trusted_some_allowed');
  }

  /**
   * Test adding a report - trusted.
   */
  public function testAddReportAsContributorTrustedWithoutRandom(): void {
    $this->runTestAddReportWithSuccess([
      'trusted',
    ], 'trusted_all');
  }

  /**
   * Test adding a report - blocked.
   */
  public function testAddReportAsContributorBlockedWithRandom(): void {
    $this->runTestAddReportWithError([
      'unverified',
      'blocked',
      'allowed',
      'trusted',
      'random',
    ], 'blocked');
  }

  /**
   * Test adding a report - unverified.
   */
  public function testAddReportAsContributorUnverifiedWithRandom(): void {
    $this->runTestAddReportWithSuccess([
      'unverified',
      'allowed',
      'trusted',
      'random',
    ], 'trusted_some_unverified');
  }

  /**
   * Test adding a report - allowed.
   */
  public function testAddReportAsContributorAllowedWithRandom(): void {
    $this->runTestAddReportWithSuccess([
      'allowed',
      'trusted',
      'random',
    ], 'trusted_some_unverified');
  }

  /**
   * Test adding a report - trusted.
   */
  public function testAddReportAsContributorTrustedWithRandom(): void {
    $this->runTestAddReportWithSuccess([
      'trusted',
      'random',
    ], 'trusted_some_unverified');
  }

  /**
   * Run test for adding a report that should result in an error.
   *
   * @param array $source_keys
   *   Array of source keys to include in the report.
   * @param string $blocked_source_key
   *   The key of the blocked source to check in the error message.
   */
  protected function runTestAddReportWithError(array $source_keys, string $blocked_source_key): void {
    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($this->randomMachineName(32));
    $edit['field_source[]'] = array_map(function ($key) {
      return $this->sources[$key]->id();
    }, $source_keys);

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains(strtr('Publications from "@source" are not allowed.', [
      '@source' => $this->sources[$blocked_source_key]->getName(),
    ]));
  }

  /**
   * Run test for adding a report that should succeed.
   *
   * @param array $source_keys
   *   Array of source keys to include in the report.
   * @param string $moderation_scenario
   *   The moderation scenario to check.
   */
  protected function runTestAddReportWithSuccess(array $source_keys, string $moderation_scenario): void {
    $title = $this->randomMachineName(32);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = array_map(function ($key) {
      return $this->sources[$key]->id();
    }, $source_keys);

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
    $expected_moderation_status = $this->getModerationStatusForScenario($moderation_scenario);
    $node = $this->getNodeByTitle($title);
    $this->assertEquals($node->moderation_status->value, $expected_moderation_status);

    // Check as anonymous.
    $expected_response_status = $this->getExpectedResponseStatusForEntityAndUser($node, $this->anonymous);
    $this->drupalLogout();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
    if ($expected_response_status === 200) {
      $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
      $this->assertSession()->pageTextContains('Belgium');
      $this->assertSession()->pageTextContains('UN Document');
      $this->assertSession()->pageTextContains('English');
    }
  }

}
