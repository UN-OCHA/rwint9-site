<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

/**
 * Add a report using browser.
 */
class RwReportAddMultipleSourcesTest extends RwReportBase {

  /**
   * Contributor.
   *
   * @var \Drupal\user\Entity\User $contributor
   */
  protected $contributor;

  /**
   * Unverified source.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $source_unverified;

  /**
   * Blocked source.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $source_blocked;

  /**
   * Allowed source.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $source_allowed;

  /**
   * Trusted source.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $source_trusted;

  /**
   * Random source.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $source_random;

  /**
   * Create terms and assign permissions.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->contributor = $this->createUserIfNeeded(2884910, 'report unverified');
    if (!$this->contributor->hasRole('contributor')) {
      $this->contributor->addRole('contributor');
      $this->contributor->save();
    }

    $rights = [
      0 => 'unverified',
      1 => 'blocked',
      2 => 'allowed',
      3 => 'trusted',
    ];

    foreach ($rights as $id => $right) {
      $label = 'Src ' . $right;
      $field_name = 'source_' . $right;

      // Create term and assign rights.
      $this->{$field_name} = $this->createTermIfNeeded('source', 9999900 + $id, $label, [
        'field_allowed_content_types' => [
          1,
        ],
      ]);

      // Set posting right to
      $this->{$field_name}->set('field_user_posting_rights', [
        [
          'id' => $this->contributor->id(),
          'job' => '0',
          'training' => '0',
          'report' => $id,
          'notes' => '',
        ],
      ]);
      $this->{$field_name}->save();
    }

    // Create term and assign rights.
    $this->source_random = $this->createTermIfNeeded('source', 9999999, 'Src random', [
      'field_allowed_content_types' => [
        1,
      ],
    ]);

  }

  /**
   * Test adding a report - blocked.
   */
  public function testAddReportAsContributorBlockedWithoutRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_unverified->id(),
      $this->source_blocked->id(),
      $this->source_allowed->id(),
      $this->source_trusted->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains('Publications from "Src blocked" are not allowed.');
  }

  /**
   * Test adding a report - unverified.
   */
  public function testAddReportAsContributorUnverifiedWithoutRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_unverified->id(),
      $this->source_allowed->id(),
      $this->source_trusted->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
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
   * Test adding a report - allowed.
   */
  public function testAddReportAsContributorAllowedWithoutRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_allowed->id(),
      $this->source_trusted->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
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
   * Test adding a report - trusted.
   */
  public function testAddReportAsContributorTrustedWithoutRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_trusted->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
    $this->assertSession()->pageTextContains('UN Document');
    $this->assertSession()->pageTextContains('English');

    // Check as anonymous.
    $this->drupalGet('user/logout');
    $node = $this->getNodeByTitle($title);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // Check moderation status.
    $this->assertEquals($node->moderation_status->value, 'published');
  }

  /**
   * Test adding a report - blocked.
   */
  public function testAddReportAsContributorBlockedWithRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_unverified->id(),
      $this->source_blocked->id(),
      $this->source_allowed->id(),
      $this->source_trusted->id(),
      $this->source_random->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has thrown an error.
    $this->assertSession()->pageTextContains('Publications from "Src blocked" are not allowed.');
  }

  /**
   * Test adding a report - unverified.
   */
  public function testAddReportAsContributorUnverifiedWithRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_unverified->id(),
      $this->source_allowed->id(),
      $this->source_trusted->id(),
      $this->source_random->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
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
   * Test adding a report - allowed.
   */
  public function testAddReportAsContributorAllowedWithRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_allowed->id(),
      $this->source_trusted->id(),
      $this->source_random->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
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
   * Test adding a report - trusted.
   */
  public function testAddReportAsContributorTrustedWithRandom() {
    $site_name = \Drupal::config('system.site')->get('name');
    $title = $this->randomMachineName(32);

    $this->drupalLogin($this->contributor);

    $edit = $this->getEditFields($title);
    $edit['field_source[]'] = [
      $this->source_trusted->id(),
      $this->source_random->id(),
    ];

    $this->drupalGet('node/add/report');
    $this->submitForm($edit, 'Submit');

    // Check that the report has been created.
    $this->assertSession()->titleEquals($title . ' - Belgium | ' . $site_name);
    $this->assertSession()->pageTextContains('Report ' . $edit['title[0][value]'] . ' has been created.');
    $this->assertSession()->pageTextContains('Belgium');
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
    $edit['field_source[]'] = [$term_source->id()];

    return $edit;
  }
}
