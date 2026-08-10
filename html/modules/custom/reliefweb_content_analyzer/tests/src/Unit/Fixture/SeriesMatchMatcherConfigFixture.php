<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture;

/**
 * Default report_series_matching.matcher config for unit tests.
 */
final class SeriesMatchMatcherConfigFixture {

  /**
   * Default matcher config array matching install defaults.
   *
   * @return array<string, mixed>
   *   Matcher config for SeriesMatchMatcherSettings::fromConfigArray().
   */
  public static function defaults(): array {
    return [
      'minimum_series_report_count' => 3,
      'series_candidate_date_range_months' => 18,
      'series_candidate_limit' => 30,
      'ai_title_generation_enabled' => TRUE,
      'ai_title_source_length_limit' => 2000,
      'ai_title_example_line_count' => 5,
      'ai_title_min_consistent_examples' => 3,
      'ai_title_extract_page_count' => 2,
      'ai_title_match_min_confidence' => 0.65,
      'title_pattern_similarity_threshold' => 0.90,
      'ai_title_description_template' => "Generate a title based on the provided raw title candidates using the same pattern as the following examples:\n@examples\nDo not invent dates, issue numbers, or week numbers.",
      'ai_title_inference' => [
        'plugin_id' => 'aws_bedrock_nova_lite_v1',
        'temperature' => 0.0,
        'top_p' => 0.9,
        'max_tokens' => 512,
        'thinking_mode' => 'none',
        'system_prompt' => 'Generate a humanitarian report title using the `structured_output` tool.',
      ],
      'pattern_token_counts' => [10, 8, 6, 4],
      'candidate_clustering_tagging_weight' => 0.5,
      'candidate_clustering_title_weight' => 0.5,
      'candidate_clustering_similarity_threshold' => 0.6,
      'cluster_scoring_size_weight' => 0.3333333333,
      'cluster_scoring_pattern_score_weight' => 0.3333333333,
      'cluster_scoring_tagging_consistency_weight' => 0.3333333333,
      'cluster_comparison_field_names' => [
        'field_primary_country',
        'field_content_format',
        'field_language',
      ],
      'recency_field_name' => 'field_original_publication_date',
      'report_entity_field_names_to_copy' => [
        'field_primary_country',
        'field_country',
        'field_language',
        'field_content_format',
        'field_theme',
        'field_disaster',
        'field_disaster_type',
      ],
    ];
  }

}
