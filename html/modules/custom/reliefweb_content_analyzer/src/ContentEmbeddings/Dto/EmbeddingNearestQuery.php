<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto;

/**
 * Options for a nearest-neighbor embedding search.
 */
final class EmbeddingNearestQuery {

  /**
   * Constructs EmbeddingNearestQuery.
   *
   * @param string $entityTypeId
   *   Entity type ID.
   * @param float[] $query
   *   Query embedding vector.
   * @param int $limit
   *   Maximum hits to return (before optional minSimilarity filtering).
   * @param string|null $bundle
   *   Restrict to this bundle, or NULL for any.
   * @param int|null $excludeEntityId
   *   Entity ID to exclude (typically the probe itself).
   * @param int|null $entityIdMin
   *   Inclusive minimum entity ID filter.
   * @param int|null $entityIdMax
   *   Inclusive maximum entity ID filter.
   * @param float|null $minSimilarity
   *   Drop hits with cosine similarity below this value (post-LIMIT).
   */
  public function __construct(
    public readonly string $entityTypeId,
    public readonly array $query,
    public readonly int $limit,
    public readonly ?string $bundle = NULL,
    public readonly ?int $excludeEntityId = NULL,
    public readonly ?int $entityIdMin = NULL,
    public readonly ?int $entityIdMax = NULL,
    public readonly ?float $minSimilarity = NULL,
  ) {}

}
