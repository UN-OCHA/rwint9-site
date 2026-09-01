<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_import\JobImport\JobImportStateStore;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests JobImportStateStore per-request import state.
 */
#[CoversClass(JobImportStateStore::class)]
#[Group('reliefweb_import')]
class JobImportStateStoreTest extends UnitTestCase {

  /**
   * Creates a minimal job stub for store tests.
   *
   * @return \Drupal\reliefweb_entities\Entity\Job
   *   Job stub.
   */
  private function createJobStub(): Job {
    return new class() extends Job {

      /**
       * {@inheritdoc}
       */
      public function __construct() {}

    };
  }

  /**
   * Tests marking, errors, notes, and blocking error detection.
   */
  public function testImportStateLifecycle(): void {
    $job = $this->createJobStub();

    $this->assertFalse(JobImportStateStore::isImporting($job));

    JobImportStateStore::markImporting($job, [
      'source' => 'workday',
      'classification_enabled' => TRUE,
      'deferred_fields' => ['field_career_categories', 'field_theme'],
    ]);

    $this->assertTrue(JobImportStateStore::isImporting($job));
    $context = JobImportStateStore::getContext($job);
    $this->assertNotNull($context);
    $this->assertSame('workday', $context->source);
    $this->assertTrue($context->classificationEnabled);

    JobImportStateStore::setError($job, 'field_job_type', 'Missing job type.');
    JobImportStateStore::setError($job, 'field_career_categories', 'Missing career category.');
    JobImportStateStore::addNote($job, 'Experience set to 5-9 years (consultancy default).');

    $this->assertTrue(JobImportStateStore::hasErrors($job));
    $this->assertTrue(JobImportStateStore::hasBlockingErrors($job));
    $this->assertSame([
      'field_job_type' => 'Missing job type.',
      'field_career_categories' => 'Missing career category.',
    ], JobImportStateStore::getErrors($job));
    $this->assertSame([
      'Experience set to 5-9 years (consultancy default).',
    ], JobImportStateStore::getNotes($job));

    JobImportStateStore::clear($job);
    $this->assertFalse(JobImportStateStore::isImporting($job));
  }

  /**
   * Tests that deferred field errors are not blocking when classification runs.
   */
  public function testDeferredErrorsAreNotBlocking(): void {
    $job = $this->createJobStub();

    JobImportStateStore::markImporting($job, [
      'classification_enabled' => TRUE,
      'deferred_fields' => ['field_career_categories', 'field_theme'],
    ]);
    JobImportStateStore::setError($job, 'field_career_categories', 'Missing career category.');
    JobImportStateStore::setError($job, 'field_theme', 'Missing theme.');

    $this->assertTrue(JobImportStateStore::hasErrors($job));
    $this->assertFalse(JobImportStateStore::hasBlockingErrors($job));
  }

}
