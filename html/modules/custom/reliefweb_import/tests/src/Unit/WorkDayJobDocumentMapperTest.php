<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\reliefweb_import\Service\WorkDayJobDocumentMapper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests WorkDayJobDocumentMapper field and experience mapping.
 */
#[CoversClass(WorkDayJobDocumentMapper::class)]
#[Group('reliefweb_import')]
class WorkDayJobDocumentMapperTest extends UnitTestCase {

  /**
   * The mapper under test.
   *
   * @var \Drupal\reliefweb_import\Service\WorkDayJobDocumentMapper
   */
  private WorkDayJobDocumentMapper $mapper;

  /**
   * Workday API fixture jobs.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $fixtureJobs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mapper = new WorkDayJobDocumentMapper();
    $fixture_path = dirname(__DIR__, 7) . '/data/RW-1531/workday.json';
    $fixture = json_decode((string) file_get_contents($fixture_path), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->fixtureJobs = $fixture['data'];
  }

  /**
   * Data provider for job type mapping.
   *
   * @return array<string, array{0: string, 1: int}>
   *   Test cases.
   */
  public static function jobTypeProvider(): array {
    return [
      'intern fixed term' => ['Temporary or Intern (Paid) (Fixed Term)', WorkDayJobDocumentMapper::JOB_TYPE_INTERNSHIP],
      'consultant' => ['Contractor / Consultant', WorkDayJobDocumentMapper::JOB_TYPE_CONSULTANCY],
      'regular job' => ['Regular', WorkDayJobDocumentMapper::JOB_TYPE_JOB],
    ];
  }

  /**
   * Tests job type descriptor mapping.
   */
  #[DataProvider('jobTypeProvider')]
  public function testMapJobType(string $descriptor, int $expected_tid): void {
    $this->assertSame($expected_tid, $this->mapper->mapJobType($descriptor));
  }

  /**
   * Tests years-to-bucket mapping.
   */
  public function testMapYearsToExperienceTerm(): void {
    $this->assertSame(WorkDayJobDocumentMapper::EXPERIENCE_0_2, $this->mapper->mapYearsToExperienceTerm(2));
    $this->assertSame(WorkDayJobDocumentMapper::EXPERIENCE_3_4, $this->mapper->mapYearsToExperienceTerm(3));
    $this->assertSame(WorkDayJobDocumentMapper::EXPERIENCE_5_9, $this->mapper->mapYearsToExperienceTerm(7));
    $this->assertSame(WorkDayJobDocumentMapper::EXPERIENCE_10_PLUS, $this->mapper->mapYearsToExperienceTerm(12));
  }

  /**
   * Tests regex parsing on known Workday samples.
   */
  public function testParseMinimumYearsFromBodyOnFixtureSamples(): void {
    $job = $this->findFixtureJob('Materials and Labor Specialist');
    $years = $this->mapper->parseMinimumYearsFromBody((string) $job['jobDescription']);
    $this->assertSame(5, $years);

    $job = $this->findFixtureJob('HR Officer');
    $years = $this->mapper->parseMinimumYearsFromBody((string) $job['jobDescription']);
    $this->assertSame(2, $years);
  }

  /**
   * Tests full mapApiJob output for a regular job.
   */
  public function testMapApiJobMapsCoreFields(): void {
    $job = $this->findFixtureJob('Materials and Labor Specialist');
    $mapped = $this->mapper->mapApiJob($job);

    $this->assertSame($job['title'], $mapped->title);
    $this->assertSame($job['jobDescription'], $mapped->body);
    $this->assertSame($job['endDate'], $mapped->field_job_closing_date);
    $this->assertSame([WorkDayJobDocumentMapper::JOB_TYPE_JOB], $mapped->field_job_type);
    $this->assertSame(['KEN'], $mapped->field_country);
    $this->assertStringStartsWith('Please follow this link to apply:', $mapped->field_how_to_apply);
    $this->assertGreaterThanOrEqual(100, strlen($mapped->field_how_to_apply));
    $this->assertSame([WorkDayJobDocumentMapper::EXPERIENCE_5_9], $mapped->field_job_experience);
  }

  /**
   * Tests intern and consultancy experience fallbacks.
   */
  public function testExperienceFallbacks(): void {
    $intern = $this->mapper->mapApiJob($this->findFixtureJob('Internship: Data and Knowledge Management Analyst'));
    $this->assertSame([WorkDayJobDocumentMapper::EXPERIENCE_0_2], $intern->field_job_experience);
    $this->assertContains('Experience set to 0-2 years (internship job type).', $intern->import_notes);

    $consultancy = $this->mapper->mapApiJob($this->findFixtureJob('Gender Equity Applied Learning & Reflective Practice (GECC) Consultant'));
    $this->assertSame([WorkDayJobDocumentMapper::EXPERIENCE_5_9], $consultancy->field_job_experience);
    $this->assertContains('Experience set to 5-9 years (consultancy default).', $consultancy->import_notes);

    $senior_job = $this->mapper->inferJobExperience(
      'Senior Development Officer, Individual Giving',
      'No years listed here.',
      WorkDayJobDocumentMapper::JOB_TYPE_JOB,
    );
    $this->assertSame(WorkDayJobDocumentMapper::EXPERIENCE_5_9, $senior_job[0]);
    $this->assertSame('Experience set to 5-9 years (job title fallback).', $senior_job[1]);

    $regular_job = $this->mapper->inferJobExperience(
      'Spec- Affiliate Support Center',
      'No years listed here.',
      WorkDayJobDocumentMapper::JOB_TYPE_JOB,
    );
    $this->assertSame(WorkDayJobDocumentMapper::EXPERIENCE_3_4, $regular_job[0]);
    $this->assertSame('Experience set to 3-4 years (job title fallback).', $regular_job[1]);
  }

  /**
   * Tests HR Officer does not match the senior title heuristic.
   */
  public function testHrOfficerUsesJuniorFallback(): void {
    $this->assertFalse($this->mapper->titleMatchesSeniorPattern('HR Officer'));
    [$tid, $note] = $this->mapper->inferJobExperience(
      'HR Officer',
      'No years listed here.',
      WorkDayJobDocumentMapper::JOB_TYPE_JOB,
    );
    $this->assertSame(WorkDayJobDocumentMapper::EXPERIENCE_3_4, $tid);
    $this->assertSame('Experience set to 3-4 years (job title fallback).', $note);
  }

  /**
   * Finds a fixture job by title substring.
   *
   * @param string $title_fragment
   *   Title fragment.
   *
   * @return array<string, mixed>
   *   Fixture job.
   */
  private function findFixtureJob(string $title_fragment): array {
    foreach ($this->fixtureJobs as $job) {
      if (str_contains((string) $job['title'], $title_fragment)) {
        return $job;
      }
    }
    $this->fail('Fixture job not found: ' . $title_fragment);
  }

}
