<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_ai\Dto\ReportLanguageDetectionResult;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSample;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSource;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_utility\Helpers\LanguageDetectionHelper;
use Nitotm\Eld\LanguageResult;

/**
 * Detects and sets report language from document text after classification.
 */
class ReportLanguageDetector {

  /**
   * Optional language detector callable for tests.
   *
   * @var callable(string): string|null
   */
  protected $languageDetector;

  /**
   * Cached language term IDs keyed by lower-case ISO language code.
   *
   * @var array<string, int|string>|null
   */
  protected ?array $languageTermIdsByCode = NULL;

  /**
   * Constructs a ReportLanguageDetector.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param callable|null $language_detector
   *   Optional callable that returns an ISO 639-1 code (or "und") for a text
   *   sample. When set, non-und results are treated as always reliable (tests).
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly Connection $database,
    ?callable $language_detector = NULL,
  ) {
    $this->languageDetector = $language_detector;
  }

  /**
   * Build a short note describing how language was resolved for simulation UI.
   *
   * @param \Drupal\reliefweb_ai\Dto\ReportLanguageDetectionResult $outcome
   *   Language resolution outcome.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Inline note shown next to the simulated language value.
   */
  public static function formatSimulationNote(ReportLanguageDetectionResult $outcome): TranslatableMarkup {
    return $outcome->formatSimulationNote();
  }

  /**
   * Update the language field of an entity based on real document text.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity being classified.
   *
   * @return array|null
   *   An array with the language field if it was updated, or NULL.
   */
  public function updateLanguage(FieldableEntityInterface $entity): ?array {
    if (!$entity->hasField('field_language')) {
      return NULL;
    }

    $outcome = $this->resolveLanguage($entity);
    if ($outcome === NULL || $outcome->termId === NULL) {
      return NULL;
    }

    // Update the entity with the detected language.
    // No strict equality comparison since the ID may be a numeric string or an
    // integer.
    $current_language_term_id = $entity->get('field_language')->target_id ?? NULL;
    if ($outcome->termId != $current_language_term_id) {
      $entity->set('field_language', $outcome->termId);
      return ['field_language'];
    }

    return NULL;
  }

