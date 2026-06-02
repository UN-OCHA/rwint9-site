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
      'ai_title_source_length_limit' => 2000,
      'ai_title_example_line_count' => 5,
      'ai_title_description_template' => "Extract the title of the document and rewrite it to match the naming style of these example titles from the same report series:\n@examples",
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
