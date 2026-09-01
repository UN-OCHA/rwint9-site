<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

/**
 * Maps Workday API job documents to ReliefWeb feed import data.
 */
final class WorkDayJobDocumentMapper {

  /**
   * Job type term: Job.
   */
  public const JOB_TYPE_JOB = 263;

  /**
   * Job type term: Consultancy.
   */
  public const JOB_TYPE_CONSULTANCY = 264;

  /**
   * Job type term: Internship.
   */
  public const JOB_TYPE_INTERNSHIP = 265;

  /**
   * Experience term: 0-2 years.
   */
  public const EXPERIENCE_0_2 = 258;

  /**
   * Experience term: 3-4 years.
   */
  public const EXPERIENCE_3_4 = 259;

  /**
   * Experience term: 5-9 years.
   */
  public const EXPERIENCE_5_9 = 260;

  /**
   * Experience term: 10+ years.
   */
  public const EXPERIENCE_10_PLUS = 261;

  /**
   * Word numbers mapped to integers for experience parsing.
   *
   * @var array<string, int>
   */
  private const WORD_NUMBERS = [
    'one' => 1,
    'two' => 2,
    'three' => 3,
    'four' => 4,
    'five' => 5,
    'six' => 6,
    'seven' => 7,
    'eight' => 8,
    'nine' => 9,
    'ten' => 10,
  ];

  /**
   * Senior title tokens for job experience fallback.
   *
   * @var string[]
   */
  private const SENIOR_TITLE_PATTERNS = [
    'senior',
    'director',
    'lead',
    'chief',
    'head',
    'principal',
    'officer',
  ];

  /**
   * Maps a Workday API job item to ReliefWeb import data.
   *
   * @param array<string, mixed> $job
   *   Workday API job item.
   *
   * @return object
   *   Mapped import data object.
   */
  public function mapApiJob(array $job): object {
    $title = (string) ($job['title'] ?? '');
    $body = (string) ($job['jobDescription'] ?? '');
    $url = (string) ($job['url'] ?? '');
    $job_type = $this->mapJobType((string) ($job['jobType']['descriptor'] ?? ''));

    [$experience_tid, $experience_note] = $this->inferJobExperience(
      $title,
      $body,
      $job_type,
    );

    $country = [];
    if (!empty($job['primaryLocation']['country']['alpha3Code'])) {
      $country[] = (string) $job['primaryLocation']['country']['alpha3Code'];
    }

    $mapped = (object) [
      'title' => $title,
      'body' => $body,
      'field_how_to_apply' => 'Please follow this link to apply: ' . $url,
      'field_job_closing_date' => (string) ($job['endDate'] ?? ''),
      'field_job_type' => [$job_type],
      'field_job_experience' => [$experience_tid],
      'field_country' => $country,
      'url' => $url,
    ];

    if ($experience_note !== NULL) {
      $mapped->import_notes = [$experience_note];
    }

    return $mapped;
  }

  /**
   * Maps a Workday job type descriptor to a ReliefWeb job type term ID.
   *
   * @param string $descriptor
   *   Workday job type descriptor.
   *
   * @return int
   *   Job type term ID.
   */
  public function mapJobType(string $descriptor): int {
    $descriptor = mb_strtolower($descriptor);
    if (str_contains($descriptor, 'intern')) {
      return self::JOB_TYPE_INTERNSHIP;
    }
    if (str_contains($descriptor, 'contractor') || str_contains($descriptor, 'consultant')) {
      return self::JOB_TYPE_CONSULTANCY;
    }
    return self::JOB_TYPE_JOB;
  }

  /**
   * Infers required experience from title, body, and job type.
   *
   * @param string $title
   *   Job title.
   * @param string $bodyHtml
   *   Job description HTML.
   * @param int $jobTypeTid
   *   Mapped job type term ID.
   *
   * @return array{0: int, 1: string|null}
   *   Experience term ID and optional revision-log note.
   */
  public function inferJobExperience(string $title, string $bodyHtml, int $jobTypeTid): array {
    if ($jobTypeTid === self::JOB_TYPE_INTERNSHIP) {
      return [self::EXPERIENCE_0_2, 'Experience set to 0-2 years (internship job type).'];
    }

    $years = $this->parseMinimumYearsFromBody($bodyHtml);
    if ($years !== NULL) {
      return [$this->mapYearsToExperienceTerm($years), NULL];
    }

    if ($jobTypeTid === self::JOB_TYPE_CONSULTANCY) {
      return [self::EXPERIENCE_5_9, 'Experience set to 5-9 years (consultancy default).'];
    }

    if ($this->titleMatchesSeniorPattern($title)) {
      return [self::EXPERIENCE_5_9, 'Experience set to 5-9 years (job title fallback).'];
    }

    return [self::EXPERIENCE_3_4, 'Experience set to 3-4 years (job title fallback).'];
  }

