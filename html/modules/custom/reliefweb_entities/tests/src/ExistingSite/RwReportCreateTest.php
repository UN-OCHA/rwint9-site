<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\reliefweb_entities\Entity\Report;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

/**
 * Tests reports.
 */
class RwReportCreateTest extends RwReportBase {

  /**
   * Contributor.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $contributor;

  /**
   * Editor.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $editor;

  /**
   * Create terms and assign permissions.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->contributor = $this->createUser();
    $this->contributor->addRole('contributor');
    $this->contributor->save();

    $this->editor = $this->createUser();
    $this->editor->addRole('editor');
    $this->editor->save();
  }

  /**
   * Test report.
   */
  public function testCreateReportAsEditorDraft() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->editor;
    $this->drupalLogin($user);

    $term_language = $this->createTermIfNeeded('language', 267, 'English');
    $term_country = $this->createTermIfNeeded('country', 34, 'Belgium');
    $term_format = $this->createTermIfNeeded('content_format', 11, 'UN Document');
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    $report = Report::create([
      'uid' => $user->id(),
      'type' => 'report',
      'title' => $title,
      'moderation_status' => 'draft',
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

    // Report will be saved as draft.
    $report->save();

    // OK for user.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->elementTextEquals('css', '.rw-article__title.rw-page-title', $title);

    // 404 for anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($report->toUrl());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test report.
   */
  public function testCreateReportAsEditorPublished() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->editor;
    $this->drupalLogin($user);

    $term_language = $this->createTermIfNeeded('language', 267, 'English');
    $term_country = $this->createTermIfNeeded('country', 34, 'Belgium');
    $term_format = $this->createTermIfNeeded('content_format', 11, 'UN Document');
    $term_source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    $report = Report::create([
      'uid' => $user->id(),
      'type' => 'report',
      'title' => $title,
      'moderation_status' => 'published',
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

    // Report will be saved as published.
    $report->save();

    // OK for user.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->elementTextEquals('css', '.rw-article__title.rw-page-title', $title);

    // OK for anonymous since it's published.
    $this->drupalGet('user/logout');
    $this->drupalGet($report->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->elementTextEquals('css', '.rw-article__title.rw-page-title', $title);
  }

  /**
   * Test report as contributor unverified, draft.
   */
  public function testCreateReportAsContributorUnverifiedDraft() {
    $this->setUserPostingRightsGetSourceTerm(0, 'Unverified');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor unverified, pending.
   */
  public function testCreateReportAsContributorUnverifiedPending() {
    $this->setUserPostingRightsGetSourceTerm(0, 'Unverified');
    $moderation_status = 'pending';
    $expected_moderation_status = 'pending';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor blocked, draft.
   */
  public function testCreateReportAsContributorBlockedDraft() {
    $this->setUserPostingRightsGetSourceTerm(1, 'blocked');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor blocked, pending.
   */
  public function testCreateReportAsContributorBlockedPending() {
    $this->setUserPostingRightsGetSourceTerm(1, 'blocked');
    $moderation_status = 'pending';
    $expected_moderation_status = 'refused';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor allowed, draft.
   */
  public function testCreateReportAsContributorAllowedDraft() {
    $this->setUserPostingRightsGetSourceTerm(2, 'allowed');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor allowed, to-review.
   */
  public function testCreateReportAsContributorAllowedPending() {
    $this->setUserPostingRightsGetSourceTerm(2, 'allowed');
    $moderation_status = 'pending';
    $expected_moderation_status = 'to-review';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, TRUE);
  }

  /**
   * Test report as contributor trusted, draft.
   */
  public function testCreateReportAsContributorTrustedDraft() {
    $this->setUserPostingRightsGetSourceTerm(3, 'trusted');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor trusted, pending.
   */
  public function testCreateReportAsContributorTrustedPending() {
    $this->setUserPostingRightsGetSourceTerm(3, 'trusted');
    $moderation_status = 'pending';
    $expected_moderation_status = 'published';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, TRUE);
  }

  /**
   * Test report as contributor trusted, pending but blocked source.
   */
  public function testCreateReportAsContributorTrustedPendingBlockedSource() {
    $term_source = $this->setUserPostingRightsGetSourceTerm(3, 'trusted', 999999, 'Blocked source');
    $term_source
      ->set('moderation_status', 'blocked')
      ->save();

    $moderation_status = 'pending';
    $expected_moderation_status = 'refused';

    $this->runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, FALSE, [
      'field_source' => [
        $term_source->id(),
      ],
    ]);
  }

  /**
   * Test report as contributor.
   */
  protected function runTestCreateReportAsContributor($moderation_status, $expected_moderation_status, $will_be_public, array $overrides = []) {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $user = $this->contributor;
    $this->drupalLogin($user);

    $term_language = $this->createTermIfNeeded('language', 267, 'English');
    $term_country = $this->createTermIfNeeded('country', 34, 'Belgium');
    $term_format = $this->createTermIfNeeded('content_format', 11, 'UN Document');
    $term_source = Term::load(43679);

    $report = Report::create($overrides + [
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

    // Report will be saved as draft.
    $report->save();

    // OK for user.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->elementTextEquals('css', '.rw-article__title.rw-page-title', $title);

    // Test for anonymous.
    $this->drupalGet('user/logout');
    $this->drupalGet($report->toUrl());
    if ($will_be_public) {
      $this->assertSession()->statusCodeEquals(200);
    }
    else {
      $this->assertSession()->statusCodeEquals(404);
    }

    // Check moderation status.
    $this->assertEquals($report->moderation_status->value, $expected_moderation_status);
  }

  /**
   * Set user posting rights.
   */
  protected function setUserPostingRightsGetSourceTerm($right, $label, $tid = 43679, $term_label = 'ABC Color') : Term {
    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', $tid, $term_label, [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

    // Set posting right to
    $term_source->set('field_user_posting_rights', [
      [
        'id' =>  $this->contributor->id(),
        'job' => '0',
        'training' => '0',
        'report' => $right,
        'notes' => '',
      ],
    ]);
    $term_source->save();

    drupal_static_reset('reliefweb_moderation_getUserPostingRights');

    return $term_source;
  }
}