  /**
   * Resolve the language that would be applied to an entity.
   *
   * Cascade: attachment → body → title with strict then soft reliability, then
   * a cross-stage score tie-break, then a title-lenient accept, else ot.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity being classified.
   *
   * @return \Drupal\reliefweb_ai\Dto\ReportLanguageDetectionResult|null
   *   Resolution outcome, or NULL when language cannot be resolved.
   */
  public function resolveLanguage(FieldableEntityInterface $entity): ?ReportLanguageDetectionResult {
    if (!$entity->hasField('field_language')) {
      return NULL;
    }

    // Call the hook so other modules can set the language.
    $language_term_ids = array_values(array_filter(
      $this->moduleHandler->invokeAll('reliefweb_ai_force_language', [$entity]),
    ));
    if ($language_term_ids !== []) {
      // If multiple modules return a language, use the first non-empty one.
      return new ReportLanguageDetectionResult(
        termId: $language_term_ids[0],
        code: NULL,
        source: LanguageDetectionSource::Forced,
        detectedCode: NULL,
        sampleSource: LanguageDetectionSample::None,
      );
    }

    $settings = $this->configFactory->get('reliefweb_ai.settings')->get('language_detection');
    if (empty($settings)) {
      return NULL;
    }

    $min_chars = max(0, (int) ($settings['min_chars'] ?? LanguageDetectionHelper::DEFAULT_MIN_CHARS));
    $min_line_chars = max(1, (int) ($settings['min_line_chars'] ?? LanguageDetectionHelper::DEFAULT_MIN_LINE_CHARS));
    $max_pages = max(1, (int) ($settings['max_pages'] ?? 3));
    $strict_ratio = max(0.0, min(1.0, (float) ($settings['reliable_ratio'] ?? 0.6)));
    $soft_ratio = max(0.0, min(1.0, (float) ($settings['reliable_ratio_soft'] ?? 0.4)));
    $title_min_chars = max(1, (int) ($settings['title_min_chars'] ?? 40));
    $score_margin_min = max(0.0, min(1.0, (float) ($settings['score_margin_min'] ?? 0.10)));
    $eld_database = (string) ($settings['eld_database'] ?? LanguageDetectionHelper::DEFAULT_DATABASE);

    $stages = $this->buildStages(
      $entity,
      $min_chars,
      $min_line_chars,
      $max_pages,
      $title_min_chars,
      $eld_database,
    );
    $order = [
      LanguageDetectionSample::Attachment,
      LanguageDetectionSample::Body,
      LanguageDetectionSample::Title,
    ];

    $reliability_passes = [
      LanguageDetectionSource::Detected->value => $strict_ratio,
      LanguageDetectionSource::DetectedSoft->value => $soft_ratio,
    ];
    foreach ($reliability_passes as $source_value => $ratio) {
      $source = LanguageDetectionSource::from($source_value);
      foreach ($order as $sample_source) {
        $stage = $stages[$sample_source->value] ?? NULL;
        if ($stage === NULL || !$stage['enough'] || $stage['language'] === 'und') {
          continue;
        }
        if ($this->isStageReliable($stage, $ratio)) {
          return $this->mapDetectedLanguage(
            $stage['language'],
            $source,
            $sample_source,
          );
        }
      }
    }

    $score_pick = $this->pickByScore($stages, $order, $score_margin_min);
    if ($score_pick !== NULL) {
      return $this->mapDetectedLanguage(
        $score_pick['language'],
        LanguageDetectionSource::DetectedScore,
        $score_pick['sample_source'],
      );
    }

    // Weak title fallback: trust ELD's top language with no quality gate.
    $title = $stages[LanguageDetectionSample::Title->value] ?? NULL;
    if (
      $title !== NULL
      && $title['language'] !== 'und'
      && $title['language'] !== ''
    ) {
      return $this->mapDetectedLanguage(
        $title['language'],
        LanguageDetectionSource::DetectedTitle,
        LanguageDetectionSample::Title,
      );
    }

    // Default to Other if the language could not be determined.
    return $this->mapDetectedLanguage(
      'ot',
      LanguageDetectionSource::DefaultNone,
      LanguageDetectionSample::None,
      NULL,
    );
  }

  /**
   * Build detection stages for the cascade.
   *
   * @return array<string, array{text: string, language: string, enough: bool, top_score: float, margin: float, eld: ?\Nitotm\Eld\LanguageResult}>
   *   Stages keyed by sample source value.
   */
  protected function buildStages(
    FieldableEntityInterface $entity,
    int $min_chars,
    int $min_line_chars,
    int $max_pages,
    int $title_min_chars,
    string $eld_database,
  ): array {
    $stages = [];

    $attachment = $this->collectAttachmentSample($entity, $min_line_chars, $max_pages);
    if ($attachment !== '') {
      $analysis = $this->analyzeSample($attachment, $eld_database);
      $stages[LanguageDetectionSample::Attachment->value] = [
        'text' => $attachment,
        'language' => $analysis['language'],
        'enough' => LanguageDetectionHelper::hasEnoughText($attachment, $min_chars),
        'top_score' => $analysis['top_score'],
        'margin' => $analysis['margin'],
        'eld' => $analysis['eld'] ?? NULL,
      ];
    }

    $body = $this->collectBodySample($entity, $min_line_chars);
    if ($body !== '') {
      $analysis = $this->analyzeSample($body, $eld_database);
      $stages[LanguageDetectionSample::Body->value] = [
        'text' => $body,
        'language' => $analysis['language'],
        'enough' => LanguageDetectionHelper::hasEnoughText($body, $min_chars),
        'top_score' => $analysis['top_score'],
        'margin' => $analysis['margin'],
        'eld' => $analysis['eld'] ?? NULL,
      ];
    }

    $title = $this->collectTitleSample($entity);
    if ($title !== '') {
      $analysis = $this->analyzeSample($title, $eld_database);
      $stages[LanguageDetectionSample::Title->value] = [
        'text' => $title,
        'language' => $analysis['language'],
        'enough' => mb_strlen(LanguageDetectionHelper::cleanText($title)) >= $title_min_chars,
        'top_score' => $analysis['top_score'],
        'margin' => $analysis['margin'],
        'eld' => $analysis['eld'] ?? NULL,
      ];
    }

    return $stages;
  }

