<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto;

use Drupal\reliefweb_content_analyzer\Helpers\TextJaccardSimilarity;
use Drupal\reliefweb_content_analyzer\Helpers\TextTfidfSimilarity;

/**
 * One scored candidate from near-duplicate detection (for inspection / apply).
 */
final class DuplicateMatchCandidate {

  /**
   * Candidate came from the created-date / source SQL window.
   */
  public const SOURCE_WINDOW = 'window';

  /**
   * Candidate came from embedding nearest-neighbor search.
   */
  public const SOURCE_EMBEDDING = 'embedding';

  /**
   * Candidate appeared in both window and embedding sets.
   */
  public const SOURCE_BOTH = 'both';

  /**
   * Discarded because title series markers indicate a series sibling.
   */
  public const DISCARD_SERIES_SIBLING = 'series_sibling';

  /**
   * Constructs a DuplicateMatchCandidate.
   *
   * @param int $nid
   *   Candidate report node ID.
   * @param string $title
   *   Candidate title.
   * @param string $url
   *   URL to the candidate report.
   * @param int $created
   *   Candidate created timestamp.
   * @param float|null $lengthRatio
   *   Shorter/longer length ratio, or NULL when not scored.
   * @param float|null $jaccardScore
   *   Word 3-gram Jaccard score, or NULL when not scored.
   * @param float|null $tfidfScore
   *   Pairwise TF-IDF cosine, or NULL when not scored.
   * @param float|null $embeddingScore
   *   Embedding cosine, or NULL when not confirmed.
   * @param bool $isDuplicate
   *   TRUE when production gates treat this as a near-duplicate.
   * @param string|null $method
   *   DuplicateMatch::METHOD_* when isDuplicate, otherwise NULL.
   * @param string|null $skipReason
   *   Machine reason when scoring was skipped (e.g. body_too_short).
   * @param string $candidateSource
   *   SOURCE_* how the candidate entered the set.
   * @param string|null $discardReason
   *   DISCARD_* when a would-be match was filtered out.
   */
  public function __construct(
    public readonly int $nid,
    public readonly string $title,
    public readonly string $url,
    public readonly int $created,
    public readonly ?float $lengthRatio = NULL,
    public readonly ?float $jaccardScore = NULL,
    public readonly ?float $tfidfScore = NULL,
    public readonly ?float $embeddingScore = NULL,
    public readonly bool $isDuplicate = FALSE,
    public readonly ?string $method = NULL,
    public readonly ?string $skipReason = NULL,
    public readonly string $candidateSource = self::SOURCE_WINDOW,
    public readonly ?string $discardReason = NULL,
  ) {}

  /**
   * Score a candidate against a source body using local gate metrics.
   *
   * Always computes length ratio, Jaccard, and TF-IDF when the candidate body
   * meets the minimum length. Only the hard Jaccard gate marks a duplicate
   * here; soft matches require embedding confirmation separately.
   *
   * @param int $nid
   *   Candidate nid.
   * @param string $title
   *   Candidate title.
   * @param string $url
   *   Candidate URL.
   * @param int $created
   *   Candidate created timestamp.
   * @param string $normalized
   *   Normalized source body.
   * @param string $candidateNormalized
   *   Normalized candidate body.
   * @param string $sourceHash
   *   SHA-256 of the source normalized body.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings.
   * @param string[] $language_codes
   *   ISO 639-1 codes for TF-IDF stopword filtering.
   * @param string $candidateSource
   *   SOURCE_* provenance.
   *
   * @return self
   *   Scored candidate row.
   */
  public static function score(
    int $nid,
    string $title,
    string $url,
    int $created,
    string $normalized,
    string $candidateNormalized,
    string $sourceHash,
    DuplicateMatchSettings $settings,
    array $language_codes = [],
    string $candidateSource = self::SOURCE_WINDOW,
  ): self {
    if (mb_strlen($candidateNormalized, 'UTF-8') < $settings->minimumBodyLength) {
      return new self(
        nid: $nid,
        title: $title,
        url: $url,
        created: $created,
        skipReason: 'body_too_short',
        candidateSource: $candidateSource,
      );
    }

    $length_ratio = TextJaccardSimilarity::lengthRatio($normalized, $candidateNormalized);
    if (hash('sha256', $candidateNormalized) === $sourceHash) {
      $jaccard = 1.0;
    }
    else {
      $jaccard = TextJaccardSimilarity::similarity($normalized, $candidateNormalized);
    }
    $tfidf = TextTfidfSimilarity::similarity($normalized, $candidateNormalized, $language_codes);

    $method = NULL;
    if ($length_ratio >= $settings->minimumLengthRatio && $jaccard >= $settings->similarityThreshold) {
      $method = DuplicateMatch::METHOD_JACCARD;
    }

    return new self(
      nid: $nid,
      title: $title,
      url: $url,
      created: $created,
      lengthRatio: $length_ratio,
      jaccardScore: $jaccard,
      tfidfScore: $tfidf,
      isDuplicate: $method !== NULL,
      method: $method,
      candidateSource: $candidateSource,
    );
  }

