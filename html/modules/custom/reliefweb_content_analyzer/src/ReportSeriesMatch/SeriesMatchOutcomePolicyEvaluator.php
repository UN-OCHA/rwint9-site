<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyResult;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;

/**
 * Evaluates configurable field and global outcome policies for a match result.
 */
final class SeriesMatchOutcomePolicyEvaluator {

  /**
   * Evaluates policies and returns the strictest action with reasons.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   Series match result with proposal provenance and evidence.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings $settings
   *   Workflow settings including field and global policies.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier $seriesTier
   *   Series confidence tier already computed from scores.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext $context
   *   Runtime context (body presence for entity and series).
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyResult
   *   Strictest action and reasons.
   */
  public function evaluate(
    SeriesMatchResult $result,
    SeriesMatchWorkflowSettings $settings,
    SeriesMatchOutcomeTier $seriesTier,
    SeriesMatchOutcomePolicyContext $context = new SeriesMatchOutcomePolicyContext(),
  ): SeriesMatchOutcomePolicyResult {
    $action = SeriesMatchOutcomePolicyAction::None;
    $reasons = [];

    foreach ($result->proposal->updatedFieldSources as $field_name => $source) {
      $field_action = $this->actionForFieldProvenance(
        $settings->fieldOutcomePolicies[$field_name] ?? NULL,
        $source,
      );
      if ($field_action === SeriesMatchOutcomePolicyAction::None) {
        continue;
      }
      $action = $action->stricter($field_action);
      $reasons[] = SeriesMatchOutcomePolicyReasonFormatter::forField(
        $field_name,
        $source,
        $field_action,
      );
    }

    $global = $this->evaluateGlobalRules($result, $settings, $seriesTier, $context);
    if ($global->action !== SeriesMatchOutcomePolicyAction::None) {
      $action = $action->stricter($global->action);
      $reasons = [...$reasons, ...$global->reasons];
    }

    return new SeriesMatchOutcomePolicyResult($action, $reasons);
  }

  /**
   * Resolves the policy action for one field provenance source.
   *
   * @param array{most_recent: string, merged: string, skipped: string}|null $policy
   *   Per-field policy map, or NULL when the field has no policy.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource $source
   *   Provenance for the proposed field value.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction
   *   Configured action, or None for AllCandidates / missing policy.
   */
  private function actionForFieldProvenance(
    ?array $policy,
    SeriesMatchFieldUpdateSource $source,
  ): SeriesMatchOutcomePolicyAction {
    if ($policy === NULL || $source === SeriesMatchFieldUpdateSource::AllCandidates) {
      return SeriesMatchOutcomePolicyAction::None;
    }

    $key = match ($source) {
      SeriesMatchFieldUpdateSource::MostRecent => 'most_recent',
      SeriesMatchFieldUpdateSource::Merged => 'merged',
      SeriesMatchFieldUpdateSource::Skipped => 'skipped',
      SeriesMatchFieldUpdateSource::AllCandidates => NULL,
    };
    if ($key === NULL || !isset($policy[$key])) {
      return SeriesMatchOutcomePolicyAction::None;
    }

    return SeriesMatchOutcomePolicyAction::fromConfig($policy[$key]);
  }

  /**
   * Evaluates enabled global outcome rules.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   Series match result with proposal provenance and evidence.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings $settings
   *   Workflow settings including global policies.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier $seriesTier
   *   Series confidence tier already computed from scores.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext $context
   *   Runtime context (body presence for entity and series).
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyResult
   *   Strictest action and reasons.
   *
   * @throws \InvalidArgumentException
   *   When a global policy action is invalid.
   */
  private function evaluateGlobalRules(
    SeriesMatchResult $result,
    SeriesMatchWorkflowSettings $settings,
    SeriesMatchOutcomeTier $seriesTier,
    SeriesMatchOutcomePolicyContext $context,
  ): SeriesMatchOutcomePolicyResult {
    $action = SeriesMatchOutcomePolicyAction::None;
    $reasons = [];
    $rules = $settings->globalOutcomeRules;

    $empty_body = $rules['empty_body_when_series_has_body'];
    if ($empty_body['enabled']
      && !$context->entityHasBody
      && $context->seriesBodyRatio !== NULL
      && $context->seriesBodyRatio >= $empty_body['series_body_threshold']) {
      $rule_action = SeriesMatchOutcomePolicyAction::fromConfig($empty_body['action']);
      $action = $action->stricter($rule_action);
      $reasons[] = SeriesMatchOutcomePolicyReasonFormatter::forGlobal(
        'empty_body_when_series_has_body',
        $rule_action,
      );
    }

    $title_rule = $rules['title_ai_failed_or_skipped'];
    if ($title_rule['enabled'] && $this->isTitleAiFailedOrSkipped($result->proposal->titleSource)) {
      $rule_action = SeriesMatchOutcomePolicyAction::fromConfig($title_rule['action']);
      $action = $action->stricter($rule_action);
      $reasons[] = SeriesMatchOutcomePolicyReasonFormatter::forGlobal(
        'title_ai_failed_or_skipped',
        $rule_action,
      );
    }

    $mismatch = $rules['low_series_confidence_with_mismatch'];
    if ($mismatch['enabled']
      && $seriesTier !== SeriesMatchOutcomeTier::High
      && $result->evidence->clusterCount >= $mismatch['min_cluster_count']
      && $result->evidence->bestClusterShare <= $mismatch['max_best_cluster_share']) {
      $rule_action = SeriesMatchOutcomePolicyAction::fromConfig($mismatch['action']);
      $action = $action->stricter($rule_action);
      $reasons[] = SeriesMatchOutcomePolicyReasonFormatter::forGlobal(
        'low_series_confidence_with_mismatch',
        $rule_action,
      );
    }

    return new SeriesMatchOutcomePolicyResult($action, $reasons);
  }

  /**
   * Checks if title source means AI failed or was skipped.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource|null $title_source
   *   Title source, or NULL when not computed.
   *
   * @return bool
   *   TRUE if title source indicates AI failure or skip, FALSE otherwise.
   */
  private function isTitleAiFailedOrSkipped(?SeriesMatchTitleSource $title_source): bool {
    if ($title_source === NULL) {
      return FALSE;
    }
    return !in_array($title_source, [
      SeriesMatchTitleSource::KeptOriginalPatternMatch,
      SeriesMatchTitleSource::AiGenerated,
    ], TRUE);
  }

}