  /**
   * Analyze a text sample once; reliability can be checked at multiple ratios.
   *
   * @return array{language: string, top_score: float, margin: float, eld: ?\Nitotm\Eld\LanguageResult}
   *   Raw language, score summary, and optional ELD result for reliability.
   */
  protected function analyzeSample(string $text, string $eld_database): array {
    if (is_callable($this->languageDetector)) {
      $language = (string) ($this->languageDetector)($text);
      $ok = $language !== 'und' && $language !== '';
      return [
        'language' => $language,
        'top_score' => $ok ? 1.0 : 0.0,
        'margin' => $ok ? 1.0 : 0.0,
        // NULL eld: test doubles are treated as always reliable when not und.
        'eld' => NULL,
      ];
    }

    $result = LanguageDetectionHelper::detect($text, $eld_database);
    $scores = $result->scores();
    $values = array_values($scores);
    $top = (float) ($values[0] ?? 0.0);
    $second = (float) ($values[1] ?? 0.0);
    $margin = $top > 0.0 ? ($top - $second) / $top : 0.0;

    return [
      'language' => $result->language,
      'top_score' => $top,
      'margin' => $margin,
      'eld' => $result,
    ];
  }

  /**
   * Whether a stage passes ELD reliability at the given ratio.
   *
   * @param array{language: string, eld: ?\Nitotm\Eld\LanguageResult} $stage
   *   Built cascade stage.
   * @param float $ratio
   *   ELD isReliable average-score ratio.
   *
   * @return bool
   *   TRUE when the stage language is reliable at this ratio.
   */
  protected function isStageReliable(array $stage, float $ratio): bool {
    if ($stage['language'] === 'und' || $stage['language'] === '') {
      return FALSE;
    }
    $eld = $stage['eld'] ?? NULL;
    if (!$eld instanceof LanguageResult) {
      // Test doubles without an ELD result are always reliable when not und.
      return TRUE;
    }
    return $eld->isReliable($ratio);
  }

  /**
   * Pick the strongest stage by ELD top score with a minimum margin.
   *
   * @param array<string, array{language: string, enough: bool, top_score: float, margin: float}> $stages
   *   Built cascade stages.
   * @param list<\Drupal\reliefweb_ai\Enum\LanguageDetectionSample> $order
   *   Stage priority used when top scores are equal.
   * @param float $score_margin_min
   *   Minimum (top - second) / top margin.
   *
   * @return array{language: string, sample_source: \Drupal\reliefweb_ai\Enum\LanguageDetectionSample}|null
   *   Winning language and sample source, or NULL.
   */
  protected function pickByScore(array $stages, array $order, float $score_margin_min): ?array {
    $best = NULL;
    $best_score = -1.0;
    $best_priority = PHP_INT_MAX;

    foreach ($order as $priority => $sample_source) {
      $stage = $stages[$sample_source->value] ?? NULL;
      if (
        $stage === NULL
        || !$stage['enough']
        || $stage['language'] === 'und'
        || $stage['language'] === ''
        || $stage['margin'] < $score_margin_min
      ) {
        continue;
      }

      $score = $stage['top_score'];
      if ($score > $best_score || ($score === $best_score && $priority < $best_priority)) {
        $best_score = $score;
        $best_priority = $priority;
        $best = [
          'language' => $stage['language'],
          'sample_source' => $sample_source,
        ];
      }
    }

    return $best;
  }

