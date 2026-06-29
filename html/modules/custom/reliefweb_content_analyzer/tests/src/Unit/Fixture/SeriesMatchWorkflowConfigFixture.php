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
    ];
  }

}
