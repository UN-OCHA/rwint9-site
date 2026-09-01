<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\reliefweb_import\Service\JobFeedsImporter;
use Drupal\reliefweb_import\Service\WorkDayJobDocumentMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Workday import hash behavior.
 */
#[CoversClass(JobFeedsImporter::class)]
#[Group('reliefweb_import')]
class WorkDayJobImporterHashTest extends JobFeedsImporterTestBase {

  /**
   * Tests feed hash excludes non feed-sourced fields and changes with data.
   */
  public function testBuildImportHashDataUsesMappedFeedFieldsOnly(): void {
    $data = (object) [
      'title' => 'Example job',
      'body' => '<p>Body content long enough for import.</p>',
      'field_how_to_apply' => 'Please follow this link to apply: https://example.test/jobs/1',
      'field_job_closing_date' => '2026-09-14',
      'field_job_type' => [WorkDayJobDocumentMapper::JOB_TYPE_JOB],
      'field_job_experience' => [WorkDayJobDocumentMapper::EXPERIENCE_5_9],
      'field_country' => ['USA'],
      'field_career_categories' => [999],
      'field_theme' => [888],
    ];

    $hash_fields = $this->invokeProtectedMethod('getFeedHashFields', []);
    $hash_a = hash('sha256', serialize($this->invokeProtectedMethod('buildImportHashData', [$data, $hash_fields])));

    $changed = clone $data;
    $changed->title = 'Changed title';
    $hash_b = hash('sha256', serialize($this->invokeProtectedMethod('buildImportHashData', [$changed, $hash_fields])));

    $this->assertNotSame($hash_a, $hash_b);

    $with_tags = clone $data;
    $with_tags->field_career_categories = [123];
    $with_tags->field_theme = [456];
    $hash_c = hash('sha256', serialize($this->invokeProtectedMethod('buildImportHashData', [$with_tags, $hash_fields])));

    $this->assertSame($hash_a, $hash_c);
  }

}
