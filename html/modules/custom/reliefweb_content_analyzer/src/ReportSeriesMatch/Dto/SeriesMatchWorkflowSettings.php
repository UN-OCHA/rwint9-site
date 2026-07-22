<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction;

/**
 * Typed settings for report series matching workflow and outcome resolution.
 *
 * Built from the report_series_matching.workflow config sub-array.
 *
 * @phpstan-type GlobalOutcomeRules array{
 *   empty_body_when_series_has_body: array{
 *     enabled: bool,
 *     series_body_threshold: float,
 *     action: string
 *   },
 *   title_ai_failed_or_skipped: array{
 *     enabled: bool,
 *     action: string
 *   },
 *   low_series_confidence_with_mismatch: array{
 *     enabled: bool,
 *     max_best_cluster_share: float,
 *     min_cluster_count: int,
 *     action: string
 *   }
 * }
 * @phpstan-type FieldOutcomePolicies array<string, array{
 *   most_recent: string,
 *   merged: string,
 *   skipped: string
 * }>
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
   * @param FieldOutcomePolicies $fieldOutcomePolicies
   *   Per-field policy actions keyed by field machine name.
   * @param GlobalOutcomeRules $globalOutcomeRules
   *   Global outcome rule configuration.
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
    public array $fieldOutcomePolicies,
    public array $globalOutcomeRules,
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
      fieldOutcomePolicies: self::requireFieldOutcomePolicies($config, 'field_outcome_policies'),
      globalOutcomeRules: self::requireGlobalOutcomeRules($config, 'global_outcome_rules'),
    );
  }

  /**
   * Default field outcome policies matching install config.
   *
   * @return array<string, array{most_recent: string, merged: string, skipped: string}>
   *   Default per-field policies.
   */
  public static function defaultFieldOutcomePolicies(): array {
    return [
      'field_primary_country' => [
        'most_recent' => 'max_low',
        'merged' => 'max_medium',
        'skipped' => 'max_low',
      ],
      'field_content_format' => [
        'most_recent' => 'max_low',
        'merged' => 'max_low',
        'skipped' => 'max_low',
      ],
      'field_country' => [
        'most_recent' => 'max_medium',
        'merged' => 'max_medium',
        'skipped' => 'none',
      ],
      'field_language' => [
        'most_recent' => 'max_medium',
        'merged' => 'max_medium',
        'skipped' => 'max_medium',
      ],
      'field_theme' => [
        'most_recent' => 'max_medium',
        'merged' => 'none',
        'skipped' => 'none',
      ],
      'field_disaster' => [
        'most_recent' => 'max_medium',
        'merged' => 'max_medium',
        'skipped' => 'none',
      ],
      'field_disaster_type' => [
        'most_recent' => 'max_medium',
        'merged' => 'none',
        'skipped' => 'none',
      ],
    ];
  }

  /**
   * Default global outcome rules matching install config.
   *
   * @return GlobalOutcomeRules
   *   Default global rules.
   */
  public static function defaultGlobalOutcomeRules(): array {
    return [
      'empty_body_when_series_has_body' => [
        'enabled' => TRUE,
        'series_body_threshold' => 0.5,
        'action' => 'max_medium',
      ],
      'title_ai_failed_or_skipped' => [
        'enabled' => TRUE,
        'action' => 'max_medium',
      ],
      'low_series_confidence_with_mismatch' => [
        'enabled' => TRUE,
        'max_best_cluster_share' => 0.5,
        'min_cluster_count' => 2,
        'action' => 'skip_match',
      ],
    ];
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

  /**
   * Reads per-field outcome policies from workflow config.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config.
   * @param string $key
   *   Config key.
   *
   * @return FieldOutcomePolicies
   *   Field policies keyed by field machine name.
   */
  private static function requireFieldOutcomePolicies(array $config, string $key): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$key} must be an array.");
    }

    $policies = [];
    foreach ($config[$key] as $field_name => $policy) {
      if (!is_string($field_name) || $field_name === '' || !is_array($policy)) {
        throw new \InvalidArgumentException("Workflow config key {$key} must map field names to policy maps.");
      }
      foreach (['most_recent', 'merged', 'skipped'] as $provenance) {
        if (!isset($policy[$provenance]) || !is_string($policy[$provenance])) {
          throw new \InvalidArgumentException("Workflow config key {$key}.{$field_name}.{$provenance} must be a string.");
        }
        SeriesMatchOutcomePolicyAction::fromConfig($policy[$provenance]);
      }
      $policies[$field_name] = [
        'most_recent' => $policy['most_recent'],
        'merged' => $policy['merged'],
        'skipped' => $policy['skipped'],
      ];
    }
    return $policies;
  }

  /**
   * Reads global outcome rules from workflow config.
   *
   * @param array<string, mixed> $config
   *   Raw workflow config.
   * @param string $key
   *   Config key.
   *
   * @return GlobalOutcomeRules
   *   Global outcome rules.
   */
  private static function requireGlobalOutcomeRules(array $config, string $key): array {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("Workflow config missing required key: {$key}.");
    }
    if (!is_array($config[$key])) {
      throw new \InvalidArgumentException("Workflow config key {$key} must be an array.");
    }

    $raw = $config[$key];
    foreach ([
      'empty_body_when_series_has_body',
      'title_ai_failed_or_skipped',
      'low_series_confidence_with_mismatch',
    ] as $rule) {
      if (!isset($raw[$rule]) || !is_array($raw[$rule])) {
        throw new \InvalidArgumentException("Workflow config key {$key}.{$rule} must be an array.");
      }
      if (!array_key_exists('enabled', $raw[$rule]) || !is_bool($raw[$rule]['enabled'])) {
        throw new \InvalidArgumentException("Workflow config key {$key}.{$rule}.enabled must be a boolean.");
      }
      if (!isset($raw[$rule]['action']) || !is_string($raw[$rule]['action'])) {
        throw new \InvalidArgumentException("Workflow config key {$key}.{$rule}.action must be a string.");
      }
      SeriesMatchOutcomePolicyAction::fromConfig($raw[$rule]['action']);
    }

    $empty_body = $raw['empty_body_when_series_has_body'];
    if (!isset($empty_body['series_body_threshold'])
      || (!is_int($empty_body['series_body_threshold']) && !is_float($empty_body['series_body_threshold']))) {
      throw new \InvalidArgumentException("Workflow config key {$key}.empty_body_when_series_has_body.series_body_threshold must be numeric.");
    }

    $mismatch = $raw['low_series_confidence_with_mismatch'];
    if (!isset($mismatch['max_best_cluster_share'])
      || (!is_int($mismatch['max_best_cluster_share']) && !is_float($mismatch['max_best_cluster_share']))) {
      throw new \InvalidArgumentException("Workflow config key {$key}.low_series_confidence_with_mismatch.max_best_cluster_share must be numeric.");
    }
    if (!isset($mismatch['min_cluster_count']) || !is_int($mismatch['min_cluster_count'])) {
      throw new \InvalidArgumentException("Workflow config key {$key}.low_series_confidence_with_mismatch.min_cluster_count must be an integer.");
    }

    return [
      'empty_body_when_series_has_body' => [
        'enabled' => $empty_body['enabled'],
        'series_body_threshold' => (float) $empty_body['series_body_threshold'],
        'action' => $empty_body['action'],
      ],
      'title_ai_failed_or_skipped' => [
        'enabled' => $raw['title_ai_failed_or_skipped']['enabled'],
        'action' => $raw['title_ai_failed_or_skipped']['action'],
      ],
      'low_series_confidence_with_mismatch' => [
        'enabled' => $mismatch['enabled'],
        'max_best_cluster_share' => (float) $mismatch['max_best_cluster_share'],
        'min_cluster_count' => $mismatch['min_cluster_count'],
        'action' => $mismatch['action'],
      ],
    ];
  }

}
