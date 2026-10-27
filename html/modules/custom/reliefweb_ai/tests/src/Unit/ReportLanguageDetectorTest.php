<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_ai\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\reliefweb_ai\Dto\ReportLanguageDetectionResult;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSample;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSource;
use Drupal\reliefweb_ai\Service\ReportLanguageDetector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;

/**
 * Tests report language detector orchestration.
 */
#[CoversClass(ReportLanguageDetector::class)]
#[Group('reliefweb_ai')]
class ReportLanguageDetectorTest extends UnitTestCase {

  /**
   * Language term IDs keyed by code used in tests.
   *
   * @var array<string, int>
   */
  private array $languageMap = [
    'en' => 267,
    'fr' => 268,
    'ot' => 269,
  ];

  /**
   * Force language from importer hook wins.
   */
  public function testForceLanguageWins(): void {
    $entity = $this->createReportEntity('', NULL);

    $module_handler = $this->prophesize(ModuleHandlerInterface::class);
    $module_handler->invokeAll('reliefweb_ai_force_language', Argument::any())
      ->willReturn([268]);

    $detector = $this->buildDetector(
      module_handler: $module_handler->reveal(),
    );

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::Forced, $outcome->source);
    $this->assertSame(268, $outcome->termId);
    $this->assertSame(['field_language'], $detector->updateLanguage($entity));
    $this->assertSame(268, $entity->get('field_language')->target_id);
  }

  /**
   * Sparse samples with no reliable detection default to other.
   */
  public function testSparseTextDefaultsToOther(): void {
    $entity = $this->createReportEntity('Legend North South km', NULL);

    $detector = $this->buildDetector(
      language_detector: static fn(string $text): string => 'und',
    );

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::DefaultNone, $outcome->source);
    $this->assertSame('ot', $outcome->code);
    $this->assertSame(LanguageDetectionSample::None, $outcome->sampleSource);
    $this->assertSame(['field_language'], $detector->updateLanguage($entity));
    $this->assertSame(269, $entity->get('field_language')->target_id);
  }

  /**
   * Prose body uses the detected language mapping.
   */
  public function testProseTextUsesDetectedLanguage(): void {
    $body = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $entity = $this->createReportEntity($body, NULL);

    $detector = $this->buildDetector(
      language_detector: static fn(string $text): string => 'fr',
    );

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::Detected, $outcome->source);
    $this->assertSame(LanguageDetectionSample::Body, $outcome->sampleSource);
    $this->assertSame('fr', $outcome->code);
    $this->assertSame('fr', $outcome->detectedCode);
    $this->assertSame(['field_language'], $detector->updateLanguage($entity));
    $this->assertSame(268, $entity->get('field_language')->target_id);
  }

  /**
   * Unreliable detection across stages defaults to other.
   */
  public function testUnreliableDetectionDefaultsToOther(): void {
    $body = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $entity = $this->createReportEntity($body, NULL);

    $detector = $this->buildDetector(
      language_detector: static fn(string $text): string => 'und',
    );

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::DefaultNone, $outcome->source);
    $this->assertSame('ot', $outcome->code);
    $this->assertSame(LanguageDetectionSample::None, $outcome->sampleSource);
    $this->assertNull($outcome->detectedCode);
    $this->assertSame(['field_language'], $detector->updateLanguage($entity));
    $this->assertSame(269, $entity->get('field_language')->target_id);
  }

  /**
   * Unmapped detected code falls back to the "other" language term.
   */
  public function testUnmappedDetectedLanguageFallsBackToOther(): void {
    $body = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $entity = $this->createReportEntity($body, NULL);

    $detector = $this->buildDetector(
      language_detector: static fn(string $text): string => 'es',
    );

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::DefaultUnmapped, $outcome->source);
    $this->assertSame('ot', $outcome->code);
    $this->assertSame('es', $outcome->detectedCode);
    $this->assertSame(LanguageDetectionSample::Body, $outcome->sampleSource);
    $this->assertSame(269, $outcome->termId);
  }

  /**
   * Empty attachment sample falls through to body detection.
   */
  public function testEmptyAttachmentFallsThroughToBody(): void {
    $body = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $entity = $this->createReportEntity($body, NULL, has_file: TRUE);

    $detector = $this->buildDetector(
      language_detector: static fn(string $text): string => 'fr',
      attachment_sample: '',
    );

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::Detected, $outcome->source);
    $this->assertSame(LanguageDetectionSample::Body, $outcome->sampleSource);
    $this->assertSame('fr', $outcome->code);
  }

  /**
   * Soft detection is reported when only the soft ratio would accept.
   *
   * With the test callable, non-und languages are always reliable, so soft is
   * exercised via a custom detector subclass that fails strict and passes soft.
   */
  public function testSoftDetectionSource(): void {
    $body = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $entity = $this->createReportEntity($body, NULL);

    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('language_detection')->willReturn($this->defaultLanguageDetectionConfig());
    $config_factory = $this->prophesize(ConfigFactoryInterface::class);
    $config_factory->get('reliefweb_ai.settings')->willReturn($config->reveal());

    $module_handler = $this->prophesize(ModuleHandlerInterface::class);
    $module_handler->invokeAll('reliefweb_ai_force_language', Argument::any())
      ->willReturn([]);

    $language_map = $this->languageMap;
    $detector = new class(
      $config_factory->reveal(),
      $module_handler->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $language_map,
    ) extends ReportLanguageDetector {

      /**
       * Language map for tests.
       *
       * @var array<string, int>
       */
      private array $testLanguageMap;

      /**
       * Constructs the test detector.
       *
       * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
       *   Config factory.
       * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
       *   Module handler.
       * @param \Drupal\Core\Database\Connection $database
       *   Database connection.
       * @param array<string, int> $language_map
       *   Language term IDs keyed by code.
       */
      public function __construct(
        ConfigFactoryInterface $config_factory,
        ModuleHandlerInterface $module_handler,
        Connection $database,
        array $language_map,
      ) {
        parent::__construct($config_factory, $module_handler, $database);
        $this->testLanguageMap = $language_map;
      }

      /**
       * {@inheritdoc}
       */
      protected function getLanguageTermIdsByCode(): array {
        return $this->testLanguageMap;
      }

      /**
       * {@inheritdoc}
       */
      protected function analyzeSample(string $text, string $eld_database = 'medium'): array {
        return [
          'language' => 'fr',
          'top_score' => 0.2,
          'margin' => 0.5,
          'eld' => NULL,
        ];
      }

      /**
       * {@inheritdoc}
       */
      protected function isStageReliable(array $stage, float $ratio): bool {
        return $ratio <= 0.4;
      }

    };

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::DetectedSoft, $outcome->source);
    $this->assertSame(LanguageDetectionSample::Body, $outcome->sampleSource);
    $this->assertSame('fr', $outcome->code);
  }

  /**
   * Score tie-break prefers the higher-scoring stage when reliability fails.
   */
  public function testScoreTieBreakPrefersHigherScore(): void {
    $body = 'This situation report summarizes humanitarian needs across affected districts and outlines response priorities for the coming month ahead.';
    $entity = $this->createReportEntity($body, NULL, has_file: TRUE);

    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('language_detection')->willReturn($this->defaultLanguageDetectionConfig());
    $config_factory = $this->prophesize(ConfigFactoryInterface::class);
    $config_factory->get('reliefweb_ai.settings')->willReturn($config->reveal());

    $module_handler = $this->prophesize(ModuleHandlerInterface::class);
    $module_handler->invokeAll('reliefweb_ai_force_language', Argument::any())
      ->willReturn([]);

    $language_map = $this->languageMap;
    $detector = new class(
      $config_factory->reveal(),
      $module_handler->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $language_map,
      $body,
    ) extends ReportLanguageDetector {

      /**
       * Language map for tests.
       *
       * @var array<string, int>
       */
      private array $testLanguageMap;

      /**
       * Body text reused as the attachment sample.
       */
      private string $bodyText;

      /**
       * Constructs the test detector.
       *
       * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
       *   Config factory.
       * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
       *   Module handler.
       * @param \Drupal\Core\Database\Connection $database
       *   Database connection.
       * @param array<string, int> $language_map
       *   Language term IDs keyed by code.
       * @param string $body_text
       *   Body text used as the attachment sample.
       */
      public function __construct(
        ConfigFactoryInterface $config_factory,
        ModuleHandlerInterface $module_handler,
        Connection $database,
        array $language_map,
        string $body_text,
      ) {
        parent::__construct($config_factory, $module_handler, $database);
        $this->testLanguageMap = $language_map;
        $this->bodyText = $body_text;
      }

      /**
       * {@inheritdoc}
       */
      protected function getLanguageTermIdsByCode(): array {
        return $this->testLanguageMap;
      }

      /**
       * {@inheritdoc}
       */
      protected function collectAttachmentSample(
        FieldableEntityInterface $entity,
        int $min_line_chars,
        int $max_pages,
      ): string {
        return $this->bodyText;
      }

      /**
       * {@inheritdoc}
       */
      protected function analyzeSample(string $text, string $eld_database = 'medium'): array {
        $is_title = !str_contains($text, 'situation report');
        return [
          'language' => 'en',
          'top_score' => $is_title ? 0.05 : 0.12,
          'margin' => 0.5,
          'eld' => NULL,
        ];
      }

      /**
       * {@inheritdoc}
       */
      protected function isStageReliable(array $stage, float $ratio): bool {
        return FALSE;
      }

    };

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::DetectedScore, $outcome->source);
    $this->assertSame(LanguageDetectionSample::Attachment, $outcome->sampleSource);
    $this->assertSame('en', $outcome->code);
  }

  /**
   * Title lenient accepts raw title language when score margin is too thin.
   */
  public function testTitleLenientWhenScoreMarginTooLow(): void {
    $entity = $this->createReportEntity('', NULL);

    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('language_detection')->willReturn($this->defaultLanguageDetectionConfig());
    $config_factory = $this->prophesize(ConfigFactoryInterface::class);
    $config_factory->get('reliefweb_ai.settings')->willReturn($config->reveal());

    $module_handler = $this->prophesize(ModuleHandlerInterface::class);
    $module_handler->invokeAll('reliefweb_ai_force_language', Argument::any())
      ->willReturn([]);

    $language_map = $this->languageMap;
    $detector = new class(
      $config_factory->reveal(),
      $module_handler->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $language_map,
    ) extends ReportLanguageDetector {

      /**
       * Language map for tests.
       *
       * @var array<string, int>
       */
      private array $testLanguageMap;

      /**
       * Constructs the test detector.
       *
       * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
       *   Config factory.
       * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
       *   Module handler.
       * @param \Drupal\Core\Database\Connection $database
       *   Database connection.
       * @param array<string, int> $language_map
       *   Language term IDs keyed by code.
       */
      public function __construct(
        ConfigFactoryInterface $config_factory,
        ModuleHandlerInterface $module_handler,
        Connection $database,
        array $language_map,
      ) {
        parent::__construct($config_factory, $module_handler, $database);
        $this->testLanguageMap = $language_map;
      }

      /**
       * {@inheritdoc}
       */
      protected function getLanguageTermIdsByCode(): array {
        return $this->testLanguageMap;
      }

      /**
       * {@inheritdoc}
       */
      protected function analyzeSample(string $text, string $eld_database = 'medium'): array {
        return [
          'language' => 'en',
          'top_score' => 0.04,
          'margin' => 0.05,
          'eld' => NULL,
        ];
      }

      /**
       * {@inheritdoc}
       */
      protected function isStageReliable(array $stage, float $ratio): bool {
        return FALSE;
      }

    };

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::DetectedTitle, $outcome->source);
    $this->assertSame(LanguageDetectionSample::Title, $outcome->sampleSource);
    $this->assertSame('en', $outcome->code);
    $this->assertSame('en', $outcome->detectedCode);
  }

  /**
   * Title lenient ignores title_min_chars when reliability and score fail.
   */
  public function testTitleLenientIgnoresEnoughThreshold(): void {
    // Under default title_min_chars (40).
    $entity = $this->createReportEntity('', NULL, title: '2026 Global Hunger Index by Severity');

    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('language_detection')->willReturn($this->defaultLanguageDetectionConfig());
    $config_factory = $this->prophesize(ConfigFactoryInterface::class);
    $config_factory->get('reliefweb_ai.settings')->willReturn($config->reveal());

    $module_handler = $this->prophesize(ModuleHandlerInterface::class);
    $module_handler->invokeAll('reliefweb_ai_force_language', Argument::any())
      ->willReturn([]);

    $language_map = $this->languageMap;
    $detector = new class(
      $config_factory->reveal(),
      $module_handler->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $language_map,
    ) extends ReportLanguageDetector {

      /**
       * Language map for tests.
       *
       * @var array<string, int>
       */
      private array $testLanguageMap;

      /**
       * Constructs the test detector.
       *
       * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
       *   Config factory.
       * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
       *   Module handler.
       * @param \Drupal\Core\Database\Connection $database
       *   Database connection.
       * @param array<string, int> $language_map
       *   Language term IDs keyed by code.
       */
      public function __construct(
        ConfigFactoryInterface $config_factory,
        ModuleHandlerInterface $module_handler,
        Connection $database,
        array $language_map,
      ) {
        parent::__construct($config_factory, $module_handler, $database);
        $this->testLanguageMap = $language_map;
      }

      /**
       * {@inheritdoc}
       */
      protected function getLanguageTermIdsByCode(): array {
        return $this->testLanguageMap;
      }

      /**
       * {@inheritdoc}
       */
      protected function analyzeSample(string $text, string $eld_database = 'medium'): array {
        return [
          'language' => 'en',
          'top_score' => 0.09,
          'margin' => 0.55,
          'eld' => NULL,
        ];
      }

      /**
       * {@inheritdoc}
       */
      protected function isStageReliable(array $stage, float $ratio): bool {
        return FALSE;
      }

    };

    $outcome = $detector->resolveLanguage($entity);
    $this->assertSame(LanguageDetectionSource::DetectedTitle, $outcome->source);
    $this->assertSame(LanguageDetectionSample::Title, $outcome->sampleSource);
    $this->assertSame('en', $outcome->code);
  }

  /**
   * No update when the detected language already matches.
   */
  public function testNoUpdateWhenLanguageUnchanged(): void {
    $entity = $this->createReportEntity('Legend North South km', 269);

    $detector = $this->buildDetector(
      language_detector: static fn(string $text): string => 'und',
    );

    $this->assertNull($detector->updateLanguage($entity));
    $this->assertSame(269, $entity->get('field_language')->target_id);
  }

  /**
   * Simulation notes describe each resolution source.
   */
  public function testFormatSimulationNote(): void {
    $detected = ReportLanguageDetector::formatSimulationNote(new ReportLanguageDetectionResult(
      termId: 268,
      code: 'fr',
      source: LanguageDetectionSource::Detected,
      detectedCode: 'fr',
      sampleSource: LanguageDetectionSample::Body,
    ));
    $this->assertSame('(detected: @sample)', $detected->getUntranslatedString());
    $this->assertSame('body', $detected->getArguments()['@sample']);

    $soft = ReportLanguageDetector::formatSimulationNote(new ReportLanguageDetectionResult(
      termId: 268,
      code: 'fr',
      source: LanguageDetectionSource::DetectedSoft,
      detectedCode: 'fr',
      sampleSource: LanguageDetectionSample::Attachment,
    ));
    $this->assertSame('(soft: @sample)', $soft->getUntranslatedString());
    $this->assertSame('attachment', $soft->getArguments()['@sample']);

    $score = ReportLanguageDetector::formatSimulationNote(new ReportLanguageDetectionResult(
      termId: 267,
      code: 'en',
      source: LanguageDetectionSource::DetectedScore,
      detectedCode: 'en',
      sampleSource: LanguageDetectionSample::Title,
    ));
    $this->assertSame('(score: @sample)', $score->getUntranslatedString());
    $this->assertSame('title', $score->getArguments()['@sample']);

    $title = ReportLanguageDetector::formatSimulationNote(new ReportLanguageDetectionResult(
      termId: 267,
      code: 'en',
      source: LanguageDetectionSource::DetectedTitle,
      detectedCode: 'en',
      sampleSource: LanguageDetectionSample::Title,
    ));
    $this->assertSame('(title)', $title->getUntranslatedString());

    $none = ReportLanguageDetector::formatSimulationNote(new ReportLanguageDetectionResult(
      termId: 269,
      code: 'ot',
      source: LanguageDetectionSource::DefaultNone,
      detectedCode: NULL,
      sampleSource: LanguageDetectionSample::None,
    ));
    $this->assertSame('(defaulted: other)', $none->getUntranslatedString());

    $unmapped = ReportLanguageDetector::formatSimulationNote(new ReportLanguageDetectionResult(
      termId: 269,
      code: 'ot',
      source: LanguageDetectionSource::DefaultUnmapped,
      detectedCode: 'es',
      sampleSource: LanguageDetectionSample::Body,
    ));
    $this->assertSame('(defaulted: other; no term for @detected)', $unmapped->getUntranslatedString());
    $this->assertSame('es', $unmapped->getArguments()['@detected']);
  }

  /**
   * Default language_detection config for unit tests.
   *
   * @return array<string, mixed>
   *   Config mapping.
   */
  private function defaultLanguageDetectionConfig(): array {
    return [
      'min_chars' => 100,
      'min_line_chars' => 40,
      'max_pages' => 3,
      'reliable_ratio' => 0.6,
      'reliable_ratio_soft' => 0.4,
      'title_min_chars' => 40,
      'score_margin_min' => 0.10,
      'eld_database' => 'medium',
    ];
  }

  /**
   * Build a detector with mocked dependencies.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $module_handler
   *   Optional module handler.
   * @param callable|null $language_detector
   *   Optional language detector callable.
   * @param string|null $attachment_sample
   *   Optional forced attachment sample (NULL = real collection).
   *
   * @return \Drupal\reliefweb_ai\Service\ReportLanguageDetector
   *   Detector under test.
   */
  private function buildDetector(
    ?ModuleHandlerInterface $module_handler = NULL,
    ?callable $language_detector = NULL,
    ?string $attachment_sample = NULL,
  ): ReportLanguageDetector {
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('language_detection')->willReturn($this->defaultLanguageDetectionConfig());

    $config_factory = $this->prophesize(ConfigFactoryInterface::class);
    $config_factory->get('reliefweb_ai.settings')->willReturn($config->reveal());

    if ($module_handler === NULL) {
      $module_handler_prophecy = $this->prophesize(ModuleHandlerInterface::class);
      $module_handler_prophecy->invokeAll('reliefweb_ai_force_language', Argument::any())
        ->willReturn([]);
      $module_handler = $module_handler_prophecy->reveal();
    }

    $database = $this->prophesize(Connection::class)->reveal();

    return new class(
      $config_factory->reveal(),
      $module_handler,
      $database,
      $language_detector,
      $this->languageMap,
      $attachment_sample,
    ) extends ReportLanguageDetector {

      /**
       * Language map for tests.
       *
       * @var array<string, int>
       */
      private array $testLanguageMap;

      /**
       * Forced attachment sample, or NULL to use parent collection.
       */
      private ?string $forcedAttachmentSample;

      /**
       * Constructs the test detector.
       *
       * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
       *   Config factory.
       * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
       *   Module handler.
       * @param \Drupal\Core\Database\Connection $database
       *   Database connection.
       * @param callable|null $language_detector
       *   Optional language detector callable.
       * @param array<string, int> $language_map
       *   Language term IDs keyed by code.
       * @param string|null $attachment_sample
       *   Forced attachment sample, or NULL for parent collection.
       */
      public function __construct(
        ConfigFactoryInterface $config_factory,
        ModuleHandlerInterface $module_handler,
        Connection $database,
        ?callable $language_detector,
        array $language_map,
        ?string $attachment_sample,
      ) {
        parent::__construct($config_factory, $module_handler, $database, $language_detector);
        $this->testLanguageMap = $language_map;
        $this->forcedAttachmentSample = $attachment_sample;
      }

      /**
       * {@inheritdoc}
       */
      protected function getLanguageTermIdsByCode(): array {
        return $this->testLanguageMap;
      }

      /**
       * {@inheritdoc}
       */
      protected function collectAttachmentSample(
        FieldableEntityInterface $entity,
        int $min_line_chars,
        int $max_pages,
      ): string {
        if ($this->forcedAttachmentSample !== NULL) {
          return $this->forcedAttachmentSample;
        }
        return parent::collectAttachmentSample($entity, $min_line_chars, $max_pages);
      }

    };
  }

  /**
   * Create a report entity mock with body and language field.
   *
   * @param string $body
   *   Body text.
   * @param int|null $language_term_id
   *   Current language term ID.
   * @param bool $has_file
   *   Whether field_file is non-empty (items are not real ReliefWebFile).
   * @param string $title
   *   Entity label used as the title detection sample.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Entity mock.
   */
  private function createReportEntity(
    string $body,
    ?int $language_term_id,
    bool $has_file = FALSE,
    string $title = 'Test report title that is long enough for title stage',
  ): ContentEntityInterface {
    $language_field = new class($language_term_id) {

      /**
       * Language term ID.
       *
       * Named to mirror Drupal field item API.
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public mixed $target_id;

      /**
       * Constructs the language field stub.
       *
       * @param mixed $target_id
       *   Language term ID.
       */
      public function __construct(mixed $target_id) {
        $this->target_id = $target_id;
      }

      /**
       * Checks whether the language field is empty.
       *
       * @return bool
       *   TRUE when no language term is set.
       */
      public function isEmpty(): bool {
        return $this->target_id === NULL || $this->target_id === '';
      }

    };

    $body_field = new class($body) {

      /**
       * Body value.
       */
      public string $value;

      /**
       * Constructs the body field stub.
       *
       * @param string $value
       *   Body text.
       */
      public function __construct(string $value) {
        $this->value = $value;
      }

      /**
       * Checks whether the body field is empty.
       *
       * @return bool
       *   TRUE when the body text is empty.
       */
      public function isEmpty(): bool {
        return $this->value === '';
      }

    };

    $file_field = new class($has_file) implements \IteratorAggregate {

      /**
       * Constructs the file field stub.
       *
       * @param bool $hasFile
       *   Whether the field should appear non-empty.
       */
      public function __construct(private bool $hasFile) {}

      /**
       * Checks whether the file field is empty.
       *
       * @return bool
       *   TRUE when there are no attachments.
       */
      public function isEmpty(): bool {
        return !$this->hasFile;
      }

      /**
       * {@inheritdoc}
       */
      public function getIterator(): \ArrayIterator {
        // Non-ReliefWebFile items are skipped by collectAttachmentSample.
        return new \ArrayIterator($this->hasFile ? [new \stdClass()] : []);
      }

    };

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasField')->willReturnCallback(static function (string $field_name): bool {
      return in_array($field_name, ['field_language', 'body', 'field_file'], TRUE);
    });
    $entity->method('get')->willReturnCallback(static function (string $field_name) use ($body_field, $file_field, $language_field) {
      return match ($field_name) {
        'body' => $body_field,
        'field_file' => $file_field,
        'field_language' => $language_field,
        default => NULL,
      };
    });
    $entity->method('label')->willReturn($title);
    $entity->method('set')->willReturnCallback(static function (string $name, mixed $value) use ($language_field, $entity) {
      if ($name === 'field_language') {
        $language_field->target_id = $value;
      }
      return $entity;
    });

    return $entity;
  }

}
