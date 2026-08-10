<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportDuplicateMatch;

use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate;

/**
 * Aggregate result of report near-duplicate detection for one report.
 */
final class DuplicateMatchResult {

  /**
   * Constructs a DuplicateMatchResult.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch[] $matches
   *   Matches at or above a configured threshold, hard matches first then by
   *   score descending.
   * @param string $reason
   *   Machine-readable stop/skip reason when empty, or 'matched'.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate[] $candidates
   *   All scored candidates (including non-duplicates), for inspection UIs.
   */
  public function __construct(
    public readonly array $matches = [],
    public readonly string $reason = 'none',
    public readonly array $candidates = [],
  ) {}

  /**
   * Whether any near-duplicates were found.
   *
   * @return bool
   *   TRUE when matches is non-empty.
   */
  public function hasMatches(): bool {
    return $this->matches !== [];
  }

  /**
   * Whether any scored candidates were loaded.
   *
   * @return bool
   *   TRUE when candidates is non-empty.
   */
  public function hasCandidates(): bool {
    return $this->candidates !== [];
  }

  /**
   * Whether any hard (Jaccard) matches were found.
   *
   * @return bool
   *   TRUE when at least one Jaccard match is present.
   */
  public function hasHardMatches(): bool {
    foreach ($this->matches as $match) {
      if ($match instanceof DuplicateMatch && $match->isHardMatch()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Scoring method that should drive status application.
   *
   * Hard Jaccard wins over soft embedding confirmation when both are present.
   *
   * @return string|null
   *   DuplicateMatch::METHOD_JACCARD, METHOD_EMBEDDING, or NULL when no
   *   matches are present.
   */
  public function targetMethod(): ?string {
    if (!$this->hasMatches()) {
      return NULL;
    }
    return $this->hasHardMatches()
      ? DuplicateMatch::METHOD_JACCARD
      : DuplicateMatch::METHOD_EMBEDDING;
  }

  /**
   * Count of candidates flagged as duplicates.
   *
   * @return int
   *   Duplicate candidate count.
   */
  public function duplicateCandidateCount(): int {
    $count = 0;
    foreach ($this->candidates as $candidate) {
      if ($candidate instanceof DuplicateMatchCandidate && $candidate->isDuplicate) {
        $count++;
      }
    }
    return $count;
  }

}
