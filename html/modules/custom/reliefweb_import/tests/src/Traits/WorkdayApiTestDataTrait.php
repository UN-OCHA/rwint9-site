<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Traits;

/**
 * Synthetic Workday API job items for unit tests.
 */
trait WorkdayApiTestDataTrait {

  /**
   * Returns a minimal Workday API job item with overridable fields.
   *
   * @param array<string, mixed> $overrides
   *   Field overrides merged recursively into the base item.
   *
   * @return array<string, mixed>
   *   Workday API job item.
   */
  protected function workdayApiJobItem(array $overrides = []): array {
    $base = [
      'id' => 'test-job-001',
      'title' => 'Example Specialist',
      'url' => 'https://example.test/jobs/example-specialist-long-application-path-for-testing',
      'endDate' => '2026-12-31',
      'jobType' => [
        'descriptor' => 'Regular',
      ],
      'primaryLocation' => [
        'descriptor' => 'Example City',
        'country' => [
          'descriptor' => 'Example Country',
          'alpha3Code' => 'KEN',
        ],
      ],
      'jobDescription' => '<p>Example job description for testing.</p>',
    ];

    return array_replace_recursive($base, $overrides);
  }

  /**
   * Regular job with 5+ years experience in the description.
   *
   * @return array<string, mixed>
   *   Workday API job item.
   */
  protected function regularJobWithFivePlusYearsApiItem(): array {
    return $this->workdayApiJobItem([
      'title' => 'Example Specialist',
      'jobType' => ['descriptor' => 'Regular Fixed Term (Fixed Term)'],
      'jobDescription' => '<p>Required experience: 5+ years of experience in project planning.</p>',
    ]);
  }

  /**
   * Internship job with no explicit years requirement.
   *
   * @return array<string, mixed>
   *   Workday API job item.
   */
  protected function internJobApiItem(): array {
    return $this->workdayApiJobItem([
      'id' => 'test-intern-001',
      'title' => 'Example Internship',
      'url' => 'https://example.test/jobs/example-internship',
      'jobType' => ['descriptor' => 'Temporary or Intern (Paid) (Fixed Term)'],
      'jobDescription' => '<p>Entry-level internship for students.</p>',
    ]);
  }

  /**
   * Consultancy job with qualitative experience only (no years).
   *
   * @return array<string, mixed>
   *   Workday API job item.
   */
  protected function consultancyJobWithoutYearsApiItem(): array {
    return $this->workdayApiJobItem([
      'id' => 'test-consultant-001',
      'title' => 'Example Consultant',
      'url' => 'https://example.test/jobs/example-consultant',
      'jobType' => ['descriptor' => 'Contractor / Consultant'],
      'jobDescription' => '<p>Experience in project implementation and field coordination.</p>',
    ]);
  }

}
