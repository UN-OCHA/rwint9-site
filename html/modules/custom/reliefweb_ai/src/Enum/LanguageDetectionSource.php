<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Enum;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * How report language was resolved in the detection cascade.
 */
enum LanguageDetectionSource: string {

  case Forced = 'forced';
  case Detected = 'detected';
  case DetectedSoft = 'detected_soft';
  case DetectedScore = 'detected_score';
  case DetectedTitle = 'detected_title';
  case DefaultUnmapped = 'default_unmapped';
  case DefaultNone = 'default_none';

  /**
   * Build a short note for the simulation comparison UI.
   *
   * @param \Drupal\reliefweb_ai\Enum\LanguageDetectionSample|null $sample
   *   Sample stage that produced the language, when applicable.
   * @param string|null $detected_code
   *   Raw ELD code before taxonomy mapping.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Inline note shown next to the simulated language value.
   */
  public function simulationNote(
    ?LanguageDetectionSample $sample = NULL,
    ?string $detected_code = NULL,
  ): TranslatableMarkup {
    $sample_label = $sample?->value ?: 'text';
    return match ($this) {
      self::Forced => new TranslatableMarkup('(override)'),
      self::Detected => new TranslatableMarkup(
        '(detected: @sample)',
        ['@sample' => $sample_label],
      ),
      self::DetectedSoft => new TranslatableMarkup(
        '(soft: @sample)',
        ['@sample' => $sample_label],
      ),
      self::DetectedScore => new TranslatableMarkup(
        '(score: @sample)',
        ['@sample' => $sample_label],
      ),
      self::DetectedTitle => new TranslatableMarkup('(inferred: title)'),
      self::DefaultUnmapped => new TranslatableMarkup(
        '(defaulted: Other; @name from @sample)',
        [
          '@name' => self::displayLanguageName($detected_code),
          '@sample' => $sample_label,
        ],
      ),
      self::DefaultNone => new TranslatableMarkup('(defaulted: Other)'),
    };
  }

  /**
   * Build a short editorial revision-log clause for this resolution.
   *
   * Soft and score detections use the same wording as strict detection. Title
   * paths use "inferred". Unmapped detections include a display name for the
   * raw ELD code.
   *
   * @param \Drupal\reliefweb_ai\Enum\LanguageDetectionSample|null $sample
   *   Sample stage that produced the language, when applicable.
   * @param string|null $detected_code
   *   Raw ELD code before taxonomy mapping.
   *
   * @return string
   *   Plain English revision-log clause ending with a period.
   */
  public function revisionLogMessage(
    ?LanguageDetectionSample $sample = NULL,
    ?string $detected_code = NULL,
  ): string {
    if ($this === self::Forced) {
      return 'Language set by override.';
    }

    if ($this === self::DefaultNone) {
      return 'Language defaulted to Other.';
    }

    if ($this === self::DefaultUnmapped) {
      $from = $this->revisionLogFromPhrase($sample, inferred: $sample === LanguageDetectionSample::Title);
      $name = self::displayLanguageName($detected_code);
      return 'Language ' . $from . ' (' . $name . '); defaulted to Other.';
    }

    if (
      $this === self::DetectedTitle
      || $sample === LanguageDetectionSample::Title
    ) {
      return 'Language inferred from title.';
    }

    return 'Language ' . $this->revisionLogFromPhrase($sample, inferred: FALSE) . '.';
  }

  /**
   * Build the "detected/inferred from …" fragment for a sample stage.
   *
   * @param \Drupal\reliefweb_ai\Enum\LanguageDetectionSample|null $sample
   *   Sample stage, when known.
   * @param bool $inferred
   *   TRUE to use "inferred from" (title); FALSE for "detected from".
   *
   * @return string
   *   Phrase without trailing period, e.g. "detected from attachment".
   */
  private function revisionLogFromPhrase(
    ?LanguageDetectionSample $sample,
    bool $inferred,
  ): string {
    $verb = $inferred ? 'inferred from' : 'detected from';
    $stage = match ($sample) {
      LanguageDetectionSample::Attachment => 'attachment',
      LanguageDetectionSample::Body => 'body',
      LanguageDetectionSample::Title => 'title',
      default => 'text',
    };
    return $verb . ' ' . $stage;
  }

  /**
   * Resolve an ISO language code to an English display name.
   *
   * @param string|null $code
   *   ISO 639-1 (or similar) language code.
   *
   * @return string
   *   English language name, or the raw code when no name is available.
   */
  private static function displayLanguageName(?string $code): string {
    $code = trim((string) $code);
    if ($code === '') {
      return '?';
    }

    if (class_exists(\Locale::class)) {
      $name = \Locale::getDisplayLanguage($code, 'en');
      if (is_string($name) && $name !== '' && strcasecmp($name, $code) !== 0) {
        return $name;
      }
    }

    return $code;
  }

}
