<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\ContentEmbeddingsSettings;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingGenerateOptions;
use Drupal\reliefweb_content_analyzer\Services\EmbeddingsStorageInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests ContentEmbeddingsSettings defaults and parsing.
 */
#[CoversClass(ContentEmbeddingsSettings::class)]
class ContentEmbeddingsSettingsTest extends UnitTestCase {

  /**
   * Defaults include nested node/report body source.
   */
  public function testDefaults(): void {
    $settings = ContentEmbeddingsSettings::fromConfigArray(NULL);
    $this->assertSame('http://ocha-ai-helper/text/deduplicate/embed', $settings->embedEndpoint);
    $this->assertSame(EmbeddingsStorageInterface::DEFAULT_DIMENSIONS, $settings->dimensions);
    $source = $settings->getSource('node', 'report');
    $this->assertNotNull($source);
    $this->assertTrue($source->enabled);
    $this->assertSame(['body'], $source->fields);
    $this->assertSame(200, $source->minTextLength);

    $config = $settings->toConfigArray();
    $this->assertArrayHasKey('node', $config['sources']);
    $this->assertArrayHasKey('report', $config['sources']['node']);
    $this->assertArrayNotHasKey('node.report', $config['sources']);
  }

  /**
   * Nested config merges with defaults.
   */
  public function testPartialNestedConfig(): void {
    $settings = ContentEmbeddingsSettings::fromConfigArray([
      'embed_endpoint' => 'http://example.test/embed',
      'sources' => [
        'node' => [
          'report' => [
            'enabled' => FALSE,
            'fields' => ['title', 'body'],
            'min_text_length' => 100,
          ],
        ],
      ],
    ]);
    $this->assertSame('http://example.test/embed', $settings->embedEndpoint);
    $source = $settings->getSource('node', 'report');
    $this->assertFalse($source->enabled);
    $this->assertSame(['title', 'body'], $source->fields);
    $this->assertSame(100, $source->minTextLength);
  }

  /**
   * Legacy flat "node.report" keys are migrated into nested sources.
   */
  public function testLegacyFlatSourceKey(): void {
    $settings = ContentEmbeddingsSettings::fromConfigArray([
      'sources' => [
        'node.report' => [
          'enabled' => FALSE,
          'fields' => ['body'],
          'min_text_length' => 50,
        ],
      ],
    ]);
    $source = $settings->getSource('node', 'report');
    $this->assertNotNull($source);
    $this->assertFalse($source->enabled);
    $this->assertSame(50, $source->minTextLength);
    $this->assertSame(
      ['node' => ['report' => $source->toConfigArray()]],
      $settings->toConfigArray()['sources'],
    );
  }

  /**
   * Skip-existing option constants.
   */
  public function testSkipModeConstants(): void {
    $this->assertSame('id', EmbeddingGenerateOptions::SKIP_ID);
    $this->assertSame('hash', EmbeddingGenerateOptions::SKIP_HASH);
    $this->assertSame('no', EmbeddingGenerateOptions::SKIP_NO);
  }

}
