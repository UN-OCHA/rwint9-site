<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Form;

use Drupal\Core\Form\FormState;
use Drupal\reliefweb_moderation\Form\RoleCapabilitiesForm;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the RoleCapabilitiesForm.
 */
#[CoversClass(RoleCapabilitiesForm::class)]
#[Group('reliefweb_moderation')]
class RoleCapabilitiesFormTest extends ExistingSiteBase {

  /**
   * Test roles.
   */
  protected array $testRoles = [];

  /**
   * Original permissions for test roles.
   */
  protected array $originalPermissions = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setupTestRoles();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreOriginalPermissions();
    parent::tearDown();
  }

  /**
   * Setup test roles and store their original permissions.
   */
  protected function setupTestRoles(): void {
    $role_ids = ['editor', 'contributor', 'submitter', 'advertiser', 'authenticated'];

    foreach ($role_ids as $role_id) {
      $role = Role::load($role_id);
      if (!$role) {
        // Create the role if it doesn't exist.
        $role = Role::create([
          'id' => $role_id,
          'label' => ucfirst($role_id),
        ]);
        $role->save();
        $this->testRoles[$role_id] = $role;
        $this->originalPermissions[$role_id] = [];
      }
      else {
        $this->testRoles[$role_id] = $role;
        $this->originalPermissions[$role_id] = $role->getPermissions();
      }
    }
  }

  /**
   * Restore original permissions for test roles.
   */
  protected function restoreOriginalPermissions(): void {
    foreach ($this->testRoles as $role_id => $role) {
      if (isset($this->originalPermissions[$role_id])) {
        // Clear all current permissions.
        $current_permissions = $role->getPermissions();
        foreach ($current_permissions as $permission) {
          $role->revokePermission($permission);
        }
        // Restore original permissions.
        foreach ($this->originalPermissions[$role_id] as $permission) {
          $role->grantPermission($permission);
        }
        $role->save();
      }
    }
  }

  /**
   * Test form build.
   */
  public function testBuildForm(): void {
    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Verify form structure.
    $this->assertArrayHasKey('description', $built_form);
    $this->assertArrayHasKey('actions', $built_form);
    $this->assertArrayHasKey('submit', $built_form['actions']);

    // Verify role fieldsets exist.
    $this->assertArrayHasKey('role_contributor', $built_form);
    $this->assertArrayHasKey('role_submitter', $built_form);
    $this->assertArrayHasKey('role_advertiser', $built_form);
    $this->assertArrayHasKey('role_editor', $built_form);
    $this->assertArrayHasKey('role_authenticated', $built_form);

    // Verify tables exist for each role.
    foreach (['contributor', 'submitter', 'advertiser', 'editor', 'authenticated'] as $role_id) {
      $this->assertArrayHasKey('table', $built_form['role_' . $role_id]);
      $table = $built_form['role_' . $role_id]['table'];
      $this->assertEquals('table', $table['#type']);
      $this->assertArrayHasKey('#header', $table);
    }
  }

  /**
   * Test submit form with no changes.
   */
  public function testSubmitFormNoChanges(): void {
    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Submit the form without any changes.
    $form->submitForm($built_form, $form_state);

    // Verify no roles were changed by checking permissions.
    $contributor_before = $this->testRoles['contributor']->getPermissions();
    $submitter_before = $this->testRoles['submitter']->getPermissions();
    $advertiser_before = $this->testRoles['advertiser']->getPermissions();

    // Reload roles to check current state.
    $contributor_after = Role::load('contributor')->getPermissions();
    $submitter_after = Role::load('submitter')->getPermissions();
    $advertiser_after = Role::load('advertiser')->getPermissions();

    $this->assertEquals($contributor_before, $contributor_after);
    $this->assertEquals($submitter_before, $submitter_after);
    $this->assertEquals($advertiser_before, $advertiser_after);
  }

  /**
   * Test submit form with permission changes for contributor role.
   */
  public function testSubmitFormContributorPermissions(): void {
    // Clear contributor permissions first.
    $contributor = $this->testRoles['contributor'];
    $current_permissions = $contributor->getPermissions();
    foreach ($current_permissions as $permission) {
      $contributor->revokePermission($permission);
    }
    $contributor->save();

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Set form values to grant specific permissions.
    // Grant "edit own" for reports, "edit affiliated" for jobs, and "create"
    // for trainings.
    $form_state->setValue([
      'role_contributor',
      'table',
      'edit_content',
      'report',
    ], 'own');

    $form_state->setValue([
      'role_contributor',
      'table',
      'edit_content',
      'job',
    ], 'affiliated');

    $form_state->setValue([
      'role_contributor',
      'table',
      'create_content',
      'training',
    ], 'any');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload the role to check permissions.
    $contributor = Role::load('contributor');
    $this->assertTrue($contributor->hasPermission('edit own report content'));
    $this->assertFalse($contributor->hasPermission('edit any report content'));
    $this->assertFalse($contributor->hasPermission('edit affiliated report content'));

    $this->assertTrue($contributor->hasPermission('edit affiliated job content'));
    $this->assertFalse($contributor->hasPermission('edit any job content'));
    $this->assertFalse($contributor->hasPermission('edit own job content'));

    $this->assertTrue($contributor->hasPermission('create training content'));
  }

  /**
   * Test submit form with multiple permission changes.
   */
  public function testSubmitFormMultiplePermissions(): void {
    // Clear submitter permissions first.
    $submitter = $this->testRoles['submitter'];
    $current_permissions = $submitter->getPermissions();
    foreach ($current_permissions as $permission) {
      $submitter->revokePermission($permission);
    }
    $submitter->save();

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Grant "any" permissions for reports.
    $form_state->setValue([
      'role_submitter',
      'table',
      'view_unpublished',
      'report',
    ], 'any');

    $form_state->setValue([
      'role_submitter',
      'table',
      'edit_content',
      'report',
    ], 'any');

    $form_state->setValue([
      'role_submitter',
      'table',
      'delete_content',
      'report',
    ], 'any');

    // Grant "own" permissions for jobs.
    $form_state->setValue([
      'role_submitter',
      'table',
      'view_unpublished',
      'job',
    ], 'own');

    $form_state->setValue([
      'role_submitter',
      'table',
      'edit_content',
      'job',
    ], 'own');

    $form_state->setValue([
      'role_submitter',
      'table',
      'delete_content',
      'job',
    ], 'own');

    // Grant "affiliated" permissions for trainings.
    $form_state->setValue([
      'role_submitter',
      'table',
      'view_unpublished',
      'training',
    ], 'affiliated');

    $form_state->setValue([
      'role_submitter',
      'table',
      'edit_content',
      'training',
    ], 'affiliated');

    $form_state->setValue([
      'role_submitter',
      'table',
      'delete_content',
      'training',
    ], 'affiliated');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload the role to check permissions.
    $submitter = Role::load('submitter');

    // Report permissions - "any".
    $this->assertTrue($submitter->hasPermission('view any report content'));
    $this->assertTrue($submitter->hasPermission('edit any report content'));
    $this->assertTrue($submitter->hasPermission('delete any report content'));
    $this->assertFalse($submitter->hasPermission('view affiliated unpublished report content'));
    $this->assertFalse($submitter->hasPermission('view own unpublished report content'));

    // Job permissions - "own".
    $this->assertTrue($submitter->hasPermission('view own unpublished job content'));
    $this->assertTrue($submitter->hasPermission('edit own job content'));
    $this->assertTrue($submitter->hasPermission('delete own job content'));
    $this->assertFalse($submitter->hasPermission('view any job content'));
    $this->assertFalse($submitter->hasPermission('edit any job content'));

    // Training permissions - "affiliated".
    $this->assertTrue($submitter->hasPermission('view affiliated unpublished training content'));
    $this->assertTrue($submitter->hasPermission('edit affiliated training content'));
    $this->assertTrue($submitter->hasPermission('delete affiliated training content'));
    $this->assertFalse($submitter->hasPermission('view any training content'));
    $this->assertFalse($submitter->hasPermission('edit any training content'));
  }

  /**
   * Test submit form revokes permissions when set to "no".
   */
  public function testSubmitFormRevokePermissions(): void {
    // Grant some permissions first.
    $advertiser = $this->testRoles['advertiser'];
    $advertiser->grantPermission('create report content');
    $advertiser->grantPermission('edit any job content');
    $advertiser->grantPermission('delete own training content');
    $advertiser->save();

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Revoke permissions by setting to "no".
    $form_state->setValue([
      'role_advertiser',
      'table',
      'create_content',
      'report',
    ], 'no');

    $form_state->setValue([
      'role_advertiser',
      'table',
      'edit_content',
      'job',
    ], 'no');

    $form_state->setValue([
      'role_advertiser',
      'table',
      'delete_content',
      'training',
    ], 'no');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload the role to check permissions are revoked.
    $advertiser = Role::load('advertiser');
    $this->assertFalse($advertiser->hasPermission('create report content'));
    $this->assertFalse($advertiser->hasPermission('edit any job content'));
    $this->assertFalse($advertiser->hasPermission('delete own training content'));
  }

  /**
   * Test submit form does not modify editor or authenticated roles.
   */
  public function testSubmitFormDoesNotModifyEditorOrAuthenticated(): void {
    // Store original permissions.
    $editor_before = $this->testRoles['editor']->getPermissions();
    $authenticated_before = $this->testRoles['authenticated']->getPermissions();

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Try to set values for editor and authenticated roles.
    $form_state->setValue([
      'role_editor',
      'table',
      'edit_content',
      'report',
    ], 'any');

    $form_state->setValue([
      'role_authenticated',
      'table',
      'create_content',
      'job',
    ], 'any');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload roles and verify permissions weren't changed.
    $editor_after = Role::load('editor')->getPermissions();
    $authenticated_after = Role::load('authenticated')->getPermissions();

    $this->assertEquals($editor_before, $editor_after);
    $this->assertEquals($authenticated_before, $authenticated_after);
  }

  /**
   * Test submit form ignores view_published capability.
   */
  public function testSubmitFormIgnoresViewPublished(): void {
    // Get original permissions.
    $contributor_before = $this->testRoles['contributor']->getPermissions();

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Try to set view_published (should be ignored).
    $form_state->setValue([
      'role_contributor',
      'table',
      'view_published',
      'report',
    ], 'any');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload role and verify 'access content' permission wasn't added
    // (or if it was already there, it should remain unchanged).
    $contributor_after = Role::load('contributor')->getPermissions();

    // The view_published capability should not affect permissions.
    // If 'access content' was already there, it should remain.
    // If it wasn't, it should still not be there.
    $had_access_content_before = in_array('access content', $contributor_before);
    $has_access_content_after = in_array('access content', $contributor_after);

    $this->assertEquals($had_access_content_before, $has_access_content_after);
  }

  /**
   * Test submit form with mixed permissions for all editable roles.
   */
  public function testSubmitFormAllEditableRoles(): void {
    // Clear permissions for all editable roles.
    foreach (['contributor', 'submitter', 'advertiser'] as $role_id) {
      $role = $this->testRoles[$role_id];
      $current_permissions = $role->getPermissions();
      foreach ($current_permissions as $permission) {
        $role->revokePermission($permission);
      }
      $role->save();
    }

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Set different permissions for each role.
    // Contributor: create jobs, edit own reports, delete affiliated trainings.
    $form_state->setValue([
      'role_contributor',
      'table',
      'create_content',
      'job',
    ], 'any');

    $form_state->setValue([
      'role_contributor',
      'table',
      'edit_content',
      'report',
    ], 'own');

    $form_state->setValue([
      'role_contributor',
      'table',
      'delete_content',
      'training',
    ], 'affiliated');

    // Submitter: view unpublished any reports, edit own jobs.
    $form_state->setValue([
      'role_submitter',
      'table',
      'view_unpublished',
      'report',
    ], 'any');

    $form_state->setValue([
      'role_submitter',
      'table',
      'edit_content',
      'job',
    ], 'own');

    // Advertiser: create trainings, delete own jobs.
    $form_state->setValue([
      'role_advertiser',
      'table',
      'create_content',
      'training',
    ], 'any');

    $form_state->setValue([
      'role_advertiser',
      'table',
      'delete_content',
      'job',
    ], 'own');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Verify contributor permissions.
    $contributor = Role::load('contributor');
    $this->assertTrue($contributor->hasPermission('create job content'));
    $this->assertTrue($contributor->hasPermission('edit own report content'));
    $this->assertTrue($contributor->hasPermission('delete affiliated training content'));
    $this->assertFalse($contributor->hasPermission('create report content'));
    $this->assertFalse($contributor->hasPermission('edit any report content'));

    // Verify submitter permissions.
    $submitter = Role::load('submitter');
    $this->assertTrue($submitter->hasPermission('view any report content'));
    $this->assertTrue($submitter->hasPermission('edit own job content'));
    $this->assertFalse($submitter->hasPermission('view any job content'));
    $this->assertFalse($submitter->hasPermission('edit any job content'));

    // Verify advertiser permissions.
    $advertiser = Role::load('advertiser');
    $this->assertTrue($advertiser->hasPermission('create training content'));
    $this->assertTrue($advertiser->hasPermission('delete own job content'));
    $this->assertFalse($advertiser->hasPermission('create job content'));
    $this->assertFalse($advertiser->hasPermission('delete any job content'));
  }

  /**
   * Test submit form handles permission transitions (ex: from "any" to "own").
   */
  public function testSubmitFormPermissionTransition(): void {
    // Grant "any" permission first.
    $contributor = $this->testRoles['contributor'];
    $contributor->grantPermission('edit any report content');
    $contributor->grantPermission('delete any job content');
    $contributor->save();

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // Change from "any" to "own" for reports, and from "any" to "affiliated"
    // for jobs.
    $form_state->setValue([
      'role_contributor',
      'table',
      'edit_content',
      'report',
    ], 'own');

    $form_state->setValue([
      'role_contributor',
      'table',
      'delete_content',
      'job',
    ], 'affiliated');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload role and verify permissions were transitioned correctly.
    $contributor = Role::load('contributor');
    // Old "any" permissions should be revoked.
    $this->assertFalse($contributor->hasPermission('edit any report content'));
    $this->assertFalse($contributor->hasPermission('delete any job content'));
    // New permissions should be granted.
    $this->assertTrue($contributor->hasPermission('edit own report content'));
    $this->assertTrue($contributor->hasPermission('delete affiliated job content'));
    // Other related permissions should not be granted.
    $this->assertFalse($contributor->hasPermission('edit affiliated report content'));
    $this->assertFalse($contributor->hasPermission('delete own job content'));
  }

  /**
   * Test that form handles permission changes requiring "apply posting rights".
   */
  public function testSubmitFormWithPostingRightsRequirement(): void {
    // Grant "apply posting rights" permission to advertiser.
    $advertiser = $this->testRoles['advertiser'];
    $advertiser->grantPermission('apply posting rights');
    $advertiser->save();

    $container = \Drupal::getContainer();
    $form = RoleCapabilitiesForm::create($container);

    $form_state = new FormState();
    $built_form = $form->buildForm([], $form_state);

    // The form should show extended options (affiliated, own) when
    // "apply posting rights" is present.
    // Set "affiliated" permissions.
    $form_state->setValue([
      'role_advertiser',
      'table',
      'edit_content',
      'report',
    ], 'affiliated');

    $form_state->setValue([
      'role_advertiser',
      'table',
      'delete_content',
      'job',
    ], 'affiliated');

    // Submit the form.
    $form->submitForm($built_form, $form_state);

    // Reload role and verify permissions.
    $advertiser = Role::load('advertiser');
    $this->assertTrue($advertiser->hasPermission('edit affiliated report content'));
    $this->assertTrue($advertiser->hasPermission('delete affiliated job content'));
    $this->assertFalse($advertiser->hasPermission('edit any report content'));
    $this->assertFalse($advertiser->hasPermission('delete any job content'));
  }

}