  /**
   * Whether this candidate passed TF-IDF and needs embedding confirmation.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings.
   *
   * @return bool
   *   TRUE when TF-IDF is at or above threshold and Jaccard did not match.
   */
  public function needsEmbeddingConfirmation(DuplicateMatchSettings $settings): bool {
    if ($this->isDuplicate || $this->skipReason !== NULL || $this->discardReason !== NULL) {
      return FALSE;
    }
    return $this->tfidfScore !== NULL
      && $this->tfidfScore >= $settings->tfidfSimilarityThreshold;
  }

  /**
   * Apply an embedding confirmation score for a soft match.
   *
   * @param float|null $embeddingScore
   *   Embedding cosine, or NULL when confirmation failed / was skipped.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings.
   *
   * @return self
   *   Candidate with embedding score and soft duplicate flag when confirmed.
   */
  public function withEmbeddingConfirmation(?float $embeddingScore, DuplicateMatchSettings $settings): self {
    $method = $this->method;
    $is_duplicate = $this->isDuplicate;
    if (
      !$is_duplicate
      && $this->discardReason === NULL
      && $embeddingScore !== NULL
      && $embeddingScore >= $settings->embeddingSimilarityThreshold
    ) {
      $method = DuplicateMatch::METHOD_EMBEDDING;
      $is_duplicate = TRUE;
    }

    return new self(
      nid: $this->nid,
      title: $this->title,
      url: $this->url,
      created: $this->created,
      lengthRatio: $this->lengthRatio,
      jaccardScore: $this->jaccardScore,
      tfidfScore: $this->tfidfScore,
      embeddingScore: $embeddingScore,
      isDuplicate: $is_duplicate,
      method: $method,
      skipReason: $this->skipReason,
      candidateSource: $this->candidateSource,
      discardReason: $this->discardReason,
    );
  }

  /**
   * Clear duplicate flags and record a series-sibling discard.
   *
   * @return self
   *   Candidate marked discarded for series.
   */
  public function withSeriesSiblingDiscard(): self {
    return new self(
      nid: $this->nid,
      title: $this->title,
      url: $this->url,
      created: $this->created,
      lengthRatio: $this->lengthRatio,
      jaccardScore: $this->jaccardScore,
      tfidfScore: $this->tfidfScore,
      embeddingScore: $this->embeddingScore,
      isDuplicate: FALSE,
      method: NULL,
      skipReason: $this->skipReason,
      candidateSource: $this->candidateSource,
      discardReason: self::DISCARD_SERIES_SIBLING,
    );
  }

  /**
   * Build a DuplicateMatch for apply hooks when this candidate is a duplicate.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch|null
   *   Match DTO, or NULL when not a duplicate.
   */
  public function toMatch(): ?DuplicateMatch {
    if (!$this->isDuplicate || $this->method === NULL || $this->discardReason !== NULL) {
      return NULL;
    }

    $score = match ($this->method) {
      DuplicateMatch::METHOD_JACCARD => (float) $this->jaccardScore,
      DuplicateMatch::METHOD_EMBEDDING => (float) $this->embeddingScore,
      default => (float) ($this->tfidfScore ?? 0.0),
    };

    return new DuplicateMatch(
      nid: $this->nid,
      title: $this->title,
      score: $score,
      url: $this->url,
      method: $this->method,
    );
  }

  /**
   * Format a 0–1 score as a percentage string, or em dash when null.
   *
   * @param float|null $score
   *   Score in 0.0–1.0, or NULL.
   *
   * @return string
   *   Percentage or "—".
   */
  public static function formatScore(?float $score): string {
    if ($score === NULL) {
      return '—';
    }
    return (string) (int) round($score * 100) . '%';
  }

}
