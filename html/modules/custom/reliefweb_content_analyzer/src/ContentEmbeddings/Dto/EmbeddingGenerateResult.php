<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto;

/**
 * Aggregate counters for a generate-embeddings run.
 */
final class EmbeddingGenerateResult {

  /**
   * Constructs EmbeddingGenerateResult.
   *
   * @param int $candidates
   *   Candidate entity count before skip filters beyond ID resolution.
   * @param int $stored
   *   Rows stored (or that would be stored in dry-run).
   * @param int $skippedId
   *   Skipped because an embedding row already exists (skip=id).
   * @param int $skippedHash
   *   Skipped because text_hash matched (skip=hash).
   * @param int $skippedShort
   *   Skipped because concatenated text was below min length.
   * @param int $skippedEmpty
   *   Skipped because entity/text was missing or empty.
   * @param int $errors
   *   Failed embed or store operations.
   * @param float $prepareMs
   *   Load + text build time in milliseconds.
   * @param float $embedMs
   *   HTTP embed time in milliseconds.
   * @param float $storeMs
   *   Upsert time in milliseconds.
   * @param float $wallMs
   *   Total wall time in milliseconds.
   */
  public function __construct(
    public int $candidates = 0,
    public int $stored = 0,
    public int $skippedId = 0,
    public int $skippedHash = 0,
    public int $skippedShort = 0,
    public int $skippedEmpty = 0,
    public int $errors = 0,
    public float $prepareMs = 0.0,
    public float $embedMs = 0.0,
    public float $storeMs = 0.0,
    public float $wallMs = 0.0,
  ) {}

}
