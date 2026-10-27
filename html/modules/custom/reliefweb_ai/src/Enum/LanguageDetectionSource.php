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
    $sample_label = $sample?->value;
    return match ($this) {
      self::Forced => new TranslatableMarkup('(forced)'),
      self::Detected => new TranslatableMarkup(
        '(detected: @sample)',
        ['@sample' => $sample_label ?: 'text'],
      ),
      self::DetectedSoft => new TranslatableMarkup(
        '(soft: @sample)',
        ['@sample' => $sample_label ?: 'text'],
      ),
      self::DetectedScore => new TranslatableMarkup(
        '(score: @sample)',
        ['@sample' => $sample_label ?: 'text'],
      ),
      self::DetectedTitle => new TranslatableMarkup('(title)'),
      self::DefaultUnmapped => new TranslatableMarkup(
        '(defaulted: other; no term for @detected)',
        ['@detected' => $detected_code ?? '?'],
      ),
      self::DefaultNone => new TranslatableMarkup('(defaulted: other)'),
    };
  }

}
