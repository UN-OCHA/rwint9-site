<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Dto;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSample;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSource;

/**
 * Outcome of resolving a report's language field.
 */
final readonly class ReportLanguageDetectionResult {

  /**
   * Constructs a ReportLanguageDetectionResult.
   *
   * @param int|string|null $termId
   *   Language taxonomy term ID to apply, or NULL when none.
   * @param string|null $code
   *   Mapped taxonomy language code (e.g. ot when defaulted/unmapped).
   * @param \Drupal\reliefweb_ai\Enum\LanguageDetectionSource $source
   *   How the language was resolved.
   * @param string|null $detectedCode
   *   Raw ELD code before mapping; NULL on forced or empty default.
   * @param \Drupal\reliefweb_ai\Enum\LanguageDetectionSample $sampleSource
   *   Sample stage that produced the detection.
   */
  public function __construct(
    public int|string|null $termId,
    public ?string $code,
    public LanguageDetectionSource $source,
    public ?string $detectedCode,
    public LanguageDetectionSample $sampleSource,
  ) {}

  /**
   * Build a short note for the simulation comparison UI.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Inline note shown next to the simulated language value.
   */
  public function formatSimulationNote(): TranslatableMarkup {
    return $this->source->simulationNote(
      $this->sampleSource === LanguageDetectionSample::None
        ? NULL
        : $this->sampleSource,
      $this->detectedCode,
    );
  }

}
