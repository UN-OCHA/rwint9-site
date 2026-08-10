<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestQuery;

/**
 * Backend-neutral storage for content embeddings.
 */
interface EmbeddingsStorageInterface {

  /**
   * Default embedding dimensions (Model2Vec potion-multilingual-128M).
   */
  public const DEFAULT_DIMENSIONS = 256;

  /**
   * Whether this storage backend is available in the current environment.
   *
   * @return bool
   *   TRUE when the backend can store and retrieve embeddings.
   */
  public function isAvailable(): bool;

  /**
   * Ensure the backend is ready (create table/index if needed).
   *
   * @param int $dimensions
   *   Embedding vector dimensions.
   *
   * @throws \RuntimeException
   *   When the backend is unavailable or setup fails.
   */
  public function ensureReady(int $dimensions = self::DEFAULT_DIMENSIONS): void;

  /**
   * Delete a stored embedding.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param int $entity_id
   *   Entity ID.
   */
  public function delete(string $entity_type_id, int $entity_id): void;

  /**
   * Load stored text hashes for entity IDs.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param int[] $entity_ids
   *   Entity IDs.
   *
   * @return array<int, string>
   *   Hash keyed by entity ID.
   */
  public function loadHashes(string $entity_type_id, array $entity_ids): array;

  /**
   * Entity IDs that already have a stored embedding.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param int[] $entity_ids
   *   Candidate IDs.
   *
   * @return array<int, true>
   *   Existing IDs as keys.
   */
  public function existingIds(string $entity_type_id, array $entity_ids): array;

  /**
   * Load a stored embedding vector.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param int $entity_id
   *   Entity ID.
   *
   * @return float[]|null
   *   Embedding floats, or NULL when missing / storage not ready.
   */
  public function loadVector(string $entity_type_id, int $entity_id): ?array;

  /**
   * Find nearest stored embeddings by cosine similarity.
   *
   * @param \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestQuery $query
   *   Search options.
   *
   * @return list<\Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestHit>
   *   Hits ordered by descending similarity (empty when storage not ready).
   *
   * @throws \InvalidArgumentException
   *   When the query vector is empty or contains non-numeric values.
   */
  public function findNearest(EmbeddingNearestQuery $query): array;

  /**
   * Insert or update an embedding.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param int $entity_id
   *   Entity ID.
   * @param string $bundle
   *   Bundle.
   * @param float[] $vector
   *   Embedding floats.
   * @param string $text_hash
   *   SHA-256 of field profile + text.
   * @param string $language
   *   Language code stored for display.
   * @param int $dimensions
   *   Expected vector length.
   * @param int|null $created
   *   Unix timestamp (last embedded at). Defaults to now.
   *
   * @throws \InvalidArgumentException
   *   When the vector length or values are invalid.
   * @throws \RuntimeException
   *   When the backend is not ready or the write fails.
   */
  public function upsert(
    string $entity_type_id,
    int $entity_id,
    string $bundle,
    array $vector,
    string $text_hash,
    string $language,
    int $dimensions = self::DEFAULT_DIMENSIONS,
    ?int $created = NULL,
  ): void;

}
