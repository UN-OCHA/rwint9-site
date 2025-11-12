<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Form;

use Drupal\Core\Form\FormState;
use Drupal\reliefweb_moderation\Form\DomainPostingRightsEditForm;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the DomainPostingRightsEditForm.
 */
#[CoversClass(DomainPostingRightsEditForm::class)]
#[Group('reliefweb_moderation')]
class DomainPostingRightsEditFormTest extends ExistingSiteBase {

  /**
   * Source vocabulary.
   */
  protected Vocabulary $sourceVocabulary;

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
    $form = DomainPostingRightsEditForm::create($container);
    $this->assertInstanceOf(DomainPostingRightsEditForm::class, $form);
  }

  /**
   * Test getFormId.
   */
  public function testGetFormId(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $this->assertEquals('reliefweb_moderation_domain_posting_rights_edit_form', $form->getFormId());
  }

  /**
   * Test getTitle.
   */
  public function testGetTitle(): void {
    $title = DomainPostingRightsEditForm::getTitle('example.com');
    $this->assertStringContainsString('example.com', (string) $title);

    // Test with @ prefix.
    $title2 = DomainPostingRightsEditForm::getTitle('@example.com');
    $this->assertStringContainsString('example.com', (string) $title2);

    // Test normalization.
    $title3 = DomainPostingRightsEditForm::getTitle('  EXAMPLE.COM  ');
    $this->assertStringContainsString('example.com', (string) $title3);
  }

  /**
   * Test buildForm with empty domain.
   */
  public function testBuildFormEmptyDomain(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, '');

    $this->assertArrayHasKey('error', $built_form);
    $this->assertStringContainsString('Invalid domain', (string) $built_form['error']['#markup']);
  }

  /**
   * Test buildForm with NULL domain.
   */
  public function testBuildFormNullDomain(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, NULL);

    $this->assertArrayHasKey('error', $built_form);
    $this->assertStringContainsString('Invalid domain', (string) $built_form['error']['#markup']);
  }

  /**
   * Test buildForm with valid domain but no existing rights.
   */
  public function testBuildFormNoExistingRights(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Verify form structure.
    $this->assertArrayHasKey('rights', $built_form);
    $this->assertArrayHasKey('table', $built_form['rights']);
    $this->assertArrayHasKey('add_more', $built_form['rights']);
    $this->assertArrayHasKey('actions', $built_form);

    // Verify table structure.
    $table = $built_form['rights']['table'];
    $this->assertEquals('table', $table['#type']);
    $this->assertArrayHasKey('#header', $table);

    // Verify no existing rows.
    $this->assertEmpty($table['#rows'] ?? []);
  }

  /**
   * Test buildForm with existing domain rights.
   */
  public function testBuildFormWithExistingRights(): void {
    // Add domain posting rights to test source.
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
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Verify form structure.
    $this->assertArrayHasKey('rights', $built_form);
    $this->assertArrayHasKey('table', $built_form['rights']);

    // Verify existing row exists.
    $table = $built_form['rights']['table'];
    $source_id = $this->testSource->id();
    $this->assertArrayHasKey($source_id, $table);

    // Verify default values.
    $this->assertEquals(2, $table[$source_id]['report']['#default_value']);
    $this->assertEquals(1, $table[$source_id]['job']['#default_value']);
    $this->assertEquals(3, $table[$source_id]['training']['#default_value']);
  }

  /**
   * Test buildForm normalizes domain.
   */
  public function testBuildFormNormalizesDomain(): void {
    // Add domain posting rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => 'EXAMPLE.COM',
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();

    // Test with different domain formats.
    $built_form1 = $form->buildForm([], $form_state, 'EXAMPLE.COM');
    $form_state2 = new FormState();
    $built_form2 = $form->buildForm([], $form_state2, '@example.com');
    $form_state3 = new FormState();
    $built_form3 = $form->buildForm([], $form_state3, '  example.com  ');

    // All should find the same rights.
    $source_id = $this->testSource->id();
    $this->assertArrayHasKey($source_id, $built_form1['rights']['table']);
    $this->assertArrayHasKey($source_id, $built_form2['rights']['table']);
    $this->assertArrayHasKey($source_id, $built_form3['rights']['table']);
  }

  /**
   * Test validateForm with invalid source.
   */
  public function testValidateFormInvalidSource(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);
    $form_state->set('new_rows_count', 1);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Set invalid source input.
    $form_state->setValue(['rights', 'table', 'new_0', 'source'], 'Invalid Source Name');

    $form->validateForm($built_form, $form_state);

    // Should have validation error.
    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
  }

  /**
   * Test validateForm with duplicate domain rights.
   */
  public function testValidateFormDuplicateRights(): void {
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
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);
    $form_state->set('new_rows_count', 1);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Try to add the same source again.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $form_state->setValue(['rights', 'table', 'new_0', 'source'], $source_input);

    $form->validateForm($built_form, $form_state);

    // Should have validation error for duplicate.
    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
  }

  /**
   * Test submitForm with no changes.
   */
  public function testSubmitFormNoChanges(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Submit with no values.
    $form_state->setValue(['rights', 'table'], []);
    $form->submitForm($built_form, $form_state);

    // Should redirect and show no changes message.
    $this->assertNotNull($form_state->getRedirect());
  }

  /**
   * Test submitForm adds new domain rights.
   */
  public function testSubmitFormAddNewRights(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);
    $form_state->set('new_rows_count', 1);
    $form_state->set('removed_new_rows', []);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Add new source with rights.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $form_state->setValue(['rights', 'table', 'new_0'], [
      'source' => $source_input,
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
      if (mb_strtolower(trim($item->domain)) === $this->testDomain) {
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
   * Test submitForm updates existing domain rights.
   */
  public function testSubmitFormUpdateExistingRights(): void {
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
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Update rights.
    $source_id = $this->testSource->id();
    // Trusted for reports.
    $form_state->setValue(['rights', 'table', $source_id, 'report'], 3);
    // Allowed for jobs.
    $form_state->setValue(['rights', 'table', $source_id, 'job'], 2);
    // Blocked for trainings.
    $form_state->setValue(['rights', 'table', $source_id, 'training'], 1);

    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were updated.
    $this->testSource = Term::load($this->testSource->id());
    $updated = FALSE;
    foreach ($this->testSource->get('field_domain_posting_rights') as $item) {
      if (mb_strtolower(trim($item->domain)) === $this->testDomain) {
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
   * Test submitForm removes existing domain rights.
   */
  public function testSubmitFormRemoveExistingRights(): void {
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
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Mark for removal.
    $source_id = $this->testSource->id();
    $form_state->setValue(['rights', 'table', $source_id, 'remove'], TRUE);

    $form->submitForm($built_form, $form_state);

    // Reload source and verify rights were removed.
    $this->testSource = Term::load($this->testSource->id());
    $has_rights = FALSE;
    foreach ($this->testSource->get('field_domain_posting_rights') as $item) {
      if (mb_strtolower(trim($item->domain)) === $this->testDomain) {
        $has_rights = TRUE;
        break;
      }
    }
    $this->assertFalse($has_rights, 'Domain posting rights were not removed.');
  }

  /**
   * Test submitForm with multiple sources.
   */
  public function testSubmitFormMultipleSources(): void {
    // Add existing rights to first source.
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
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);
    $form_state->set('new_rows_count', 1);
    $form_state->set('removed_new_rows', []);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Update existing source.
    $source_id1 = $this->testSource->id();
    $form_state->setValue(['rights', 'table', $source_id1, 'report'], 3);

    // Add new source.
    $source_input = $this->testSource2->label() . ' [id:' . $this->testSource2->id() . ']';
    $form_state->setValue(['rights', 'table', 'new_0'], [
      'source' => $source_input,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);

    $form->submitForm($built_form, $form_state);

    // Verify both sources have rights.
    $this->testSource = Term::load($this->testSource->id());
    $this->testSource2 = Term::load($this->testSource2->id());

    $source1_updated = FALSE;
    foreach ($this->testSource->get('field_domain_posting_rights') as $item) {
      if (mb_strtolower(trim($item->domain)) === $this->testDomain) {
        $this->assertEquals(3, (int) $item->report);
        $source1_updated = TRUE;
        break;
      }
    }
    $this->assertTrue($source1_updated);

    $source2_added = FALSE;
    foreach ($this->testSource2->get('field_domain_posting_rights') as $item) {
      if (mb_strtolower(trim($item->domain)) === $this->testDomain) {
        $source2_added = TRUE;
        break;
      }
    }
    $this->assertTrue($source2_added);
  }

  /**
   * Test submitForm does not update if values haven't changed.
   */
  public function testSubmitFormDoesNotUpdateIfValuesHaveNotChanged(): void {
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
    $entity_type_manager = $container->get('entity_type.manager');
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');

    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

    // Set same values (no change).
    $source_id = $this->testSource->id();
    // Allowed for reports.
    $form_state->setValue(['rights', 'table', $source_id, 'report'], 2);
    // Allowed for jobs.
    $form_state->setValue(['rights', 'table', $source_id, 'job'], 2);
    $form_state->setValue(['rights', 'table', $source_id, 'training'], 2);

    // Get the entity before submission to check if it's saved.
    $rights_before = clone $this->testSource->get('field_domain_posting_rights');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reset the entity cache to get the fresh data.
    $term_storage->resetCache([$this->testSource->id()]);

    // Load the source and get the rights.
    $source = $term_storage->load($this->testSource->id());
    $rights_after = $source->get('field_domain_posting_rights');

    // Verify the rights haven't changed.
    $this->assertEquals($rights_before->report, $rights_after->report);
    $this->assertEquals($rights_before->job, $rights_after->job);
    $this->assertEquals($rights_before->training, $rights_after->training);
  }

  /**
   * Test addMoreSubmit adds a new row.
   */
  public function testAddMoreSubmit(): void {
    $container = $this->container;
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

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
    $form = DomainPostingRightsEditForm::create($container);
    $form_state = new FormState();
    $form_state->set('domain', $this->testDomain);
    $form_state->set('new_rows_count', 2);
    $form_state->set('removed_new_rows', []);

    $built_form = $form->buildForm([], $form_state, $this->testDomain);

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

}
