<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;

/**
 * Typed settings for series and tagging confidence formula weights.
 *
 * Built from the report_series_matching.workflow.confidence_scoring config.
 */
final readonly class SeriesMatchConfidenceScoringSettings {

  /**
   * Constructs confidence scoring settings.
   *
   * @param float $clusterShareWeight
   *   Weight for pattern-score-weighted cluster share.
   * @param float $clusterScoreWeight
   *   Weight for the winning cluster composite score.
   * @param float $dualSignalRatioWeight
   *   Weight for dual title+URL retrieval ratio.
   * @param float $clusterShareDominanceWeight
   *   Additional weight applied to cluster share (dominance bonus).
   * @param float $fieldBlendWeight
   *   Weight for average field provenance score in tagging confidence.
   * @param float $titleBlendWeight
   *   Weight for title source band score in tagging confidence.
   * @param array{all_candidates: float, merged: float, most_recent: float, skipped: float} $fieldProvenanceWeights
   *   Score per field update source type.
   * @param array{kept_original_pattern_match: float, ai_generated: float, other: float} $titleSourceScores
   *   Score per title source outcome.
   */
  public function __construct(
    public float $clusterShareWeight,
    public float $clusterScoreWeight,
    public float $dualSignalRatioWeight,
    public float $clusterShareDominanceWeight,
    public float $fieldBlendWeight,
    public float $titleBlendWeight,
    public array $fieldProvenanceWeights,
    public array $titleSourceScores,
  ) {}

  /**
   * Default confidence scoring config matching install defaults.
   *
   * @return array<string, mixed>
   *   Raw confidence_scoring config array.
   */
  public static function defaultConfig(): array {
    return [
      'series' => [
        'cluster_share_weight' => 0.40,
        'cluster_score_weight' => 0.25,
        'dual_signal_ratio_weight' => 0.20,
        'cluster_share_dominance_weight' => 0.15,
      ],
      'tagging' => [
        'field_blend_weight' => 0.70,
        'title_blend_weight' => 0.30,
        'field_provenance_weights' => [
          'all_candidates' => 1.0,
          'merged' => 0.75,
          'most_recent' => 0.50,
          'skipped' => 0.0,
        ],
        'title_source_scores' => [
          'kept_original_pattern_match' => 1.0,
          'ai_generated' => 0.65,
          'other' => 0.25,
        ],
      ],
    ];
  }

  /**
   * Builds settings from the confidence_scoring config array.
   *
   * @param array<string, mixed> $config
   *   Raw confidence_scoring config from Drupal config.
   *
   * @throws \InvalidArgumentException
   *   When a required key is missing or has an invalid type.
   *
   * @return self
   *   Typed confidence scoring settings instance.
   */
  public static function fromConfigArray(array $config): self {
    if (!isset($config['series']) || !is_array($config['series'])) {
      throw new \InvalidArgumentException('Workflow config key confidence_scoring.series must be an array.');
    }
    if (!isset($config['tagging']) || !is_array($config['tagging'])) {
      throw new \InvalidArgumentException('Workflow config key confidence_scoring.tagging must be an array.');
    }

    $series = $config['series'];
    $tagging = $config['tagging'];

    return new self(
      clusterShareWeight: self::requireFloat($series, 'cluster_share_weight', 'confidence_scoring.series'),
      clusterScoreWeight: self::requireFloat($series, 'cluster_score_weight', 'confidence_scoring.series'),
      dualSignalRatioWeight: self::requireFloat($series, 'dual_signal_ratio_weight', 'confidence_scoring.series'),
      clusterShareDominanceWeight: self::requireFloat($series, 'cluster_share_dominance_weight', 'confidence_scoring.series'),
      fieldBlendWeight: self::requireFloat($tagging, 'field_blend_weight', 'confidence_scoring.tagging'),
      titleBlendWeight: self::requireFloat($tagging, 'title_blend_weight', 'confidence_scoring.tagging'),
      fieldProvenanceWeights: self::requireFieldProvenanceWeights(
        self::requireMapping($tagging, 'field_provenance_weights', 'confidence_scoring.tagging'),
      ),
      titleSourceScores: self::requireTitleSourceScores(
        self::requireMapping($tagging, 'title_source_scores', 'confidence_scoring.tagging'),
      ),
    );
  }

  /**
   * Returns the provenance weight for a field update source.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource $source
   *   Field update source.
   *
   * @return float
   *   Configured weight for the source.
   */
  public function fieldProvenanceWeight(SeriesMatchFieldUpdateSource $source): float {
    $key = match ($source) {
      SeriesMatchFieldUpdateSource::AllCandidates => 'all_candidates',
      SeriesMatchFieldUpdateSource::Merged => 'merged',
      SeriesMatchFieldUpdateSource::MostRecent => 'most_recent',
      SeriesMatchFieldUpdateSource::Skipped => 'skipped',
    };
    return $this->fieldProvenanceWeights[$key];
  }

  /**
   * Returns the title source band score.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource|null $source
   *   Title source outcome.
   *
   * @return float
   *   Configured score for the title source.
   */
  public function titleSourceScore(?SeriesMatchTitleSource $source): float {
    $key = match ($source) {
      SeriesMatchTitleSource::KeptOriginalPatternMatch => 'kept_original_pattern_match',
      SeriesMatchTitleSource::AiGenerated => 'ai_generated',
      default => 'other',
    };
    return $this->titleSourceScores[$key];
  }

  /**
   * Reads a required mapping from config.
   *
   * @param array<string, mixed> $config
   *   Raw config section.
   * @param string $key
   *   Config key.
   * @param string $prefix
   *   Config path prefix for error messages.
   *
   * @return array<string, mixed>
   *   Nested config array.
   */
  private static function requireMapping(array $config, string $key, string $prefix): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$prefix}.{$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$prefix}.{$key} must be an array.");
    }
    return $config[$key];
  }

  /**
   * Reads a required float value from config.
   *
   * @param array<string, mixed> $config
   *   Raw config section.
   * @param string $key
   *   Config key.
   * @param string $prefix
   *   Config path prefix for error messages.
   *
   * @return float
   *   Parsed float value.
   */
  private static function requireFloat(array $config, string $key, string $prefix): float {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$prefix}.{$key}.");
    }
    if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$prefix}.{$key} must be numeric.");
    }
    return (float) $config[$key];
  }

  /**
   * Reads field provenance weights from config.
   *
   * @param array<string, mixed> $config
   *   Raw field_provenance_weights config.
   *
   * @return array{all_candidates: float, merged: float, most_recent: float, skipped: float}
   *   Parsed provenance weights.
   */
  private static function requireFieldProvenanceWeights(array $config): array {
    $weights = [];
    foreach (['all_candidates', 'merged', 'most_recent', 'skipped'] as $key) {
      if (!array_key_exists($key, $config)) {
        throw new \InvalidArgumentException("Workflow config key confidence_scoring.tagging.field_provenance_weights.{$key} is required.");
      }
      if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
        throw new \InvalidArgumentException("Workflow config key confidence_scoring.tagging.field_provenance_weights.{$key} must be numeric.");
      }
      $weights[$key] = (float) $config[$key];
    }
    return $weights;
  }

  /**
   * Reads title source scores from config.
   *
   * @param array<string, mixed> $config
   *   Raw title_source_scores config.
   *
   * @return array{kept_original_pattern_match: float, ai_generated: float, other: float}
   *   Parsed title source scores.
   */
  private static function requireTitleSourceScores(array $config): array {
    $scores = [];
    foreach (['kept_original_pattern_match', 'ai_generated', 'other'] as $key) {
      if (!array_key_exists($key, $config)) {
        throw new \InvalidArgumentException("Workflow config key confidence_scoring.tagging.title_source_scores.{$key} is required.");
      }
      if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
        throw new \InvalidArgumentException("Workflow config key confidence_scoring.tagging.title_source_scores.{$key} must be numeric.");
      }
      $scores[$key] = (float) $config[$key];
    }
    return $scores;
  }

}
