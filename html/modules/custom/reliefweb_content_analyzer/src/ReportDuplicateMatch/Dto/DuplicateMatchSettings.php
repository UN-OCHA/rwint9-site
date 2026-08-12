<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto;

/**
 * Typed settings for report near-duplicate detection.
 */
final class DuplicateMatchSettings {

  /**
   * Constructs DuplicateMatchSettings.
   *
   * @param bool $automationEnabledFormCreated
   *   Whether automation runs for editorial form creates.
   * @param bool $automationEnabledImported
   *   Whether automation runs for Post API / import creates.
   * @param bool $skipWithAttachments
   *   Whether to skip detection for reports that have file attachments and
   *   exclude attached reports from the candidate set.
   * @param bool $filterBySource
   *   Whether candidates must share at least one field_source with the report.
   * @param int $lookbackDays
   *   Days before the entity created date for candidate search.
   * @param int $lookforwardDays
   *   Days after the entity created date for candidate search.
   * @param int $candidateLimit
   *   Maximum window/source candidates to load.
   * @param int $minimumBodyLength
   *   Minimum normalized body length required for the body-similarity strategy.
   * @param float $minimumLengthRatio
   *   Minimum shorter/longer length ratio before Jaccard scoring.
   * @param float $similarityThreshold
   *   Minimum Jaccard score for a hard near-duplicate.
   * @param float $tfidfSimilarityThreshold
   *   Minimum TF-IDF cosine to candidate for embedding confirmation.
   * @param float $embeddingSimilarityThreshold
   *   Minimum embedding cosine for NN retrieval and soft near-duplicates.
   * @param int $embeddingTopk
   *   Maximum nearest neighbors from embedding storage.
   * @param int $embeddingLookbackDays
   *   Days before the entity created date for embedding NN search.
   * @param string $targetStatus
   *   Moderation status target when any near-duplicate match exists.
   * @param string[] $candidateModerationStatuses
   *   Candidate moderation statuses to include.
   * @param string[] $skipModerationStatuses
   *   Entity moderation statuses that skip detection.
   */
  public function __construct(
    public readonly bool $automationEnabledFormCreated,
    public readonly bool $automationEnabledImported,
    public readonly bool $skipWithAttachments,
    public readonly bool $filterBySource,
    public readonly int $lookbackDays,
    public readonly int $lookforwardDays,
    public readonly int $candidateLimit,
    public readonly int $minimumBodyLength,
    public readonly float $minimumLengthRatio,
    public readonly float $similarityThreshold,
    public readonly float $tfidfSimilarityThreshold,
    public readonly float $embeddingSimilarityThreshold,
    public readonly int $embeddingTopk,
    public readonly int $embeddingLookbackDays,
    public readonly string $targetStatus,
    public readonly array $candidateModerationStatuses,
    public readonly array $skipModerationStatuses,
  ) {}

  /**
   * Build settings from the report_duplicate_matching config mapping.
   *
   * @param array<string, mixed>|null $config
   *   Config array, or NULL for defaults.
   *
   * @return self
   *   Settings instance.
   */
  public static function fromConfigArray(?array $config): self {
    $config ??= [];
    $defaults = self::defaultConfig();
    return new self(
      automationEnabledFormCreated: (bool) ($config['automation_enabled_form_created'] ?? TRUE),
      automationEnabledImported: (bool) ($config['automation_enabled_imported'] ?? TRUE),
      skipWithAttachments: (bool) ($config['skip_with_attachments'] ?? $defaults['skip_with_attachments']),
      filterBySource: (bool) ($config['filter_by_source'] ?? $defaults['filter_by_source']),
      lookbackDays: max(1, (int) ($config['lookback_days'] ?? $defaults['lookback_days'])),
      lookforwardDays: max(0, (int) ($config['lookforward_days'] ?? $defaults['lookforward_days'])),
      candidateLimit: max(1, (int) ($config['candidate_limit'] ?? $defaults['candidate_limit'])),
      minimumBodyLength: max(1, (int) ($config['minimum_body_length'] ?? $defaults['minimum_body_length'])),
      minimumLengthRatio: (float) ($config['minimum_length_ratio'] ?? $defaults['minimum_length_ratio']),
      similarityThreshold: (float) ($config['similarity_threshold'] ?? $defaults['similarity_threshold']),
      tfidfSimilarityThreshold: (float) ($config['tfidf_similarity_threshold'] ?? $defaults['tfidf_similarity_threshold']),
      embeddingSimilarityThreshold: (float) ($config['embedding_similarity_threshold'] ?? $defaults['embedding_similarity_threshold']),
      embeddingTopk: max(1, (int) ($config['embedding_topk'] ?? $defaults['embedding_topk'])),
      embeddingLookbackDays: max(1, (int) ($config['embedding_lookback_days'] ?? $defaults['embedding_lookback_days'])),
      targetStatus: self::nonEmptyString(
        $config['target_status'] ?? NULL,
        (string) $defaults['target_status'],
      ),
      candidateModerationStatuses: array_values(array_filter(
        $config['candidate_moderation_statuses'] ?? $defaults['candidate_moderation_statuses'],
        static fn($status): bool => is_string($status) && $status !== '',
      )),
      skipModerationStatuses: array_values(array_filter(
        $config['skip_moderation_statuses'] ?? $defaults['skip_moderation_statuses'],
        static fn($status): bool => is_string($status) && $status !== '',
      )),
    );
  }

  /**
   * Default config mapping for install / updates.
   *
   * @return array<string, mixed>
   *   Default report_duplicate_matching config.
   */
  public static function defaultConfig(): array {
    return [
      'automation_enabled_form_created' => TRUE,
      'automation_enabled_imported' => TRUE,
      'skip_with_attachments' => FALSE,
      'filter_by_source' => TRUE,
      'lookback_days' => 7,
      'lookforward_days' => 1,
      'candidate_limit' => 50,
      'minimum_body_length' => 200,
      'minimum_length_ratio' => 0.85,
      'similarity_threshold' => 0.92,
      'tfidf_similarity_threshold' => 0.70,
      'embedding_similarity_threshold' => 0.90,
      'embedding_topk' => 50,
      'embedding_lookback_days' => 1095,
      'target_status' => 'duplicate',
      'candidate_moderation_statuses' => [
        'draft',
        'pending',
        'on-hold',
        'to-review',
        'published',
        'refused',
        'duplicate',
        'embargoed',
        'reference',
        'archive',
      ],
      'skip_moderation_statuses' => [
        'refused',
        'duplicate',
      ],
    ];
  }

  /**
   * Resolve a non-empty status string with fallback.
   *
   * @param mixed $value
   *   Config value.
   * @param string $default
   *   Default status.
   *
   * @return string
   *   Non-empty status machine name.
   */
  protected static function nonEmptyString(mixed $value, string $default): string {
    if (is_string($value) && $value !== '') {
      return $value;
    }
    return $default;
  }

}
