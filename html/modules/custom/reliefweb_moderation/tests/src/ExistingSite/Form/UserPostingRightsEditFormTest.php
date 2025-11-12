<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Form;

use Drupal\Core\Form\FormState;
use Drupal\reliefweb_moderation\Form\UserPostingRightsEditForm;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the UserPostingRightsEditForm.
 */
#[CoversClass(UserPostingRightsEditForm::class)]
#[Group('reliefweb_moderation')]
class UserPostingRightsEditFormTest extends ExistingSiteBase {

  /**
   * Source vocabulary.
   */
  protected Vocabulary $sourceVocabulary;

  /**
   * Test user.
   */
  protected User $testUser;

  /**
   * Test user 2.
   */
  protected User $testUser2;

  /**
   * Test source entity.
   */
  protected Term $testSource;

  /**
   * Test source entity 2.
   */
  protected Term $testSource2;

  /**
   * Test domain.
   */
  protected string $testDomain = 'example.com';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Source vocabulary.
    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->sourceVocabulary->save();
    }

    // Create test users.
    $this->testUser = $this->createUser([], 'test_user', FALSE, [
      'mail' => 'test@example.com',
      'status' => 1,
    ]);

    $this->testUser2 = $this->createUser([], 'test_user2', FALSE, [
      'mail' => 'test2@example.com',
      'status' => 1,
    ]);

    // Create test sources.
    $this->testSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source 1',
      'field_allowed_content_types' => [
        // Job, Report, Training.
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);

    $this->testSource2 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source 2',
      'field_allowed_content_types' => [
        // Job, Report, Training.
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);
  }

  /**
   * Test form creation.
   */
  public function testCreate(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $this->assertInstanceOf(UserPostingRightsEditForm::class, $form);
  }

  /**
   * Test getFormId.
   */
  public function testGetFormId(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $this->assertEquals('reliefweb_moderation_user_posting_rights_edit_form', $form->getFormId());
  }

  /**
   * Test getTitle.
   */
  public function testGetTitle(): void {
    $title = UserPostingRightsEditForm::getTitle($this->testUser);
    $this->assertStringContainsString('test@example.com', (string) $title);
  }

  /**
   * Test buildForm with no existing rights.
   */
  public function testBuildFormNoExistingRights(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Verify form structure.
    $this->assertArrayHasKey('rights', $built_form);
    $this->assertArrayHasKey('table', $built_form['rights']);
    $this->assertArrayHasKey('add_more', $built_form['rights']);
    $this->assertArrayHasKey('actions', $built_form);
    $this->assertArrayHasKey('info', $built_form['rights']);

    // Verify table structure.
    $table = $built_form['rights']['table'];
    $this->assertEquals('table', $table['#type']);
    $this->assertArrayHasKey('#header', $table);

    // Verify no existing rows (no user or domain rights).
    $this->assertEmpty($table['#rows'] ?? []);
  }

  /**
   * Test buildForm with existing user rights.
   */
  public function testBuildFormWithUserRights(): void {
    // Add user posting rights.
    $this->testSource->get('field_user_posting_rights')->appendItem([
      'id' => $this->testUser->id(),
      // Allowed for reports.
      'report' => 2,
      // Blocked for jobs.
      'job' => 1,
      // Trusted for trainings.
      'training' => 3,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Verify existing row exists.
    $table = $built_form['rights']['table'];
    $row_key = 'user_' . $this->testSource->id();
    $this->assertArrayHasKey($row_key, $table);

    // Verify default values.
    $this->assertEquals(2, $table[$row_key]['report']['#default_value']);
    $this->assertEquals(1, $table[$row_key]['job']['#default_value']);
    $this->assertEquals(3, $table[$row_key]['training']['#default_value']);

    // Verify type is "User".
    $this->assertEquals('User', (string) $table[$row_key]['type']['#context']['text']);
  }

  /**
   * Test buildForm with existing domain rights.
   */
  public function testBuildFormWithDomainRights(): void {
    // Add domain posting rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Blocked for jobs.
      'job' => 1,
      // Trusted for trainings.
      'training' => 3,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Verify existing row exists.
    $table = $built_form['rights']['table'];
    $row_key = 'domain_' . $this->testSource->id();
    $this->assertArrayHasKey($row_key, $table);

    // Verify default values.
    $this->assertEquals(2, $table[$row_key]['report']['#default_value']);
    $this->assertEquals(1, $table[$row_key]['job']['#default_value']);
    $this->assertEquals(3, $table[$row_key]['training']['#default_value']);

    // Verify type is "Domain".
    $this->assertEquals('Domain', (string) $table[$row_key]['type']['#context']['text']);
  }

  /**
   * Test buildForm with both user and domain rights.
   */
  public function testBuildFormWithBothRights(): void {
    // Add both user and domain rights.
    $this->testSource->get('field_user_posting_rights')->appendItem([
      'id' => $this->testUser->id(),
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Blocked for reports.
      'report' => 1,
      // Blocked for jobs.
      'job' => 1,
      // Blocked for trainings.
      'training' => 1,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Verify both rows exist.
    $table = $built_form['rights']['table'];
    $user_row_key = 'user_' . $this->testSource->id();
    $domain_row_key = 'domain_' . $this->testSource->id();
    $this->assertArrayHasKey($user_row_key, $table);
    $this->assertArrayHasKey($domain_row_key, $table);
  }

  /**
   * Test validateForm with invalid source.
   */
  public function testValidateFormInvalidSource(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);
    $form_state->set('new_rows_count', 1);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Set invalid source input.
    $form_state->setValue([
      'rights',
      'table',
      'new_0',
      'source',
    ], 'Invalid Source Name');

    $form->validateForm($built_form, $form_state);

    // Should have validation error.
    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
  }

  /**
   * Test validateForm with duplicate user rights.
   */
  public function testValidateFormDuplicateUserRights(): void {
    // Add existing user rights.
    $this->testSource->get('field_user_posting_rights')->appendItem([
      'id' => $this->testUser->id(),
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);
    $form_state->set('new_rows_count', 1);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Try to add the same source with user type again.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $form_state->setValue([
      'rights',
      'table',
      'new_0',
      'source',
    ], $source_input);
    $form_state->setValue([
      'rights',
      'table',
      'new_0',
      'type',
    ], 'user');

    $form->validateForm($built_form, $form_state);

    // Should have validation error for duplicate.
    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
  }

  /**
   * Test validateForm with duplicate domain rights.
   */
  public function testValidateFormDuplicateDomainRights(): void {
    // Add existing domain rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);
    $form_state->set('new_rows_count', 1);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Try to add the same source with domain type again.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $form_state->setValue([
      'rights',
      'table',
      'new_0',
      'source',
    ], $source_input);
    $form_state->setValue([
      'rights',
      'table',
      'new_0',
      'type',
    ], 'domain');

    $form->validateForm($built_form, $form_state);

    // Should have validation error for duplicate.
    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
  }

  /**
   * Test validateForm with user without email for domain type.
   */
  public function testValidateFormDomainTypeWithoutEmail(): void {
    // Create user without email.
    $user_no_email = $this->createUser([], 'user_no_email', FALSE, [
      'mail' => '',
      'status' => 1,
    ]);

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $user_no_email);
    $form_state->set('new_rows_count', 1);

    $built_form = $form->buildForm([], $form_state, $user_no_email);

    // Try to add source with domain type.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $form_state->setValue([
      'rights',
      'table',
      'new_0',
      'source',
    ], $source_input);
    $form_state->setValue([
      'rights',
      'table',
      'new_0',
      'type',
    ], 'domain');

    $form->validateForm($built_form, $form_state);

    // Should have validation error.
    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
  }

  /**
   * Test submitForm with no changes.
   */
  public function testSubmitFormNoChanges(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Submit with no values.
    $form_state->setValue(['rights', 'table'], []);
    $form->submitForm($built_form, $form_state);

    // Should redirect.
    $this->assertNotNull($form_state->getRedirect());
  }

  /**
   * Test submitForm adds new user rights.
   */
  public function testSubmitFormAddNewUserRights(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);
    $form_state->set('new_rows_count', 1);
    $form_state->set('removed_new_rows', []);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Add new source with user rights.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';

    // Set values directly in the form state structure.
    // This ensures getValue() can retrieve them correctly.
    $form_state->setValue('rights', [
      'table' => [
        'new_0' => [
          'source' => $source_input,
          'type' => 'user',
          // Allowed for reports.
          'report' => 2,
          // Trusted for jobs.
          'job' => 3,
          // Blocked for trainings.
          'training' => 1,
        ],
      ],
    ]);

    // Validate the form first.
    $form->validateForm($built_form, $form_state);

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were added.
    // Clear entity cache to ensure we get fresh data.
    \Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache([$this->testSource->id()]);
    $this->testSource = Term::load($this->testSource->id());
    $has_rights = FALSE;
    foreach ($this->testSource->get('field_user_posting_rights') as $item) {
      if (isset($item->id) && (int) $item->id === (int) $this->testUser->id()) {
        $this->assertEquals(2, (int) $item->report);
        $this->assertEquals(3, (int) $item->job);
        $this->assertEquals(1, (int) $item->training);
        $has_rights = TRUE;
        break;
      }
    }
    $this->assertTrue($has_rights, 'User posting rights were not added.');
  }

  /**
   * Test submitForm adds new domain rights.
   */
  public function testSubmitFormAddNewDomainRights(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);
    $form_state->set('new_rows_count', 1);
    $form_state->set('removed_new_rows', []);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Add new source with domain rights.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $form_state->setValue(['rights', 'table', 'new_0'], [
      'source' => $source_input,
      'type' => 'domain',
      // Allowed for reports.
      'report' => 2,
      // Trusted for jobs.
      'job' => 3,
      // Blocked for trainings.
      'training' => 1,
    ]);

    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were added.
    $this->testSource = Term::load($this->testSource->id());
    $has_rights = FALSE;
    foreach ($this->testSource->get('field_domain_posting_rights') as $item) {
      if (isset($item->domain) && mb_strtolower(trim($item->domain)) === $this->testDomain) {
        $this->assertEquals(2, (int) $item->report);
        $this->assertEquals(3, (int) $item->job);
        $this->assertEquals(1, (int) $item->training);
        $has_rights = TRUE;
        break;
      }
    }
    $this->assertTrue($has_rights, 'Domain posting rights were not added.');
  }

  /**
   * Test submitForm updates existing user rights.
   */
  public function testSubmitFormUpdateExistingUserRights(): void {
    // Add existing user rights.
    $this->testSource->get('field_user_posting_rights')->appendItem([
      'id' => $this->testUser->id(),
      // Unverified for reports.
      'report' => 0,
      // Blocked for jobs.
      'job' => 1,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Update rights.
    $row_key = 'user_' . $this->testSource->id();

    // Get existing rights structure and update it.
    $rights = $form_state->getValue(['rights', 'table'], []);
    // Trusted for reports.
    $rights[$row_key]['report'] = 3;
    // Allowed for jobs.
    $rights[$row_key]['job'] = 2;
    // Blocked for trainings.
    $rights[$row_key]['training'] = 1;
    $form_state->setValue(['rights', 'table'], $rights);

    // Validate the form first.
    $form->validateForm($built_form, $form_state);

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were updated.
    $this->testSource = Term::load($this->testSource->id());
    $updated = FALSE;
    foreach ($this->testSource->get('field_user_posting_rights') as $item) {
      if (isset($item->id) && (int) $item->id === (int) $this->testUser->id()) {
        $this->assertEquals(3, (int) $item->report);
        $this->assertEquals(2, (int) $item->job);
        $this->assertEquals(1, (int) $item->training);
        $updated = TRUE;
        break;
      }
    }
    $this->assertTrue($updated, 'User posting rights were not updated.');
  }

  /**
   * Test submitForm updates existing domain rights.
   */
  public function testSubmitFormUpdateExistingDomainRights(): void {
    // Add existing domain rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Unverified for reports.
      'report' => 0,
      // Blocked for jobs.
      'job' => 1,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Update rights.
    $row_key = 'domain_' . $this->testSource->id();
    // Trusted for reports.
    $form_state->setValue(['rights', 'table', $row_key, 'report'], 3);
    // Allowed for jobs.
    $form_state->setValue(['rights', 'table', $row_key, 'job'], 2);
    // Blocked for trainings.
    $form_state->setValue(['rights', 'table', $row_key, 'training'], 1);

    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were updated.
    $this->testSource = Term::load($this->testSource->id());
    $updated = FALSE;
    foreach ($this->testSource->get('field_domain_posting_rights') as $item) {
      if (isset($item->domain) && mb_strtolower(trim($item->domain)) === $this->testDomain) {
        $this->assertEquals(3, (int) $item->report);
        $this->assertEquals(2, (int) $item->job);
        $this->assertEquals(1, (int) $item->training);
        $updated = TRUE;
        break;
      }
    }
    $this->assertTrue($updated, 'Domain posting rights were not updated.');
  }

  /**
   * Test submitForm removes existing user rights.
   */
  public function testSubmitFormRemoveExistingUserRights(): void {
    // Add existing user rights.
    $this->testSource->get('field_user_posting_rights')->appendItem([
      'id' => $this->testUser->id(),
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Mark for removal.
    $row_key = 'user_' . $this->testSource->id();
    $form_state->setValue([
      'rights',
      'table',
      $row_key,
      'remove',
    ], TRUE);

    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were removed.
    $this->testSource = Term::load($this->testSource->id());
    $has_rights = FALSE;
    foreach ($this->testSource->get('field_user_posting_rights') as $item) {
      if (isset($item->id) && (int) $item->id === (int) $this->testUser->id()) {
        $has_rights = TRUE;
        break;
      }
    }
    $this->assertFalse($has_rights, 'User posting rights were not removed.');
  }

  /**
   * Test submitForm removes existing domain rights.
   */
  public function testSubmitFormRemoveExistingDomainRights(): void {
    // Add existing domain rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Mark for removal.
    $row_key = 'domain_' . $this->testSource->id();
    $form_state->setValue([
      'rights',
      'table',
      $row_key,
      'remove',
    ], TRUE);

    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were removed.
    $this->testSource = Term::load($this->testSource->id());
    $has_rights = FALSE;
    foreach ($this->testSource->get('field_domain_posting_rights') as $item) {
      if (isset($item->domain) && mb_strtolower(trim($item->domain)) === $this->testDomain) {
        $has_rights = TRUE;
        break;
      }
    }
    $this->assertFalse($has_rights, 'Domain posting rights were not removed.');
  }

  /**
   * Test submitForm with multiple operations.
   */
  public function testSubmitFormMultipleOperations(): void {
    // Add existing user rights to first source.
    $this->testSource->get('field_user_posting_rights')->appendItem([
      'id' => $this->testUser->id(),
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);
    $form_state->set('new_rows_count', 1);
    $form_state->set('removed_new_rows', []);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Get existing rights structure.
    $rights = $form_state->getValue(['rights', 'table'], []);

    // Update existing source.
    $row_key = 'user_' . $this->testSource->id();
    $rights[$row_key]['report'] = 3;

    // Add new source with domain rights.
    $source_input = $this->testSource2->label() . ' [id:' . $this->testSource2->id() . ']';
    $rights['new_0'] = [
      'source' => $source_input,
      'type' => 'domain',
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ];

    $form_state->setValue(['rights', 'table'], $rights);

    // Validate the form first.
    $form->validateForm($built_form, $form_state);

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Verify both sources were processed.
    $this->testSource = Term::load($this->testSource->id());
    $this->testSource2 = Term::load($this->testSource2->id());

    $source1_updated = FALSE;
    foreach ($this->testSource->get('field_user_posting_rights') as $item) {
      if (isset($item->id) && (int) $item->id === (int) $this->testUser->id()) {
        $this->assertEquals(3, (int) $item->report);
        $source1_updated = TRUE;
        break;
      }
    }
    $this->assertTrue($source1_updated);

    $source2_added = FALSE;
    foreach ($this->testSource2->get('field_domain_posting_rights') as $item) {
      if (isset($item->domain) && mb_strtolower(trim($item->domain)) === $this->testDomain) {
        $source2_added = TRUE;
        break;
      }
    }
    $this->assertTrue($source2_added);
  }

  /**
   * Test addMoreSubmit adds a new row.
   */
  public function testAddMoreSubmit(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Initially no new rows.
    $this->assertEquals(0, $form_state->get('new_rows_count', 0));

    // Call addMoreSubmit.
    $form->addMoreSubmit($built_form, $form_state);

    // Should have one new row.
    $this->assertEquals(1, $form_state->get('new_rows_count', 0));
    $this->assertTrue($form_state->isRebuilding());
  }

  /**
   * Test removeNewRowSubmit removes a row.
   */
  public function testRemoveNewRowSubmit(): void {
    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('user', $this->testUser);
    $form_state->set('new_rows_count', 2);
    $form_state->set('removed_new_rows', []);

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Simulate clicking remove button for new_0.
    $form_state->setTriggeringElement([
      '#name' => 'remove_new_0',
    ]);

    $form->removeNewRowSubmit($built_form, $form_state);

    // new_0 should be in removed list.
    $removed = $form_state->get('removed_new_rows', []);
    $this->assertContains('new_0', $removed);
    $this->assertTrue($form_state->isRebuilding());
  }

  /**
   * Test that user rights take precedence over domain rights.
   *
   * This is more of a documentation test - the form shows both types
   * but the actual precedence logic is in the UserPostingRightsManager.
   */
  public function testFormShowsBothUserAndDomainRights(): void {
    // Add both user and domain rights for same source.
    $this->testSource->get('field_user_posting_rights')->appendItem([
      'id' => $this->testUser->id(),
      // Trusted for reports.
      'report' => 3,
      // Trusted for jobs.
      'job' => 3,
      // Trusted for trainings.
      'training' => 3,
    ]);
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Blocked for reports.
      'report' => 1,
      // Blocked for jobs.
      'job' => 1,
      // Blocked for trainings.
      'training' => 1,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = UserPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, $this->testUser);

    // Both rows should be shown.
    $table = $built_form['rights']['table'];
    $user_row_key = 'user_' . $this->testSource->id();
    $domain_row_key = 'domain_' . $this->testSource->id();
    $this->assertArrayHasKey($user_row_key, $table);
    $this->assertArrayHasKey($domain_row_key, $table);

    // Both should show their respective values.
    $this->assertEquals(3, $table[$user_row_key]['report']['#default_value']);
    $this->assertEquals(1, $table[$domain_row_key]['report']['#default_value']);
  }

}
