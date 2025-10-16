<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests reports.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class RwReportCreateTest extends RwReportBase {

  /**
   * Contributor.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $contributor;

  /**
   * Editor.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editor;

  /**
   * Create terms and assign permissions.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->contributor = $this->createUser(values: [
      'roles' => ['contributor'],
    ]);

    $this->editor = $this->createUser(values: [
      'roles' => ['editor'],
    ]);
  }

  /**
   * Test creating a report as editor, draft.
   */
  public function testCreateReportAsEditorDraft() {
    $this->runTestCreateReportAsEditor(
      'draft',
      'draft',
    );
  }

  /**
   * Test creating a report as editor, published.
   */
  public function testCreateReportAsEditorPublished() {
    $this->runTestCreateReportAsEditor(
      'published',
      'published',
    );
  }

  /**
   * Test report as contributor unverified, draft.
   */
  public function testCreateReportAsContributorUnverifiedDraft() {
    $this->runTestCreateReportAsContributor(
      'draft',
      'unverified',
    );
  }

  /**
   * Test report as contributor unverified, pending.
   */
  public function testCreateReportAsContributorUnverifiedPending() {
    $this->runTestCreateReportAsContributor(
      'pending',
      'unverified',
    );
  }

  /**
   * Test report as contributor blocked, draft.
   */
  public function testCreateReportAsContributorBlockedDraft() {
    $this->runTestCreateReportAsContributor(
      'draft',
      'trusted',
    );
  }

  /**
   * Test report as contributor blocked, pending.
   */
  public function testCreateReportAsContributorBlockedPending() {
    $this->runTestCreateReportAsContributor(
      'pending',
      'blocked',
    );
  }

  /**
   * Test report as contributor allowed, draft.
   */
  public function testCreateReportAsContributorAllowedDraft() {
    $this->runTestCreateReportAsContributor(
      'draft',
      'allowed',
    );
  }

  /**
   * Test report as contributor allowed, to-review.
   */
  public function testCreateReportAsContributorAllowedPending() {
    $this->runTestCreateReportAsContributor(
      'pending',
      'allowed',
    );
  }

  /**
   * Test report as contributor trusted, draft.
   */
  public function testCreateReportAsContributorTrustedDraft() {
    $this->runTestCreateReportAsContributor(
      'draft',
      'trusted',
    );
  }

  /**
   * Test report as contributor trusted, pending.
   */
  public function testCreateReportAsContributorTrustedPending() {
    $this->runTestCreateReportAsContributor(
      'pending',
      'trusted',
    );
  }

  /**
   * Test report as contributor trusted, pending but blocked source.
   */
  public function testCreateReportAsContributorTrustedPendingBlockedSource() {
    $this->runTestCreateReportAsContributor(
      'pending',
      'trusted',
      // Blocked source.
      'blocked',
    );
  }

  /**
   * Test report as editor.
   *
   * @param string $moderation_status
   *   The moderation status.
   * @param string $expected_moderation_status
   *   The expected moderation status.
   */
  protected function runTestCreateReportAsEditor(
    string $moderation_status,
    string $expected_moderation_status,
  ): void {
    $this->runTestCreateReport(
      $this->editor,
      $moderation_status,
      $expected_moderation_status,
    );
  }

  /**
   * Test report as contributor.
   *
   * @param string $moderation_status
   *   The moderation status.
   * @param string|null $posting_right
   *   The posting right.
   * @param string|null $source_moderation_status
   *   The source moderation status.
   */
  protected function runTestCreateReportAsContributor(
    string $moderation_status,
    ?string $posting_right = NULL,
    ?string $source_moderation_status = 'active',
  ): void {
    $this->runTestCreateReport(
      $this->contributor,
      $moderation_status,
      NULL,
      $posting_right,
      $source_moderation_status,
    );
  }

  /**
   * Test report as contributor.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to create the report for.
   * @param string $moderation_status
   *   The moderation status.
   * @param string|null $expected_moderation_status
   *   The expected moderation status.
   * @param string|null $posting_right
   *   The posting right.
   * @param string|null $source_moderation_status
   *   The source moderation status.
   */
  protected function runTestCreateReport(
    AccountInterface $user,
    string $moderation_status,
    ?string $expected_moderation_status = NULL,
    ?string $posting_right = NULL,
    ?string $source_moderation_status = 'active',
  ): void {
    $title = $this->randomMachineName(32);

    $this->drupalLogin($user);

    $term_language = $this->createTermIfNeeded('language', 267, 'English');
    $term_country = $this->createTermIfNeeded('country', 34, 'Belgium');
    $term_format = $this->createTermIfNeeded('content_format', 11, 'UN Document');
    $term_source = $this->createReportSource($user, $posting_right, $source_moderation_status);

    $report = $this->createNode([
      'uid' => $user->id(),
      'revision_uid' => $user->id(),
      'type' => 'report',
      'title' => $title,
      'moderation_status' => $moderation_status,
      'field_origin' => 0,
      'field_origin_notes' => 'https://www.example.com/my-report',
      'field_language' => $term_language->id(),
      'field_country' => [
        $term_country->id(),
      ],
      'field_primary_country' => $term_country->id(),
      'field_content_format' => $term_format->id(),
      'field_source' => [
        $term_source->id(),
      ],
    ]);

    // OK for user.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
    $this->assertSession()->elementTextEquals('css', '.rw-article__title.rw-page-title', $title);

    // Get the expected moderation status.
    if ($source_moderation_status === 'blocked') {
      $expected_moderation_status = 'refused';
    }
    elseif (!is_null($expected_moderation_status)) {
      $expected_moderation_status = $expected_moderation_status;
    }
    elseif ($moderation_status === 'pending' && !is_null($posting_right)) {
      $expected_moderation_status = match ($posting_right) {
        'trusted' => $this->getModerationStatusForScenario('trusted_all'),
        'allowed' => $this->getModerationStatusForScenario('allowed_all'),
        'unverified' => $this->getModerationStatusForScenario('unverified_all'),
        'blocked' => $this->getModerationStatusForScenario('blocked'),
        default => $moderation_status,
      };
    }
    else {
      $expected_moderation_status = $moderation_status;
    }

    $this->assertEquals($report->moderation_status->value, $expected_moderation_status);

    // Test for anonymous.
    $expected_response_status = $this->getExpectedResponseStatusAsAnonymous($expected_moderation_status);
    $this->drupalGet('user/logout');
    $this->drupalGet($report->toUrl());
    $this->assertSession()->statusCodeEquals($expected_response_status);
    if ($expected_response_status === 200) {
      $this->assertSession()->titleEquals($title . ' - Belgium | ' . $this->siteName);
      $this->assertSession()->elementTextEquals('css', '.rw-article__title.rw-page-title', $title);
    }
  }

}
