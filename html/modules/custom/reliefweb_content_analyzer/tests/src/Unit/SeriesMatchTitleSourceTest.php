<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchTitleSource label helpers.
 */
#[CoversClass(SeriesMatchTitleSource::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchTitleSourceTest extends UnitTestCase {

  /**
   * AI-generated titles have no unchanged reason.
   */
  public function testUnchangedReasonNullForAiGenerated(): void {
    $this->assertNull(SeriesMatchTitleSource::AiGenerated->unchangedReason());
  }

  /**
   * Unchanged outcomes expose a non-empty reason phrase.
   */
  #[DataProvider('unchangedReasonProvider')]
  public function testUnchangedReason(SeriesMatchTitleSource $source, string $expected): void {
    $this->assertSame($expected, $source->unchangedReason());
  }

  /**
   * Data provider for unchanged reason phrases.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource, 1: string}>
   *   Source enum case and expected reason.
   */
  public static function unchangedReasonProvider(): array {
    return [
      'pattern match' => [SeriesMatchTitleSource::KeptOriginalPatternMatch, 'matches series pattern'],
      'ai disabled' => [SeriesMatchTitleSource::SkippedAiDisabled, 'AI disabled'],
      'no attachment text' => [SeriesMatchTitleSource::SkippedNoAttachmentText, 'no attachment text'],
      'no candidate titles' => [SeriesMatchTitleSource::FailedNoCandidateTitles, 'no candidate titles'],
      'unsupported plugin' => [SeriesMatchTitleSource::FailedUnsupportedAiPlugin, 'unsupported AI plugin'],
      'ai call error' => [SeriesMatchTitleSource::FailedAiCallError, 'AI call error'],
      'empty ai output' => [SeriesMatchTitleSource::FailedEmptyAiOutput, 'empty AI output'],
    ];
  }

  /**
   * Revision log clauses follow the title unchanged pattern.
   */
  #[DataProvider('revisionLogClauseProvider')]
  public function testRevisionLogClause(SeriesMatchTitleSource $source, string $expected): void {
    $this->assertSame($expected, $source->revisionLogClause());
  }

  /**
   * Data provider for revision log clauses.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource, 1: string}>
   *   Source enum case and expected clause.
   */
  public static function revisionLogClauseProvider(): array {
    return [
      'pattern match' => [
        SeriesMatchTitleSource::KeptOriginalPatternMatch,
        'title unchanged (matches series pattern)',
      ],
      'ai generated' => [SeriesMatchTitleSource::AiGenerated, 'AI-generated title'],
      'ai disabled' => [
        SeriesMatchTitleSource::SkippedAiDisabled,
        'title unchanged (AI disabled)',
      ],
      'failed ai call' => [
        SeriesMatchTitleSource::FailedAiCallError,
        'title unchanged (AI call error)',
      ],
    ];
  }

  /**
   * Legacy stored values map to current enum cases.
   */
  #[DataProvider('legacyStoredValueProvider')]
  public function testTryFromStoredLegacyValues(string $stored, SeriesMatchTitleSource $expected): void {
    $this->assertSame($expected, SeriesMatchTitleSource::tryFromStored($stored));
  }

  /**
   * Data provider for legacy stored title_source values.
   *
   * @return array<string, array{0: string, 1: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource}>
   *   Stored value and expected enum case.
   */
  public static function legacyStoredValueProvider(): array {
    return [
      'failed no source text' => ['failed_no_source_text', SeriesMatchTitleSource::SkippedNoAttachmentText],
      'ai disabled' => ['ai_disabled', SeriesMatchTitleSource::SkippedAiDisabled],
      'failed ai' => ['failed_ai', SeriesMatchTitleSource::FailedAiCallError],
      'current value' => ['skipped_no_attachment_text', SeriesMatchTitleSource::SkippedNoAttachmentText],
    ];
  }

  /**
   * Unknown or empty stored values return NULL.
   */
  public function testTryFromStoredUnknownOrEmpty(): void {
    $this->assertNull(SeriesMatchTitleSource::tryFromStored(NULL));
    $this->assertNull(SeriesMatchTitleSource::tryFromStored(''));
    $this->assertNull(SeriesMatchTitleSource::tryFromStored('unknown_value'));
  }

  /**
   * Title sources map to the expected attention level.
   */
  #[DataProvider('attentionLevelProvider')]
  public function testAttentionLevel(
    SeriesMatchTitleSource $source,
    SeriesMatchAttentionLevel $expected,
  ): void {
    $this->assertSame($expected, $source->attentionLevel());
  }

  /**
   * Data provider for title source attention levels.
   *
   * @return array<string, array{0: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource, 1: \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel}>
   *   Source and expected attention level.
   */
  public static function attentionLevelProvider(): array {
    return [
      'pattern match' => [SeriesMatchTitleSource::KeptOriginalPatternMatch, SeriesMatchAttentionLevel::Ok],
      'ai generated' => [SeriesMatchTitleSource::AiGenerated, SeriesMatchAttentionLevel::Info],
      'ai disabled' => [SeriesMatchTitleSource::SkippedAiDisabled, SeriesMatchAttentionLevel::Warning],
      'no attachment text' => [SeriesMatchTitleSource::SkippedNoAttachmentText, SeriesMatchAttentionLevel::Warning],
      'no candidate titles' => [SeriesMatchTitleSource::FailedNoCandidateTitles, SeriesMatchAttentionLevel::Error],
      'unsupported plugin' => [SeriesMatchTitleSource::FailedUnsupportedAiPlugin, SeriesMatchAttentionLevel::Error],
      'ai call error' => [SeriesMatchTitleSource::FailedAiCallError, SeriesMatchAttentionLevel::Error],
      'empty ai output' => [SeriesMatchTitleSource::FailedEmptyAiOutput, SeriesMatchAttentionLevel::Error],
    ];
  }

}