  /**
   * Maps a minimum years value to a job experience term ID.
   *
   * @param int $years
   *   Minimum years of experience.
   *
   * @return int
   *   Experience term ID.
   */
  public function mapYearsToExperienceTerm(int $years): int {
    if ($years <= 2) {
      return self::EXPERIENCE_0_2;
    }
    if ($years <= 4) {
      return self::EXPERIENCE_3_4;
    }
    if ($years <= 9) {
      return self::EXPERIENCE_5_9;
    }
    return self::EXPERIENCE_10_PLUS;
  }

  /**
   * Parses the minimum years of experience from job description text.
   *
   * @param string $bodyHtml
   *   Job description HTML.
   *
   * @return int|null
   *   Minimum years if found.
   */
  public function parseMinimumYearsFromBody(string $bodyHtml): ?int {
    $text = html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(
      ["\u{2019}", "\u{2018}", "\u{2013}", "\u{2014}"],
      ["'", "'", '-', '-'],
      $text,
    );
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    $patterns = [
      '/\b(?:minimum|at least)\s+(?:of\s+)?(?:(one|two|three|four|five|six|seven|eight|nine|ten)\s*\(\s*(\d+)\s*\)|(\d+)\+?)\s*years?\b/i',
      '/\b(one|two|three|four|five|six|seven|eight|nine|ten)\s+years?\b/i',
      '/\b(\d+)\s*[-–]\s*(\d+)\s*years?\b/i',
      '/\b(\d+)\+?\s*years?\s+of\s+(?:relevant\s+)?experience\b/i',
      '/\b(\d+)\+?\s*years?\b/i',
    ];

    $candidates = [];
    foreach ($patterns as $pattern) {
      if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        continue;
      }

      foreach ($matches as $match) {
        $offset = $match[0][1];
        $snippet = mb_substr($text, max(0, $offset - 20), 60);
        if ($this->isExperienceFalsePositive($snippet)) {
          continue;
        }

        $years = $this->extractYearsFromMatch($match);
        if ($years !== NULL) {
          $candidates[] = $years;
        }
      }
    }

    if ($candidates === []) {
      return NULL;
    }

    return min($candidates);
  }

  /**
   * Whether a title matches senior-level fallback patterns.
   *
   * @param string $title
   *   Job title.
   *
   * @return bool
   *   TRUE when the title suggests senior experience.
   */
  public function titleMatchesSeniorPattern(string $title): bool {
    $title = mb_strtolower($title);
    if (preg_match('/\bhr\s+officer\b/i', $title) === 1) {
      return FALSE;
    }

    foreach (self::SENIOR_TITLE_PATTERNS as $pattern) {
      if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/i', $title) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extracts a years value from a regex match array.
   *
   * @param array<int, array{0: string, 1: int}> $match
   *   PCRE match with offsets.
   *
   * @return int|null
   *   Parsed years or NULL.
   */
  private function extractYearsFromMatch(array $match): ?int {
    if (!empty($match[2][0]) && is_numeric($match[2][0])) {
      return (int) $match[2][0];
    }
    if (!empty($match[3][0]) && is_numeric($match[3][0])) {
      return (int) $match[3][0];
    }
    if (!empty($match[1][0])) {
      $token = mb_strtolower($match[1][0]);
      if (isset(self::WORD_NUMBERS[$token])) {
        return self::WORD_NUMBERS[$token];
      }
      if (is_numeric($token)) {
        return (int) $token;
      }
    }

    return NULL;
  }

  /**
   * Whether a matched snippet is a known false positive.
   *
   * @param string $snippet
   *   Text around the match.
   *
   * @return bool
   *   TRUE when the match should be ignored.
   */
  private function isExperienceFalsePositive(string $snippet): bool {
    $snippet = mb_strtolower($snippet);
    if (str_contains($snippet, 'figure')) {
      return TRUE;
    }
    if (str_contains($snippet, 'academic year')) {
      return TRUE;
    }
    if (preg_match('/\d+\s*[-–]\s*\d+\s*months?/i', $snippet) === 1) {
      return TRUE;
    }
    if (preg_match('/\d+\s*months?/i', $snippet) === 1 && str_contains($snippet, 'year')) {
      return TRUE;
    }
    return FALSE;
  }

}
