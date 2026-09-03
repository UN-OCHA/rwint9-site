<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate;
use Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcher;
use Drupal\reliefweb_utility\Helpers\TitlePatternHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ReportDuplicateMatcher helpers that do not need full DI.
 */
#[CoversClass(ReportDuplicateMatcher::class)]
#[Group('reliefweb_content_analyzer')]
class ReportDuplicateMatcherEmbeddingTest extends UnitTestCase {

  /**
   * Invoke a protected matcher method without constructing dependencies.
   *
   * @param string $method
   *   Method name.
   * @param array<int, mixed> $args
   *   Arguments.
   *
   * @return mixed
   *   Return value.
   */
  protected function invoke(string $method, array $args = []): mixed {
    $matcher = (new \ReflectionClass(ReportDuplicateMatcher::class))
      ->newInstanceWithoutConstructor();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('report_series_matching.matcher.title_pattern_similarity_threshold')
      ->willReturn(TitlePatternHelper::DEFAULT_SERIES_STEM_SIMILARITY);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('reliefweb_content_analyzer.settings')
      ->willReturn($config);
    $property = new \ReflectionProperty(ReportDuplicateMatcher::class, 'configFactory');
    $property->setValue($matcher, $factory);

    $ref = new \ReflectionMethod(ReportDuplicateMatcher::class, $method);
    return $ref->invoke($matcher, ...$args);
  }

  /**
   * Series sibling titles are detected.
   */
  public function testSeriesSiblingTitles(): void {
    $this->assertTrue($this->invoke('isSeriesSibling', [
      'Sudan Situation Report No. 12',
      'Sudan Situation Report No. 13',
    ]));
    $this->assertFalse($this->invoke('isSeriesSibling', [
      'Completely unrelated headline about floods',
      'Another different story about earthquakes',
    ]));
    $this->assertTrue($this->invoke('isSeriesSibling', [
      'UNHCR Middle East Situation: Emergency Flash Update #14 as of 21 April 2026',
      'UNHCR Middle East Situation: Emergency Update #15 as of 29 April 2026',
    ]));
    $this->assertSame(
      TitlePatternHelper::COMPARE_SERIES_SIBLING,
      TitlePatternHelper::compareSeriesMarkers(
        TitlePatternHelper::extractSeriesMarkers('Sudan Situation Report No. 12'),
        TitlePatternHelper::extractSeriesMarkers('Sudan Situation Report No. 13'),
      ),
    );
  }

  /**
   * Candidate current title can discard when first-revision title cannot.
   */
  public function testSeriesSiblingCandidateUsesCurrentTitle(): void {
    $probe = 'UNHCR-Syria-operational-update -March 2026';
    $first = 'UNHCR Syria Operational Update -February';
    $current = 'UNHCR Syria Operational Update, February 2026';

    $this->assertFalse($this->invoke('isSeriesSibling', [$probe, $first]));
    $this->assertTrue($this->invoke('isSeriesSibling', [$probe, $current]));
    $this->assertFalse($this->invoke('isSeriesSiblingCandidate', [
      $probe,
      $first,
      $first,
    ]));
    $this->assertTrue($this->invoke('isSeriesSiblingCandidate', [
      $probe,
      $first,
      $current,
    ]));
  }

  /**
   * Union tags both / window / embedding sources.
   */
  public function testUnionCandidateSources(): void {
    $window = [
      (object) [
        'nid' => 1,
        'title' => 'A',
        'created' => 1,
        'body_value' => 'body a',
      ],
    ];
    $emb = [
      (object) [
        'nid' => 1,
        'title' => 'A',
        'created' => 1,
        'body_value' => 'body a',
      ],
      (object) [
        'nid' => 2,
        'title' => 'B',
        'created' => 2,
        'body_value' => 'body b',
      ],
    ];
    $union = $this->invoke('unionCandidateRows', [$window, $emb]);
    $by_nid = [];
    foreach ($union as $row) {
      $by_nid[(int) $row->nid] = $row->candidate_source;
    }
    $this->assertSame(DuplicateMatchCandidate::SOURCE_BOTH, $by_nid[1]);
    $this->assertSame(DuplicateMatchCandidate::SOURCE_EMBEDDING, $by_nid[2]);
  }

}
