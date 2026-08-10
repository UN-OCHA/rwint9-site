<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto;

/**
 * Options for a generate-embeddings run.
 */
final class EmbeddingGenerateOptions {

  /**
   * Skip entities that already have an embedding row.
   */
  public const SKIP_ID = 'id';

  /**
   * Skip entities whose stored text_hash still matches.
   */
  public const SKIP_HASH = 'hash';

  /**
   * Always re-embed and upsert.
   */
  public const SKIP_NO = 'no';

  /**
   * Sort candidate IDs ascending.
   */
  public const SORT_ASC = 'asc';

  /**
   * Sort candidate IDs descending (most recent first for nodes).
   */
  public const SORT_DESC = 'desc';

  /**
   * Constructs EmbeddingGenerateOptions.
   *
   * @param string $entityTypeId
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string[] $fields
   *   Fields to embed.
   * @param int $minTextLength
   *   Minimum text length.
   * @param int $limit
   *   Max entities (0 = all).
   * @param int $batchSize
   *   HTTP batch size.
   * @param string $sort
   *   Asc|desc.
   * @param int[] $ids
   *   Explicit IDs (empty = scan).
   * @param int|null $minId
   *   Min entity id.
   * @param int|null $maxId
   *   Max entity id.
   * @param string $skipExisting
   *   Id|hash|no.
   * @param string $endpoint
   *   Embed URL.
   * @param float $timeout
   *   HTTP timeout.
   * @param int $dimensions
   *   Vector dimensions.
   * @param bool $dryRun
   *   Prepare only.
   * @param bool $fieldsExplicit
   *   TRUE when CLI passed --fields (allows disabled sources).
   */
  public function __construct(
    public readonly string $entityTypeId,
    public readonly string $bundle,
    public readonly array $fields,
    public readonly int $minTextLength,
    public readonly int $limit,
    public readonly int $batchSize,
    public readonly string $sort,
    public readonly array $ids,
    public readonly ?int $minId,
    public readonly ?int $maxId,
    public readonly string $skipExisting,
    public readonly string $endpoint,
    public readonly float $timeout,
    public readonly int $dimensions,
    public readonly bool $dryRun,
    public readonly bool $fieldsExplicit = FALSE,
  ) {}

}
