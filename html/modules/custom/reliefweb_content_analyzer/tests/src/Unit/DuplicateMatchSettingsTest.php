<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DuplicateMatchSettings defaults and config loading.
 */
#[CoversClass(DuplicateMatchSettings::class)]
#[Group('reliefweb_content_analyzer')]
class DuplicateMatchSettingsTest extends UnitTestCase {

  /**
   * Defaults match the install config values.
   */
  public function testDefaultConfig(): void {
    $defaults = DuplicateMatchSettings::defaultConfig();
    $settings = DuplicateMatchSettings::fromConfigArray($defaults);
    $this->assertTrue($settings->automationEnabledFormCreated);
    $this->assertTrue($settings->automationEnabledImported);
    $this->assertFalse($settings->skipWithAttachments);
    $this->assertTrue($settings->filterBySource);
    $this->assertSame(7, $settings->lookbackDays);
    $this->assertSame(1, $settings->lookforwardDays);
    $this->assertSame(50, $settings->candidateLimit);
    $this->assertSame(200, $settings->minimumBodyLength);
    $this->assertSame(0.85, $settings->minimumLengthRatio);
    $this->assertSame(0.92, $settings->similarityThreshold);
    $this->assertSame(0.70, $settings->tfidfSimilarityThreshold);
    $this->assertSame(0.90, $settings->embeddingSimilarityThreshold);
    $this->assertSame(50, $settings->embeddingTopk);
    $this->assertSame(1095, $settings->embeddingLookbackDays);
    $this->assertSame(2, $settings->embeddingLookforwardDays);
    $this->assertSame('duplicate', $settings->targetStatus);
    $this->assertSame([
      'draft',
      'pending',
      'on-hold',
      'to-review',
      'published',
      'refused',
      'duplicate',
      'embargoed',
      'reference',
      'archive',
    ], $settings->candidateModerationStatuses);
    $this->assertSame(['refused', 'duplicate'], $settings->skipModerationStatuses);
  }

  /**
   * Null config falls back to defaults.
   */
  public function testFromNullConfig(): void {
    $settings = DuplicateMatchSettings::fromConfigArray(NULL);
    $this->assertSame(7, $settings->lookbackDays);
    $this->assertSame(1, $settings->lookforwardDays);
    $this->assertFalse($settings->skipWithAttachments);
    $this->assertTrue($settings->filterBySource);
    $this->assertSame(0.70, $settings->tfidfSimilarityThreshold);
    $this->assertSame(0.90, $settings->embeddingSimilarityThreshold);
    $this->assertSame(50, $settings->embeddingTopk);
    $this->assertSame(1095, $settings->embeddingLookbackDays);
    $this->assertSame(2, $settings->embeddingLookforwardDays);
    $this->assertSame('duplicate', $settings->targetStatus);
  }

  /**
   * Partial config fills missing keys from defaults.
   */
  public function testPartialConfigUsesDefaults(): void {
    $settings = DuplicateMatchSettings::fromConfigArray([
      'lookback_days' => 14,
      'lookforward_days' => 2,
      'similarity_threshold' => 0.95,
    ]);
    $this->assertSame(14, $settings->lookbackDays);
    $this->assertSame(2, $settings->lookforwardDays);
    $this->assertSame(0.95, $settings->similarityThreshold);
    $this->assertTrue($settings->filterBySource);
    $this->assertSame(0.70, $settings->tfidfSimilarityThreshold);
    $this->assertSame(0.90, $settings->embeddingSimilarityThreshold);
    $this->assertSame(50, $settings->embeddingTopk);
    $this->assertSame('duplicate', $settings->targetStatus);
    $this->assertSame(2, $settings->embeddingLookforwardDays);
  }

  /**
   * Explicit target_status is honored.
   */
  public function testTargetStatusCanBeOverridden(): void {
    $settings = DuplicateMatchSettings::fromConfigArray([
      'target_status' => 'pending',
    ]);
    $this->assertSame('pending', $settings->targetStatus);
  }

  /**
   * Explicit filter_by_source false is honored.
   */
  public function testFilterBySourceCanBeDisabled(): void {
    $settings = DuplicateMatchSettings::fromConfigArray([
      'filter_by_source' => FALSE,
    ]);
    $this->assertFalse($settings->filterBySource);
  }

  /**
   * Legacy embedding_endpoint key is ignored without error.
   */
  public function testLegacyEmbeddingEndpointIgnored(): void {
    $settings = DuplicateMatchSettings::fromConfigArray([
      'embedding_endpoint' => 'http://example.test/texts',
      'embedding_similarity_threshold' => 0.91,
    ]);
    $this->assertSame(0.91, $settings->embeddingSimilarityThreshold);
  }

}
