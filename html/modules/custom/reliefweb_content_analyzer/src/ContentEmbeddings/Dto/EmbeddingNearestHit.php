<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto;

/**
 * One nearest-neighbor hit from embedding storage.
 */
final class EmbeddingNearestHit {

  /**
   * Constructs EmbeddingNearestHit.
   *
   * @param int $entityId
   *   Matched entity ID.
   * @param float $similarity
   *   Cosine similarity in [0, 1] (higher is closer).
   */
  public function __construct(
    public readonly int $entityId,
    public readonly float $similarity,
  ) {}

}