  /**
   * Map a detected language code to a taxonomy term outcome.
   *
   * @return \Drupal\reliefweb_ai\Dto\ReportLanguageDetectionResult
   *   Resolution outcome.
   */
  protected function mapDetectedLanguage(
    string $language,
    LanguageDetectionSource $source,
    LanguageDetectionSample $sample_source,
    ?string $detected_code = NULL,
  ): ReportLanguageDetectionResult {
    if ($detected_code === NULL && $source !== LanguageDetectionSource::DefaultNone) {
      $detected_code = $language;
    }

    $languages = $this->getLanguageTermIdsByCode();

    if ($source === LanguageDetectionSource::DefaultNone) {
      return new ReportLanguageDetectionResult(
        termId: $languages['ot'] ?? NULL,
        code: isset($languages['ot']) ? 'ot' : NULL,
        source: LanguageDetectionSource::DefaultNone,
        detectedCode: $detected_code,
        sampleSource: $sample_source,
      );
    }

    if (isset($languages[$language])) {
      return new ReportLanguageDetectionResult(
        termId: $languages[$language],
        code: $language,
        source: $source,
        detectedCode: $detected_code,
        sampleSource: $sample_source,
      );
    }

    return new ReportLanguageDetectionResult(
      termId: $languages['ot'] ?? NULL,
      code: isset($languages['ot']) ? 'ot' : NULL,
      source: LanguageDetectionSource::DefaultUnmapped,
      detectedCode: $detected_code,
      sampleSource: $sample_source,
    );
  }

  /**
   * Build a detection sample from report attachments.
   */
  protected function collectAttachmentSample(
    FieldableEntityInterface $entity,
    int $min_line_chars,
    int $max_pages,
  ): string {
    if (!$entity->hasField('field_file') || $entity->get('field_file')->isEmpty()) {
      return '';
    }

    $all_pages = [];
    foreach ($entity->get('field_file') as $item) {
      if (!$item instanceof ReliefWebFile) {
        continue;
      }

      $page_count = max(1, (int) $item->getPageCount());
      $pages_to_scan = min($max_pages, $page_count);
      if (!$item->hasPageCount()) {
        $pages_to_scan = $max_pages;
      }

      $pages = $item->extractStructuredTextSpans(1, $pages_to_scan);
      if ($pages === []) {
        continue;
      }
      foreach ($pages as $page_spans) {
        $all_pages[] = $page_spans;
      }
    }

    if ($all_pages === []) {
      return '';
    }

    return LanguageDetectionHelper::buildSampleFromSpans(
      $all_pages,
      $min_line_chars,
    );
  }

  /**
   * Build a detection sample from the body field.
   */
  protected function collectBodySample(
    FieldableEntityInterface $entity,
    int $min_line_chars,
  ): string {
    if (!$entity->hasField('body') || $entity->get('body')->isEmpty()) {
      return '';
    }

    $body = strip_tags((string) ($entity->get('body')->value ?? ''));
    return LanguageDetectionHelper::buildSampleFromText($body, $min_line_chars);
  }

  /**
   * Build a detection sample from the entity title.
   */
  protected function collectTitleSample(FieldableEntityInterface $entity): string {
    return LanguageDetectionHelper::cleanText((string) $entity->label());
  }

  /**
   * Load language term IDs keyed by lower-case ISO language code.
   *
   * @return array<string, string|int>
   *   Language term IDs keyed by language code.
   */
  protected function getLanguageTermIdsByCode(): array {
    if ($this->languageTermIdsByCode !== NULL) {
      return $this->languageTermIdsByCode;
    }

    $languages = $this->database
      ->select('taxonomy_term__field_language_code', 't')
      ->fields('t', ['field_language_code_value', 'entity_id'])
      ->execute()
      ?->fetchAllKeyed() ?? [];

    return $this->languageTermIdsByCode = array_change_key_case($languages, \CASE_LOWER);
  }

}
