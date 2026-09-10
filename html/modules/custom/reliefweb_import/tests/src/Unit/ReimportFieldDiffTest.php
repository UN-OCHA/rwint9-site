<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\Tests\reliefweb_import\Unit\Stub\ReimportFieldDiffTestImporter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests partial reimport field diff helpers.
 */
#[CoversClass(ReliefWebImporterPluginBase::class)]
#[Group('reliefweb_import')]
class ReimportFieldDiffTest extends UnitTestCase {

  /**
   * Plugin under test.
   */
  protected ReimportFieldDiffTestImporter $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $reflection = new \ReflectionClass(ReimportFieldDiffTestImporter::class);
    $this->plugin = $reflection->newInstanceWithoutConstructor();

    $logger_property = new \ReflectionProperty(ReliefWebImporterPluginBase::class, 'loggerFactory');
    $logger_property->setValue($this->plugin, $logger_factory);
  }

  /**
   * Invoke a protected method on the plugin.
   */
  protected function invoke(string $method_name, array $arguments = []): mixed {
    $method = new \ReflectionMethod(ReliefWebImporterPluginBase::class, $method_name);
    return $method->invokeArgs($this->plugin, $arguments);
  }

  /**
   * Title differs and is skipped; file differs and is applied.
   */
  public function testDescribeReimportFieldDiffTitleSkippedFileApplied(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->plugin->importValueOverrides = [
      'title' => 'New title',
      'file' => ['checksum-new'],
    ];
    $this->plugin->entityValueOverrides = [
      'title' => 'Old title',
      'file' => ['checksum-old'],
    ];

    $data = [
      'title' => 'New title',
      'file' => [
        ['checksum' => 'checksum-new'],
      ],
    ];
    $filtered_data = [
      'file' => $data['file'],
    ];

    $diff = $this->invoke('describeReimportFieldDiff', [
      $data,
      $filtered_data,
      $entity,
    ]);

    $this->assertSame(['file'], $diff['applied']);
    $this->assertSame(['title'], $diff['skipped']);
  }

  /**
   * No differing fields yields empty applied/skipped lists.
   */
  public function testDescribeReimportFieldDiffNoDiffs(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->plugin->importValueOverrides = [
      'title' => 'Same title',
      'file' => ['checksum-same'],
    ];
    $this->plugin->entityValueOverrides = [
      'title' => 'Same title',
      'file' => ['checksum-same'],
    ];

    $data = [
      'title' => 'Same title',
      'file' => [
        ['checksum' => 'checksum-same'],
      ],
    ];
    $filtered_data = [
      'file' => $data['file'],
    ];

    $diff = $this->invoke('describeReimportFieldDiff', [
      $data,
      $filtered_data,
      $entity,
    ]);

    $this->assertSame([], $diff['applied']);
    $this->assertSame([], $diff['skipped']);
  }

  /**
   * Empty filtered data marks differing fields as skipped only.
   */
  public function testDescribeReimportFieldDiffEmptyFilteredPayload(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->plugin->importValueOverrides = [
      'title' => 'New title',
    ];
    $this->plugin->entityValueOverrides = [
      'title' => 'Old title',
    ];

    $data = ['title' => 'New title'];
    $filtered_data = [];

    $diff = $this->invoke('describeReimportFieldDiff', [
      $data,
      $filtered_data,
      $entity,
    ]);

    $this->assertSame([], $diff['applied']);
    $this->assertSame(['title'], $diff['skipped']);
  }

  /**
   * Revision log message includes applied and skipped fields.
   */
  public function testBuildPartialReimportLogMessage(): void {
    $message = $this->invoke('buildPartialReimportLogMessage', [
      [
        'applied' => ['file'],
        'skipped' => ['title'],
      ],
    ]);

    $this->assertSame(
      'Automatic partial update from Post API. Applied: file. Skipped by reimport rules: title.',
      $message
    );
  }

  /**
   * Revision log message includes an original document review link.
   */
  public function testBuildPartialReimportLogMessageWithOriginUrl(): void {
    $message = $this->invoke('buildPartialReimportLogMessage', [
      [
        'applied' => ['file'],
        'skipped' => ['title'],
      ],
      'https://data.unhcr.org/en/documents/details/123941',
    ]);

    $this->assertSame(
      'Automatic partial update from Post API. Applied: file. Skipped by reimport rules: title. Please review [original document](https://data.unhcr.org/en/documents/details/123941).',
      $message
    );
  }

  /**
   * Revision log message without field diffs keeps the default prefix.
   */
  public function testBuildPartialReimportLogMessageNoDiffs(): void {
    $message = $this->invoke('buildPartialReimportLogMessage', [
      [
        'applied' => [],
        'skipped' => [],
      ],
    ]);

    $this->assertSame('Automatic partial update from Post API.', $message);
  }

  /**
   * Incoming origin URL is preferred when it is an http(s) URL.
   */
  public function testResolvePartialReimportOriginUrlPrefersIncomingOrigin(): void {
    $entity = $this->createOriginNotesEntity('https://example.test/from-entity');

    $url = $this->invoke('resolvePartialReimportOriginUrl', [
      ['origin' => 'https://example.test/from-import'],
      $entity,
    ]);

    $this->assertSame('https://example.test/from-import', $url);
  }

  /**
   * Falls back to field_origin_notes when import origin is missing.
   */
  public function testResolvePartialReimportOriginUrlFallsBackToEntity(): void {
    $entity = $this->createOriginNotesEntity('https://example.test/from-entity');

    $url = $this->invoke('resolvePartialReimportOriginUrl', [
      [],
      $entity,
    ]);

    $this->assertSame('https://example.test/from-entity', $url);
  }

  /**
   * Non-URL origin notes are ignored.
   */
  public function testResolvePartialReimportOriginUrlIgnoresNonUrls(): void {
    $entity = $this->createOriginNotesEntity('Not a URL');

    $url = $this->invoke('resolvePartialReimportOriginUrl', [
      ['origin' => 'also-not-a-url'],
      $entity,
    ]);

    $this->assertSame('', $url);
  }

  /**
   * Create an entity mock with field_origin_notes.
   */
  protected function createOriginNotesEntity(string $value): ContentEntityInterface {
    $field = new class($value) {

      /**
       * Field value.
       */
      public string $value;

      public function __construct(string $value) {
        $this->value = $value;
      }

    };

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasField')->with('field_origin_notes')->willReturn(TRUE);
    $entity->method('get')->with('field_origin_notes')->willReturn($field);
    return $entity;
  }

  /**
   * Date normalization keeps Y-m-d.
   */
  public function testNormalizeComparableDate(): void {
    $this->assertSame('2026-09-03', $this->invoke('normalizeComparableDate', ['2026-09-03T00:00:00+00:00']));
    $this->assertSame('2026-09-03', $this->invoke('normalizeComparableDate', ['2026-09-03']));
    $this->assertSame('', $this->invoke('normalizeComparableDate', [NULL]));
  }

  /**
   * Term IDs are normalized to sorted integers.
   */
  public function testNormalizeComparableTermIds(): void {
    $this->assertSame([3, 10], $this->invoke('normalizeComparableTermIds', [[10, '3', 0]]));
  }

  /**
   * File checksums are extracted and sorted.
   */
  public function testNormalizeComparableFileChecksums(): void {
    $files = [
      ['checksum' => 'b'],
      ['url' => 'https://example.test/a.pdf'],
      ['checksum' => 'a'],
    ];
    $this->assertSame(['a', 'b'], $this->invoke('normalizeComparableFileChecksums', [$files]));
  }

}
