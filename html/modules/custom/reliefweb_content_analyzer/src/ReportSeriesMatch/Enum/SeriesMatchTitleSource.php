<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum;

/**
 * How the proposed report title was chosen during series matching.
 */
enum SeriesMatchTitleSource: string {

  case KeptOriginalPatternMatch = 'kept_original_pattern_match';
  case AiGenerated = 'ai_generated';
  case SkippedAiDisabled = 'skipped_ai_disabled';
  case SkippedNoAttachmentText = 'skipped_no_attachment_text';
  case FailedNoCandidateTitles = 'failed_no_candidate_titles';
  case FailedUnsupportedAiPlugin = 'failed_unsupported_ai_plugin';
  case FailedAiCallError = 'failed_ai_call_error';
  case FailedEmptyAiOutput = 'failed_empty_ai_output';

  /**
   * Returns the reason phrase for unchanged-title outcomes.
   *
   * @return string|null
   *   Reason text for parenthetical display, or NULL when AI generated a title.
   */
  public function unchangedReason(): ?string {
    return match ($this) {
      self::KeptOriginalPatternMatch => 'matches series pattern',
      self::SkippedAiDisabled => 'AI disabled',
      self::SkippedNoAttachmentText => 'no attachment text',
      self::FailedNoCandidateTitles => 'no candidate titles',
      self::FailedUnsupportedAiPlugin => 'unsupported AI plugin',
      self::FailedAiCallError => 'AI call error',
      self::FailedEmptyAiOutput => 'empty AI output',
      self::AiGenerated => NULL,
    };
  }

  /**
   * Returns a short revision-log clause describing the title outcome.
   */
  public function revisionLogClause(): string {
    if ($this === self::AiGenerated) {
      return 'AI-generated title';
    }

    $reason = $this->unchangedReason();
    if ($reason === NULL) {
      return 'title unchanged';
    }

    return 'title unchanged (' . $reason . ')';
  }

  /**
   * Returns the editor attention level for this title outcome.
   */
  public function attentionLevel(): SeriesMatchAttentionLevel {
    return match ($this) {
      self::KeptOriginalPatternMatch => SeriesMatchAttentionLevel::Ok,
      self::AiGenerated => SeriesMatchAttentionLevel::Info,
      self::SkippedAiDisabled, self::SkippedNoAttachmentText => SeriesMatchAttentionLevel::Warning,
      self::FailedNoCandidateTitles, self::FailedUnsupportedAiPlugin,
      self::FailedAiCallError, self::FailedEmptyAiOutput => SeriesMatchAttentionLevel::Error,
    };
  }

  /**
   * Resolves a stored title_source value, including legacy enum strings.
   *
   * @param string|null $value
   *   Raw title_source from stored proposal data.
   *
   * @return self|null
   *   Matching enum case, or NULL when unknown.
   */
  public static function tryFromStored(?string $value): ?self {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    $legacy = match ($value) {
      'failed_no_source_text' => self::SkippedNoAttachmentText,
      'ai_disabled' => self::SkippedAiDisabled,
      'failed_ai' => self::FailedAiCallError,
      default => NULL,
    };
    if ($legacy !== NULL) {
      return $legacy;
    }

    return self::tryFrom($value);
  }

}
