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
   * Test report.
   */
  public function testCreateReportAsAdminDraft() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = 'My report';
    $user = User::load(1);
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
  public function testCreateReportAsAdminPublished() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = 'My report';
    $user = User::load(1);
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

    // 404 for anonymous.
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
    $title = 'My report - unverified';
    $this->setUserPostingRightsGetSourceTerm(0, 'Unverified');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor unverified, to-review.
   */
  public function testCreateReportAsContributorUnverifiedToReview() {
    $title = 'My report - unverified';
    $this->setUserPostingRightsGetSourceTerm(0, 'Unverified');
    $moderation_status = 'to-review';
    $expected_moderation_status = 'to-review';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, TRUE);
  }

  /**
   * Test report as contributor blocked, draft.
   */
  public function testCreateReportAsContributorBlockedDraft() {
    $title = 'My report - blocked';
    $this->setUserPostingRightsGetSourceTerm(1, 'blocked');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor blocked, to-review.
   */
  public function testCreateReportAsContributorBlockedToReview() {
    $title = 'My report - blocked';
    $this->setUserPostingRightsGetSourceTerm(1, 'blocked');
    $moderation_status = 'to-review';
    $expected_moderation_status = 'refused';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor allowed, draft.
   */
  public function testCreateReportAsContributorAllowedDraft() {
    $title = 'My report - allowed';
    $this->setUserPostingRightsGetSourceTerm(2, 'allowed');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor allowed, to-review.
   */
  public function testCreateReportAsContributorAllowedToReview() {
    $title = 'My report - allowed';
    $this->setUserPostingRightsGetSourceTerm(2, 'allowed');
    $moderation_status = 'to-review';
    $expected_moderation_status = 'to-review';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, TRUE);
  }

  /**
   * Test report as contributor trusted, draft.
   */
  public function testCreateReportAsContributorTrustedDraft() {
    $title = 'My report - trusted';
    $this->setUserPostingRightsGetSourceTerm(3, 'trusted');
    $moderation_status = 'draft';
    $expected_moderation_status = 'draft';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, FALSE);
  }

  /**
   * Test report as contributor trusted, to-review.
   */
  public function testCreateReportAsContributorTrustedToReview() {
    $title = 'My report - trusted';
    $this->setUserPostingRightsGetSourceTerm(3, 'trusted');
    $moderation_status = 'to-review';
    $expected_moderation_status = 'published';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, TRUE);
  }

  /**
   * Test report as contributor trusted, to-review but blocked source.
   */
  public function testCreateReportAsContributorTrustedToReviewBlockedSource() {
    $title = 'My report - trusted';
    $term_source = $this->setUserPostingRightsGetSourceTerm(3, 'trusted', 2884910, 999999, 'Blocked source');
    $term_source
      ->set('moderation_status', 'blocked')
      ->save();

    $moderation_status = 'to-review';
    $expected_moderation_status = 'refused';

    $this->runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, FALSE, [
      'field_source' => [
        $term_source->id(),
      ],
    ]);
  }

  /**
   * Test report as contributor.
   */
  protected function runTestCreateReportAsContributor($title, $moderation_status, $expected_moderation_status, $will_be_public, array $overrides = []) {
    $site_name = \Drupal::config('system.site')->get('name');

    $user = User::load(2884910);
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
  protected function setUserPostingRightsGetSourceTerm($right, $label, $uid = 2884910, $tid = 43679, $term_label = 'ABC Color') : Term {
    $user = $this->createUserIfNeeded($uid, $label);
    if (!$user->hasRole('contributor')) {
      $user->addRole('contributor');
      $user->save();
    }

    // Create term first so we can assign posting rights.
    $term_source = $this->createTermIfNeeded('source', $tid, $term_label, [
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
        'report' => $right,
        'notes' => '',
      ],
    ]);
    $term_source->save();

    drupal_static_reset('reliefweb_moderation_getUserPostingRights');

    return $term_source;
  }
}
