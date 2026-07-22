<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture;

/**
 * Default report_series_matching.workflow config for unit tests.
 */
final class SeriesMatchWorkflowConfigFixture {

  /**
   * Default workflow config array matching install defaults.
   *
   * @return array<string, mixed>
   *   Workflow config for SeriesMatchWorkflowSettings::fromConfigArray().
   */
  public static function defaults(): array {
    return [
      'automation_enabled_form_created' => TRUE,
      'automation_enabled_imported' => TRUE,
      'minimum_series_confidence' => 0.65,
      'minimum_tagging_confidence' => 0.40,
      'series_confidence_tiers' => ['high' => 0.80, 'medium' => 0.60],
      'tagging_confidence_tiers' => ['high' => 0.80, 'medium' => 0.60],
      'moderation_by_outcome_tier' => [
        'low' => 'pending',
        'medium' => 'to-review',
        'high' => 'published',
      ],
      'restrictiveness_order' => [
        'refused', 'draft', 'on-hold', 'pending', 'to-review',
        'embargoed', 'reference', 'published',
      ],
      'skip_series_match_moderation_statuses' => ['refused'],
      'field_outcome_policies' => [
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
      ],
      'global_outcome_rules' => [
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
      ],
    ];
  }

}
