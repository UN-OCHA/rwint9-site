<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction;

/**
 * Builds editor-facing messages for outcome policy reasons.
 */
final class SeriesMatchOutcomePolicyReasonFormatter {

  /**
   * Fallback labels when field definitions are unavailable (e.g. unit tests).
   *
   * @var array<string, string>
   */
  private const FIELD_LABELS = [
    'field_primary_country' => 'Primary country',
    'field_country' => 'Country',
    'field_language' => 'Language',
    'field_content_format' => 'Content format',
    'field_theme' => 'Theme',
    'field_disaster' => 'Disaster',
    'field_disaster_type' => 'Disaster type',
    'title' => 'Title',
  ];

  /**
   * Constructs a field provenance policy reason.
   *
   * @param string $field_name
   *   Field machine name.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource $source
   *   Field update provenance.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction $action
   *   Policy action applied for this provenance.
   * @param string|null $field_label
   *   Optional human field label; falls back to a static map or machine name.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason
   *   Reason with code and message.
   */
  public static function forField(
    string $field_name,
    SeriesMatchFieldUpdateSource $source,
    SeriesMatchOutcomePolicyAction $action,
    ?string $field_label = NULL,
  ): SeriesMatchOutcomePolicyReason {
    $label = $field_label ?? self::fieldLabel($field_name);
    $message = match ($source) {
      SeriesMatchFieldUpdateSource::MostRecent => $label . ' from most recent report',
      SeriesMatchFieldUpdateSource::Merged => $label . ' merged from series',
      SeriesMatchFieldUpdateSource::Skipped => $label . ' could not be copied',
      SeriesMatchFieldUpdateSource::AllCandidates => $label . ' from series',
    };

    return new SeriesMatchOutcomePolicyReason(
      code: sprintf('field:%s:%s:%s', $field_name, $source->value, $action->value),
      message: $message,
    );
  }

  /**
   * Constructs a global policy reason.
   *
   * @param string $rule
   *   Global rule machine name.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction $action
   *   Policy action for the rule.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason
   *   Reason with code and message.
   */
  public static function forGlobal(
    string $rule,
    SeriesMatchOutcomePolicyAction $action,
  ): SeriesMatchOutcomePolicyReason {
    $message = match ($rule) {
      'empty_body_when_series_has_body' => 'no body while series usually has body',
      'title_ai_failed_or_skipped' => 'title could not be generated',
      'low_series_confidence_with_mismatch' => 'weak series match with conflicting candidates',
      default => str_replace('_', ' ', $rule),
    };

    return new SeriesMatchOutcomePolicyReason(
      code: 'global:' . $rule . ':' . $action->value,
      message: $message,
    );
  }

  /**
   * Reason when series confidence is below the configured apply threshold.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason
   *   Reason with code and editor-facing message.
   */
  public static function forBelowMinimumSeriesConfidence(): SeriesMatchOutcomePolicyReason {
    return new SeriesMatchOutcomePolicyReason(
      code: 'global:below_minimum_series_confidence:skip_match',
      message: 'Series confidence is below the configured minimum',
    );
  }

  /**
   * Returns messages from a list of policy reasons.
   *
   * @param list<\Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason> $reasons
   *   Policy reasons.
   *
   * @return list<string>
   *   Editor-facing messages.
   */
  public static function messages(array $reasons): array {
    return array_values(array_map(
      static fn(SeriesMatchOutcomePolicyReason $reason): string => $reason->message,
      $reasons,
    ));
  }

  /**
   * Returns machine codes from a list of policy reasons.
   *
   * @param list<\Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason> $reasons
   *   Policy reasons.
   *
   * @return list<string>
   *   Machine-readable codes.
   */
  public static function codes(array $reasons): array {
    return array_values(array_map(
      static fn(SeriesMatchOutcomePolicyReason $reason): string => $reason->code,
      $reasons,
    ));
  }

  /**
   * Resolves a display label for a report field machine name.
   *
   * @param string $field_name
   *   Field machine name.
   *
   * @return string
   *   Display label.
   */
  public static function fieldLabel(string $field_name): string {
    return self::FIELD_LABELS[$field_name] ?? $field_name;
  }

}
