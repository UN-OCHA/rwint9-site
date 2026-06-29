<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;

/**
 * Resolved tier outcome and target moderation status for a series match run.
 *
 * Built by resolving series and tagging confidence scores against configured
 * thresholds. Immutable once constructed; use the static factory methods.
 */
final readonly class SeriesMatchOutcome {

  /**
   * Constructs a series match outcome.
   *
   * @param float $seriesConfidence
   *   Raw series identification confidence (0–1).
   * @param float $taggingConfidence
   *   Raw tagging proposal confidence (0–1).
   * @param string $seriesTier
   *   Series confidence tier: 'high', 'medium', or 'low'.
   * @param string $taggingTier
   *   Tagging confidence tier: 'high', 'medium', or 'low'.
   * @param string $outcomeTier
   *   Combined outcome tier: min(seriesTier, taggingTier).
   * @param string $targetModerationStatus
   *   Moderation state mapped from the outcome tier via config.
   */
  public function __construct(
    public float $seriesConfidence,
    public float $taggingConfidence,
    public string $seriesTier,
    public string $taggingTier,
    public string $outcomeTier,
    public string $targetModerationStatus,
  ) {}

  /**
   * Resolves a series match result to an outcome using workflow settings.
   *
   * Returns NULL when either confidence score is not computable, which signals
   * the caller to skip moderation adjustment.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The series match result from the matcher.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings $settings
   *   Report series matching workflow settings.
   *
   * @return self|null
   *   Resolved outcome, or NULL when the result is not scorable.
   */
  public static function resolve(SeriesMatchResult $result, SeriesMatchWorkflowSettings $settings): ?self {
    $series_confidence = $result->calculateSeriesConfidence();
    $tagging_confidence = $result->calculateTaggingConfidence();

    if ($series_confidence === NULL || $tagging_confidence === NULL) {
      return NULL;
    }

    $series_tier = self::scoreToTier($series_confidence, $settings->seriesConfidenceTiers);
    $tagging_tier = self::scoreToTier($tagging_confidence, $settings->taggingConfidenceTiers);

    if ($tagging_confidence < $settings->minimumTaggingConfidence) {
      $tagging_tier = 'low';
    }

    $outcome_tier = self::minTier($series_tier, $tagging_tier);
    $target_moderation = $settings->moderationByOutcomeTier[$outcome_tier];

    return new self(
      seriesConfidence: $series_confidence,
      taggingConfidence: $tagging_confidence,
      seriesTier: $series_tier,
      taggingTier: $tagging_tier,
      outcomeTier: $outcome_tier,
      targetModerationStatus: $target_moderation,
    );
  }

  /**
   * Returns the more restrictive of two moderation status machine names.
   *
   * Compares positions in the restrictiveness order (lower index = more
   * restrictive). When a status is not found in the order, it is treated as
   * the least restrictive (sorted after all known values).
   *
   * @param string $current
   *   The current moderation status on the entity.
   * @param string $proposed
   *   The target status proposed by series-match outcome.
   * @param string[] $restrictivenessOrder
   *   Ordered list of status machine names, most restrictive first.
   *
   * @return string
   *   The more restrictive status.
   */
  public static function moreRestrictiveStatus(
    string $current,
    string $proposed,
    array $restrictivenessOrder,
  ): string {
    $order = array_flip($restrictivenessOrder);
    $last = count($restrictivenessOrder);
    $current_pos = $order[$current] ?? $last;
    $proposed_pos = $order[$proposed] ?? $last;

    return $current_pos <= $proposed_pos ? $current : $proposed;
  }

  /**
   * Maps a confidence score to a tier label using threshold config.
   *
   * @param float $score
   *   Confidence score in the range 0–1.
   * @param array{high: float, medium: float} $tiers
   *   Tier thresholds keyed by tier name.
   *
   * @return string
   *   'high', 'medium', or 'low'.
   */
  private static function scoreToTier(float $score, array $tiers): string {
    $high = $tiers['high'];
    $medium = $tiers['medium'];

    if ($score >= $high) {
      return 'high';
    }
    if ($score >= $medium) {
      return 'medium';
    }
    return 'low';
  }

  /**
   * Returns the lower (more restrictive) of two tier labels.
   *
   * Order: low < medium < high.
   *
   * @param string $tier_a
   *   First tier label.
   * @param string $tier_b
   *   Second tier label.
   *
   * @return string
   *   The more restrictive tier.
   */
  private static function minTier(string $tier_a, string $tier_b): string {
    $order = ['low' => 0, 'medium' => 1, 'high' => 2];
    $pos_a = $order[$tier_a] ?? 0;
    $pos_b = $order[$tier_b] ?? 0;

    return $pos_a <= $pos_b ? $tier_a : $tier_b;
  }

}
