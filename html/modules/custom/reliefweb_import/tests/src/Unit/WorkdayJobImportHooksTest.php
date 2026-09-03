<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_import\Hook\WorkdayJobImportHooks;
use Drupal\reliefweb_import\JobImport\JobImportStateStore;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests WorkdayJobImportHooks OCHA integration.
 */
#[CoversClass(WorkdayJobImportHooks::class)]
#[Group('reliefweb_import')]
class WorkdayJobImportHooksTest extends UnitTestCase {

  /**
   * The hooks under test.
   *
   * @var \Drupal\reliefweb_import\Hook\WorkdayJobImportHooks
   */
  private WorkdayJobImportHooks $hooks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->hooks = new WorkdayJobImportHooks();
  }

  /**
   * Tests permission check bypass for Workday classification imports.
   */
  public function testUserPermissionCheckAlter(): void {
    $job = $this->createWorkdayJobStub();
    $check = TRUE;
    $account = $this->createStub(AccountInterface::class);

    $this->hooks->userPermissionCheckAlter($check, $account, ['entity' => $job]);
    $this->assertFalse($check);

    $check = TRUE;
    $this->hooks->userPermissionCheckAlter($check, $account, ['entity' => new \stdClass()]);
    $this->assertTrue($check);
  }

  /**
   * Tests specified field check bypass for deferred Workday fields.
   */
  public function testSpecifiedFieldCheckAlter(): void {
    $job = $this->createWorkdayJobStub();
    $workflow = $this->createStub(ClassificationWorkflowInterface::class);
    $fields = [
      'field_career_categories' => TRUE,
      'field_theme' => TRUE,
      'title' => TRUE,
    ];

    $this->hooks->specifiedFieldCheckAlter($fields, $workflow, ['entity' => $job]);
    $this->assertFalse($fields['field_career_categories']);
    $this->assertFalse($fields['field_theme']);
    $this->assertTrue($fields['title']);
  }

  /**
   * Tests pending Workday imports are not skipped for classification.
   */
  public function testSkipClassificationAlterForPendingImports(): void {
    $job = $this->createWorkdayJobStub();
    $workflow = $this->createStub(ClassificationWorkflowInterface::class);
    $skip = TRUE;

    $this->hooks->skipClassificationAlter($skip, $workflow, ['entity' => $job]);
    $this->assertFalse($skip);
  }

  /**
   * Creates a job stub with Workday classification import context.
   *
   * @return \Drupal\reliefweb_entities\Entity\Job
   *   Job stub.
   */
  private function createWorkdayJobStub(): Job {
    $job = new class() extends Job {

      /**
       * {@inheritdoc}
       */
      public function __construct() {}

      /**
       * {@inheritdoc}
       */
      public function getModerationStatus(): string {
        return 'pending';
      }

    };

    JobImportStateStore::markImporting($job, [
      'source' => 'workday',
      'classification_enabled' => TRUE,
      'deferred_fields' => ['field_career_categories', 'field_theme'],
    ]);

    return $job;
  }

}
