<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

/**
 * Typed settings for report series matching workflow and outcome resolution.
 *
 * Built from the report_series_matching.workflow config sub-array.
 */
final readonly class SeriesMatchWorkflowSettings {

  /**
   * Constructs workflow settings.
   *
   * @param bool $automationEnabledFormCreated
   *   Enable automation for editorial form submissions.
   * @param bool $automationEnabledImported
   *   Enable automation for Post API / import submissions.
   * @param float $minimumSeriesConfidence
   *   Minimum series confidence required to apply a match.
   * @param float $minimumTaggingConfidence
   *   Tagging scores below this value are forced to the low tier.
   * @param array{high: float, medium: float} $seriesConfidenceTiers
   *   Series confidence tier thresholds.
   * @param array{high: float, medium: float} $taggingConfidenceTiers
   *   Tagging confidence tier thresholds.
   * @param array{low: string, medium: string, high: string} $moderationByOutcomeTier
   *   Moderation state mapped from each outcome tier.
   * @param list<string> $skipSeriesMatchModerationStatuses
   *   Moderation states for which series matching is skipped.
   * @param list<string> $restrictivenessOrder
   *   Moderation states ordered most restrictive first.
   */
  public function __construct(
    public bool $automationEnabledFormCreated,
    public bool $automationEnabledImported,
    public float $minimumSeriesConfidence,
    public float $minimumTaggingConfidence,
    public array $seriesConfidenceTiers,
    public array $taggingConfidenceTiers,
    public array $moderationByOutcomeTier,
    public array $skipSeriesMatchModerationStatuses,
    public array $restrictivenessOrder,
  ) {}

  /**
   * Builds settings from the report_series_matching.workflow config array.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config from Drupal config.
   *
   * @throws \InvalidArgumentException
   *   When a required key is missing or has an invalid type.
   *
   * @return self
   *   Typed workflow settings instance.
   */
  public static function fromConfigArray(array $config): self {
    return new self(
      automationEnabledFormCreated: self::requireBool($config, 'automation_enabled_form_created'),
      automationEnabledImported: self::requireBool($config, 'automation_enabled_imported'),
      minimumSeriesConfidence: self::requireFloat($config, 'minimum_series_confidence'),
      minimumTaggingConfidence: self::requireFloat($config, 'minimum_tagging_confidence'),
      seriesConfidenceTiers: self::requireFloatTierMap($config, 'series_confidence_tiers'),
      taggingConfidenceTiers: self::requireFloatTierMap($config, 'tagging_confidence_tiers'),
      moderationByOutcomeTier: self::requireModerationTierMap($config, 'moderation_by_outcome_tier'),
      skipSeriesMatchModerationStatuses: self::requireStringList($config, 'skip_series_match_moderation_statuses'),
      restrictivenessOrder: self::requireStringList($config, 'restrictiveness_order'),
    );
  }

  /**
   * Reads a required boolean value from workflow config.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config.
   * @param string $key
   *   Config key.
   *
   * @return bool
   *   Parsed boolean value.
   */
  private static function requireBool(array $config, string $key): bool {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$key}.");
    }
    if (!is_bool($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$key} must be a boolean.");
    }
    return $config[$key];
  }

  /**
   * Reads a required float value from workflow config.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config.
   * @param string $key
   *   Config key.
   *
   * @return float
   *   Parsed float value.
   */
  private static function requireFloat(array $config, string $key): float {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$key}.");
    }
    if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$key} must be numeric.");
    }
    return (float) $config[$key];
  }

  /**
   * Reads a required high/medium float tier map from workflow config.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config.
   * @param string $key
   *   Config key.
   *
   * @return array{high: float, medium: float}
   *   Tier thresholds.
   */
  private static function requireFloatTierMap(array $config, string $key): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$key} must be an array.");
    }
    foreach (['high', 'medium'] as $tier) {
      if (!array_key_exists($tier, $config[$key])) {
        throw new \InvalidArgumentException("Workflow config key {$key}.{$tier} is required.");
      }
    }
    return [
      'high' => (float) $config[$key]['high'],
      'medium' => (float) $config[$key]['medium'],
    ];
  }

  /**
   * Reads a required outcome-tier moderation map from workflow config.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config.
   * @param string $key
   *   Config key.
   *
   * @return array{low: string, medium: string, high: string}
   *   Outcome tier to moderation state map.
   */
  private static function requireModerationTierMap(array $config, string $key): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$key} must be an array.");
    }
    foreach (['low', 'medium', 'high'] as $tier) {
      if (!array_key_exists($tier, $config[$key]) || !is_string($config[$key][$tier])) {
        throw new \InvalidArgumentException("Workflow config key {$key}.{$tier} must be a string.");
      }
    }
    return [
      'low' => $config[$key]['low'],
      'medium' => $config[$key]['medium'],
      'high' => $config[$key]['high'],
    ];
  }

  /**
   * Reads a required list of non-empty strings from workflow config.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config.
   * @param string $key
   *   Config key.
   *
   * @return list<string>
   *   String list values.
   */
  private static function requireStringList(array $config, string $key): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$key} must be an array.");
    }
    $values = [];
    foreach (array_values($config[$key]) as $value) {
      if (!is_string($value) && !is_int($value)) {
        throw new \InvalidArgumentException("Workflow config key {$key} must contain string values.");
      }
      $string = (string) $value;
      if ($string !== '') {
        $values[] = $string;
      }
    }
    return $values;
  }

}
