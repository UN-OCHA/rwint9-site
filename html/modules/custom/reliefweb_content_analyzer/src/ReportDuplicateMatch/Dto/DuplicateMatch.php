<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto;

/**
 * One near-duplicate match against an existing report.
 */
final class DuplicateMatch {

  /**
   * Jaccard hard-match method.
   */
  public const METHOD_JACCARD = 'jaccard';

  /**
   * Legacy TF-IDF soft-match method (pre-embedding confirmation).
   */
  public const METHOD_TFIDF = 'tfidf';

  /**
   * Embedding-confirmed soft-match method.
   */
  public const METHOD_EMBEDDING = 'embedding';

  /**
   * Constructs a DuplicateMatch.
   *
   * @param int $nid
   *   Matched report node ID.
   * @param string $title
   *   Matched report title.
   * @param float $score
   *   Similarity score in 0.0–1.0 for the method used.
   * @param string $url
   *   Absolute or relative URL to the matched report.
   * @param string $method
   *   Scoring method: METHOD_JACCARD or METHOD_EMBEDDING.
   */
  public function __construct(
    public readonly int $nid,
    public readonly string $title,
    public readonly float $score,
    public readonly string $url,
    public readonly string $method = self::METHOD_JACCARD,
  ) {}

  /**
   * Similarity as a percentage string (e.g. "95%").
   *
   * @return string
   *   Rounded percentage with % suffix.
   */
  public function similarityPercentage(): string {
    return (string) (int) round($this->score * 100) . '%';
  }

  /**
   * Whether this match came from the hard Jaccard gate.
   *
   * @return bool
   *   TRUE for Jaccard matches.
   */
  public function isHardMatch(): bool {
    return $this->method === self::METHOD_JACCARD;
  }

}
