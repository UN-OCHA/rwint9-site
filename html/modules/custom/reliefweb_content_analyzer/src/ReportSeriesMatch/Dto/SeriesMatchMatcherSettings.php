<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

/**
 * Typed settings for the report series matcher algorithm.
 *
 * Built from the report_series_matching.matcher config sub-array.
 */
final readonly class SeriesMatchMatcherSettings {

  /**
   * Constructs matcher settings.
   *
   * @param int $minimumSeriesReportCount
   *   Minimum reports in a cluster for series confidence.
   * @param int $seriesCandidateDateRangeMonths
   *   Candidate lookback window in months.
   * @param int $seriesCandidateLimit
   *   Maximum series candidates to retrieve.
   * @param int $aiTitleSourceLengthLimit
   *   Maximum source text length for title AI generation.
   * @param int $aiTitleExampleLineCount
   *   Number of example titles for title AI generation.
   * @param string $aiTitleDescriptionTemplate
   *   Structured output title field description template.
   * @param list<int> $patternTokenCounts
   *   Token counts for title pattern generation.
   * @param float $candidateClusteringTaggingWeight
   *   Tagging weight in candidate clustering.
   * @param float $candidateClusteringTitleWeight
   *   Title weight in candidate clustering.
   * @param float $candidateClusteringSimilarityThreshold
   *   Minimum combined similarity for clustering edges.
   * @param float $clusterScoringSizeWeight
   *   Cluster size weight in best-cluster selection.
   * @param float $clusterScoringPatternScoreWeight
   *   Pattern score weight in best-cluster selection.
   * @param float $clusterScoringTaggingConsistencyWeight
   *   Tagging consistency weight in best-cluster selection.
   * @param list<string> $clusterComparisonFieldNames
   *   Fields used for cluster comparison.
   * @param string $recencyFieldName
   *   Field used to determine candidate recency.
   * @param list<string> $reportEntityFieldNamesToCopy
   *   Report fields copied from series candidates.
   */
  public function __construct(
    public int $minimumSeriesReportCount,
    public int $seriesCandidateDateRangeMonths,
    public int $seriesCandidateLimit,
    public int $aiTitleSourceLengthLimit,
    public int $aiTitleExampleLineCount,
    public string $aiTitleDescriptionTemplate,
    public array $patternTokenCounts,
    public float $candidateClusteringTaggingWeight,
    public float $candidateClusteringTitleWeight,
    public float $candidateClusteringSimilarityThreshold,
    public float $clusterScoringSizeWeight,
    public float $clusterScoringPatternScoreWeight,
    public float $clusterScoringTaggingConsistencyWeight,
    public array $clusterComparisonFieldNames,
    public string $recencyFieldName,
    public array $reportEntityFieldNamesToCopy,
  ) {}

  /**
   * Builds settings from the report_series_matching.matcher config array.
   *
   * @param array<string, mixed> $config
   *   Raw matcher config from Drupal config.
   *
   * @throws \InvalidArgumentException
   *   When a required key is missing or has an invalid type.
   */
  public static function fromConfigArray(array $config): self {
    return new self(
      minimumSeriesReportCount: self::requireInt($config, 'minimum_series_report_count'),
      seriesCandidateDateRangeMonths: self::requireInt($config, 'series_candidate_date_range_months'),
      seriesCandidateLimit: self::requireInt($config, 'series_candidate_limit'),
      aiTitleSourceLengthLimit: self::requireInt($config, 'ai_title_source_length_limit'),
      aiTitleExampleLineCount: self::requireInt($config, 'ai_title_example_line_count'),
      aiTitleDescriptionTemplate: self::requireString($config, 'ai_title_description_template'),
      patternTokenCounts: self::requireIntList($config, 'pattern_token_counts'),
      candidateClusteringTaggingWeight: self::requireFloat($config, 'candidate_clustering_tagging_weight'),
      candidateClusteringTitleWeight: self::requireFloat($config, 'candidate_clustering_title_weight'),
      candidateClusteringSimilarityThreshold: self::requireFloat($config, 'candidate_clustering_similarity_threshold'),
      clusterScoringSizeWeight: self::requireFloat($config, 'cluster_scoring_size_weight'),
      clusterScoringPatternScoreWeight: self::requireFloat($config, 'cluster_scoring_pattern_score_weight'),
      clusterScoringTaggingConsistencyWeight: self::requireFloat($config, 'cluster_scoring_tagging_consistency_weight'),
      clusterComparisonFieldNames: self::requireStringList($config, 'cluster_comparison_field_names'),
      recencyFieldName: self::requireString($config, 'recency_field_name'),
      reportEntityFieldNamesToCopy: self::requireStringList($config, 'report_entity_field_names_to_copy'),
    );
  }

  /**
   * Reads a required integer value from matcher config.
   *
   * @param array<string, mixed> $config
   *   Raw matcher config.
   * @param string $key
   *   Config key.
   */
  private static function requireInt(array $config, string $key): int {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Matcher config missing required key: {$key}.");
    }
    if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
      throw new \InvalidArgumentException("Matcher config key {$key} must be numeric.");
    }
    return (int) $config[$key];
  }

  /**
   * Reads a required float value from matcher config.
   *
   * @param array<string, mixed> $config
   *   Raw matcher config.
   * @param string $key
   *   Config key.
   */
  private static function requireFloat(array $config, string $key): float {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Matcher config missing required key: {$key}.");
    }
    if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
      throw new \InvalidArgumentException("Matcher config key {$key} must be numeric.");
    }
    return (float) $config[$key];
  }

  /**
   * Reads a required string value from matcher config.
   *
   * @param array<string, mixed> $config
   *   Raw matcher config.
   * @param string $key
   *   Config key.
   */
  private static function requireString(array $config, string $key): string {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Matcher config missing required key: {$key}.");
    }
    if (!is_string($config[$key])) {
      throw new \InvalidArgumentException("Matcher config key {$key} must be a string.");
    }
    return $config[$key];
  }

  /**
   * Reads a required list of integers from matcher config.
   *
   * @param array<string, mixed> $config
   *   Raw matcher config.
   * @param string $key
   *   Config key.
   *
   * @return list<int>
   *   Integer list values.
   */
  private static function requireIntList(array $config, string $key): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Matcher config missing required key: {$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Matcher config key {$key} must be an array.");
    }
    return array_map('intval', array_values($config[$key]));
  }

  /**
   * Reads a required list of non-empty strings from matcher config.
   *
   * @param array<string, mixed> $config
   *   Raw matcher config.
   * @param string $key
   *   Config key.
   *
   * @return list<string>
   *   String list values.
   */
  private static function requireStringList(array $config, string $key): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Matcher config missing required key: {$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Matcher config key {$key} must be an array.");
    }
    $values = [];
    foreach (array_values($config[$key]) as $value) {
      if (!is_string($value) && !is_int($value)) {
        throw new \InvalidArgumentException("Matcher config key {$key} must contain string values.");
      }
      $string = (string) $value;
      if ($string !== '') {
        $values[] = $string;
      }
    }
    return $values;
  }

}
