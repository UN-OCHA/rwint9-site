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
  case SkippedInconsistentExamples = 'skipped_inconsistent_examples';
  case SkippedLowTitleMatchConfidence = 'skipped_low_title_match_confidence';
  case SkippedInsufficientTitleMarkers = 'skipped_insufficient_title_markers';
  case FailedNoCandidateTitles = 'failed_no_candidate_titles';
  case FailedUnsupportedAiPlugin = 'failed_unsupported_ai_plugin';
  case FailedAiCallError = 'failed_ai_call_error';
  case FailedEmptyAiOutput = 'failed_empty_ai_output';
  case FailedUngroundedTitleMarkers = 'failed_ungrounded_title_markers';
  case FailedSeriesPatternMismatch = 'failed_series_pattern_mismatch';

  /**
   * Returns the reason phrase for unchanged-title outcomes.
   *
   * Used next to the Title field ("Original title kept (...)") and revision
   * log clauses — describes what happened to the title, not why outcome was
   * reduced.
   *
   * @return string|null
   *   Reason text for parenthetical display, or NULL when AI generated a title.
   */
  public function unchangedReason(): ?string {
    return match ($this) {
      self::KeptOriginalPatternMatch => 'matches series pattern',
      self::SkippedAiDisabled => 'AI disabled',
      self::SkippedNoAttachmentText => 'no attachment text',
      self::SkippedInconsistentExamples => 'inconsistent series title examples',
      self::SkippedLowTitleMatchConfidence => 'low title match confidence',
      self::SkippedInsufficientTitleMarkers => 'insufficient title markers',
      self::FailedNoCandidateTitles => 'no candidate titles',
      self::FailedUnsupportedAiPlugin => 'unsupported AI plugin',
      self::FailedAiCallError => 'AI call error',
      self::FailedEmptyAiOutput => 'empty AI output',
      self::FailedUngroundedTitleMarkers => 'ungrounded date or series marker',
      self::FailedSeriesPatternMismatch => 'does not match series title pattern',
      self::AiGenerated => NULL,
    };
  }

  /**
   * Returns a problem-oriented phrase for outcome-policy demotion reasons.
   *
   * Used under "Outcome reduced because:" — frames why generation failed or
   * was skipped, not that the original title was kept.
   *
   * @return string|null
   *   Editor-facing problem phrase, or NULL when this source does not demote
   *   for title AI failure/skip.
   */
  public function outcomePolicyReason(): ?string {
    return match ($this) {
      self::SkippedAiDisabled => 'title AI disabled',
      self::SkippedNoAttachmentText => 'no attachment text for title generation',
      self::SkippedInconsistentExamples => 'inconsistent series title examples',
      self::SkippedLowTitleMatchConfidence => 'title match confidence too low',
      self::SkippedInsufficientTitleMarkers => 'insufficient title markers for generation',
      self::FailedNoCandidateTitles => 'no candidate titles for generation',
      self::FailedUnsupportedAiPlugin => 'unsupported AI plugin for title generation',
      self::FailedAiCallError => 'title generation failed',
      self::FailedEmptyAiOutput => 'title generation returned empty output',
      self::FailedUngroundedTitleMarkers => 'generated title failed validation (ungrounded markers)',
      self::FailedSeriesPatternMismatch => 'generated title does not match series pattern',
      self::KeptOriginalPatternMatch, self::AiGenerated => NULL,
    };
  }

  /**
   * Returns a short revision-log clause describing the title outcome.
   *
   * @return string
   *   Revision-log clause describing the title outcome.
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
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel
   *   The editor attention level.
   */
  public function attentionLevel(): SeriesMatchAttentionLevel {
    return match ($this) {
      self::KeptOriginalPatternMatch => SeriesMatchAttentionLevel::Ok,
      self::AiGenerated => SeriesMatchAttentionLevel::Info,
      self::SkippedAiDisabled, self::SkippedNoAttachmentText,
      self::SkippedInconsistentExamples,
      self::SkippedLowTitleMatchConfidence,
      self::SkippedInsufficientTitleMarkers => SeriesMatchAttentionLevel::Warning,
      self::FailedNoCandidateTitles, self::FailedUnsupportedAiPlugin,
      self::FailedAiCallError, self::FailedEmptyAiOutput,
      self::FailedUngroundedTitleMarkers,
      self::FailedSeriesPatternMismatch => SeriesMatchAttentionLevel::Error,
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
