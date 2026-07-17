<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier;

/**
 * Resolved tier outcome and target moderation status for a series match run.
 *
 * Built by resolving series and tagging confidence scores against configured
 * thresholds, then applying configurable field/global outcome policies.
 * Immutable once constructed; use the static factory methods.
 */
final readonly class SeriesMatchOutcome {

  /**
   * Constructs a series match outcome.
   *
   * @param float $seriesConfidence
   *   Raw series identification confidence (0–1).
   * @param float $taggingConfidence
   *   Raw tagging proposal confidence (0–1).
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier $seriesTier
   *   Series confidence tier.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier $taggingTier
   *   Tagging confidence tier.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier $outcomeTier
   *   Combined outcome tier after policy ceilings.
   * @param string $targetModerationStatus
   *   Moderation state mapped from the outcome tier via config.
   * @param bool $applyMatch
   *   FALSE when a skip_match policy vetoes applying series tagging.
   * @param list<\Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason> $policyReasons
   *   Triggered outcome policies with codes and editor-facing messages.
   */
  public function __construct(
    public float $seriesConfidence,
    public float $taggingConfidence,
    public SeriesMatchOutcomeTier $seriesTier,
    public SeriesMatchOutcomeTier $taggingTier,
    public SeriesMatchOutcomeTier $outcomeTier,
    public string $targetModerationStatus,
    public bool $applyMatch = TRUE,
    public array $policyReasons = [],
  ) {}

  /**
   * Editor-facing policy reason messages.
   *
   * @return list<string>
   *   Human-readable messages.
   */
  public function policyReasonMessages(): array {
    return SeriesMatchOutcomePolicyReasonFormatter::messages($this->policyReasons);
  }

  /**
   * Machine-readable policy reason codes.
   *
   * @return list<string>
   *   Reason codes for storage/debug.
   */
  public function policyReasonCodes(): array {
    return SeriesMatchOutcomePolicyReasonFormatter::codes($this->policyReasons);
  }

  /**
   * Resolves a series match result to an outcome using workflow settings.
   *
   * Returns NULL when either confidence score is not computable, which signals
   * the caller to skip moderation adjustment. When policies resolve to
   * skip_match, returns an outcome with applyMatch FALSE instead of NULL.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The series match result from the matcher.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings $settings
   *   Report series matching workflow settings.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext $context
   *   Runtime context for global rules (body presence).
   *
   * @return self|null
   *   Resolved outcome, or NULL when the result is not scorable.
   */
  public static function resolve(
    SeriesMatchResult $result,
    SeriesMatchWorkflowSettings $settings,
    SeriesMatchOutcomePolicyContext $context = new SeriesMatchOutcomePolicyContext(),
  ): ?self {
    $series_confidence = $result->calculateSeriesConfidence();
    $tagging_confidence = $result->calculateTaggingConfidence();

    if ($series_confidence === NULL || $tagging_confidence === NULL) {
      return NULL;
    }

    $series_tier = SeriesMatchOutcomeTier::fromScore(
      $series_confidence,
      $settings->seriesConfidenceTiers,
    );
    $tagging_tier = SeriesMatchOutcomeTier::fromScore(
      $tagging_confidence,
      $settings->taggingConfidenceTiers,
    );

    if ($tagging_confidence < $settings->minimumTaggingConfidence) {
      $tagging_tier = SeriesMatchOutcomeTier::Low;
    }

    $outcome_tier = $series_tier->min($tagging_tier);

    if ($series_confidence < $settings->minimumSeriesConfidence) {
      return new self(
        seriesConfidence: $series_confidence,
        taggingConfidence: $tagging_confidence,
        seriesTier: $series_tier,
        taggingTier: $tagging_tier,
        outcomeTier: $outcome_tier,
        targetModerationStatus: $settings->moderationByOutcomeTier[$outcome_tier->value],
        applyMatch: FALSE,
        policyReasons: [
          SeriesMatchOutcomePolicyReasonFormatter::forBelowMinimumSeriesConfidence(),
        ],
      );
    }

    $policy = (new SeriesMatchOutcomePolicyEvaluator())->evaluate(
      $result,
      $settings,
      $series_tier,
      $context,
    );

    if ($policy->action === SeriesMatchOutcomePolicyAction::SkipMatch) {
      return new self(
        seriesConfidence: $series_confidence,
        taggingConfidence: $tagging_confidence,
        seriesTier: $series_tier,
        taggingTier: $tagging_tier,
        outcomeTier: $outcome_tier,
        targetModerationStatus: $settings->moderationByOutcomeTier[$outcome_tier->value],
        applyMatch: FALSE,
        policyReasons: $policy->reasons,
      );
    }

    $outcome_tier = $policy->action->applyToTier($outcome_tier);
    $target_moderation = $settings->moderationByOutcomeTier[$outcome_tier->value];

    return new self(
      seriesConfidence: $series_confidence,
      taggingConfidence: $tagging_confidence,
      seriesTier: $series_tier,
      taggingTier: $tagging_tier,
      outcomeTier: $outcome_tier,
      targetModerationStatus: $target_moderation,
      applyMatch: TRUE,
      policyReasons: $policy->reasons,
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

}
