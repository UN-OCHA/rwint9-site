<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ocha_ai\Plugin\CompletionPluginBase;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchMatcherConfigFixture;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ReportSeriesMatcher helpers for cluster lookback display.
 */
#[CoversClass(ReportSeriesMatcher::class)]
#[Group('reliefweb_content_analyzer')]
class ReportSeriesMatcherTest extends UnitTestCase {

  /**
   * Builds a config factory stub with matcher settings.
   *
   * @param array<string, mixed> $matcher_overrides
   *   Values to merge over the default matcher config.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   Config factory stub returning matcher settings.
   */
  private function buildConfigFactory(array $matcher_overrides = []): ConfigFactoryInterface {
    $matcher = array_merge(SeriesMatchMatcherConfigFixture::defaults(), $matcher_overrides);
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static function (string $key) use ($matcher): mixed {
        if ($key === 'report_series_matching.matcher') {
          return $matcher;
        }
        return NULL;
      },
    );

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

  /**
   * Builds a matcher with constructor dependencies stubbed.
   *
   * @param array<string, mixed> $matcher_overrides
   *   Optional overrides for matcher config.
   * @param \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface|null $completion
   *   Optional completion plugin manager mock.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface|null $logger_factory
   *   Optional logger factory mock.
   *
   * @return \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher
   *   Matcher with stubbed dependencies.
   */
  private function buildMatcher(
    array $matcher_overrides = [],
    ?CompletionPluginManagerInterface $completion = NULL,
    ?LoggerChannelFactoryInterface $logger_factory = NULL,
  ): ReportSeriesMatcher {
    if ($logger_factory === NULL) {
      $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
      $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    }
    return new ReportSeriesMatcher(
      $this->buildConfigFactory($matcher_overrides),
      $logger_factory,
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(Connection::class),
      $completion ?? $this->createMock(CompletionPluginManagerInterface::class),
    );
  }

  /**
   * Builds a partial matcher that returns fixed PDF page spans.
   *
   * @param list<list<array{text: string, x: float, y: float, w: float, h: float, size: float}>> $pages
   *   Per-page spans returned by the attachment.
   * @param array<string, mixed>|null $match_result
   *   Stubbed SeriesTitleMatchHelper-shaped result, or NULL.
   * @param \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface $completion
   *   Completion plugin manager mock.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface|null $logger_factory
   *   Optional logger factory.
   *
   * @return \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher&\PHPUnit\Framework\MockObject\MockObject
   *   Partial matcher mock.
   */
  private function buildMatcherWithSpans(
    array $pages,
    ?array $match_result,
    CompletionPluginManagerInterface $completion,
    ?LoggerChannelFactoryInterface $logger_factory = NULL,
  ): ReportSeriesMatcher {
    $file = $this->createMock(ReliefWebFile::class);
    $file->method('extractStructuredTextSpans')->with(1, 2)->willReturn($pages);

    if ($logger_factory === NULL) {
      $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
      $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    }

    $matcher = $this->getMockBuilder(ReportSeriesMatcher::class)
      ->setConstructorArgs([
        $this->buildConfigFactory(),
        $logger_factory,
        $this->createMock(EntityFieldManagerInterface::class),
        $this->createMock(TimeInterface::class),
        $this->createMock(Connection::class),
        $completion,
      ])
      ->onlyMethods(['getFirstFile', 'matchSeriesTitleRegion'])
      ->getMock();
    $matcher->method('getFirstFile')->willReturn($file);
    $matcher->method('matchSeriesTitleRegion')->willReturn($match_result);
    return $matcher;
  }

  /**
   * Invokes a protected method on ReportSeriesMatcher.
   *
   * @param string $method_name
   *   Method name.
   * @param mixed ...$args
   *   Method arguments.
   *
   * @return mixed
   *   Method return value.
   */
  private function invokeProtected(
    string $method_name,
    mixed ...$args,
  ): mixed {
    return $this->invokeProtectedWithMatcher($this->buildMatcher(), $method_name, ...$args);
  }

  /**
   * Invokes a protected method on a specific matcher instance.
   *
   * @param \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher $matcher
   *   Matcher instance to invoke on.
   * @param string $method_name
   *   Method name.
   * @param mixed ...$args
   *   Method arguments.
   *
   * @return mixed
   *   Method return value.
   */
  private function invokeProtectedWithMatcher(
    ReportSeriesMatcher $matcher,
    string $method_name,
    mixed ...$args,
  ): mixed {
    $method = new \ReflectionMethod(ReportSeriesMatcher::class, $method_name);
    return $method->invoke($matcher, ...$args);
  }

  /**
   * Invokes computeBestClusterLookbackMonths on the matcher.
   *
   * @param int $anchor
   *   Anchor timestamp.
   * @param int[] $cluster_ids
   *   Cluster node IDs.
   * @param array<int, array<string, mixed>> $metadata
   *   Candidate metadata.
   *
   * @return int
   *   Computed lookback months.
   */
  private function computeLookbackMonths(
    int $anchor,
    array $cluster_ids,
    array $metadata,
  ): int {
    return $this->invokeProtected(
      'computeBestClusterLookbackMonths',
      $anchor,
      $cluster_ids,
      $metadata,
    );
  }

  /**
   * Parses an ISO publication date string to a timestamp.
   */
  public function testParseRecencyValueToTimestampIsoDate(): void {
    $actual = $this->invokeProtected(
      'parseRecencyValueToTimestamp',
      '2025-03-15',
    );
    $expected = (new \DateTimeImmutable('2025-03-15', new \DateTimeZone('UTC')))
      ->getTimestamp();

    $this->assertSame($expected, $actual);
  }

  /**
   * Parses a Unix timestamp string from node created metadata.
   */
  public function testParseRecencyValueToTimestampUnixString(): void {
    $this->assertSame(1741996800, $this->invokeProtected(
      'parseRecencyValueToTimestamp',
      '1741996800',
    ));
  }

  /**
   * Invalid recency values return NULL.
   */
  public function testParseRecencyValueToTimestampInvalid(): void {
    $this->assertNull($this->invokeProtected(
      'parseRecencyValueToTimestamp',
      'not-a-date',
    ));
  }

  /**
   * Ceils partial calendar months between oldest cluster date and anchor.
   */
  public function testComputeBestClusterLookbackMonthsCeilsPartialSpan(): void {
    $anchor = (new \DateTimeImmutable('2025-06-01', new \DateTimeZone('UTC')))
      ->getTimestamp();
    $metadata = [
      101 => ['field_original_publication_date' => '2025-05-01'],
      102 => ['field_original_publication_date' => '2025-03-15'],
    ];

    $this->assertSame(
      3,
      $this->computeLookbackMonths($anchor, [101, 102], $metadata),
    );
  }

  /**
   * Lookback works when recency values are Unix timestamps (e.g. created).
   */
  public function testComputeBestClusterLookbackMonthsWithUnixRecencyValues(): void {
    $anchor = (new \DateTimeImmutable('2025-06-01', new \DateTimeZone('UTC')))
      ->getTimestamp();
    $metadata = [
      101 => ['field_original_publication_date' => (string) (new \DateTimeImmutable('2025-05-01', new \DateTimeZone('UTC')))->getTimestamp()],
      102 => ['field_original_publication_date' => (string) (new \DateTimeImmutable('2025-03-15', new \DateTimeZone('UTC')))->getTimestamp()],
    ];

    $this->assertSame(
      3,
      $this->computeLookbackMonths($anchor, [101, 102], $metadata),
    );
  }

  /**
   * Same-day oldest and anchor yield at least one month.
   */
  public function testComputeBestClusterLookbackMonthsSameDayMinimumOne(): void {
    $anchor = (new \DateTimeImmutable('2025-06-01', new \DateTimeZone('UTC')))
      ->getTimestamp();
    $metadata = [
      101 => ['field_original_publication_date' => '2025-06-01'],
    ];

    $this->assertSame(
      1,
      $this->computeLookbackMonths($anchor, [101], $metadata),
    );
  }

  /**
   * Missing publication date falls back to configured search window.
   */
  public function testComputeBestClusterLookbackMonthsMissingDateUsesConfig(): void {
    $anchor = (new \DateTimeImmutable('2025-06-01', new \DateTimeZone('UTC')))
      ->getTimestamp();
    $metadata = [
      101 => ['field_original_publication_date' => '2025-05-01'],
      102 => ['field_original_publication_date' => ''],
    ];
    $matcher = $this->buildMatcher(['series_candidate_date_range_months' => 6]);

    $this->assertSame(
      6,
      $this->invokeProtectedWithMatcher(
        $matcher,
        'computeBestClusterLookbackMonths',
        $anchor,
        [101, 102],
        $metadata,
      ),
    );
  }

  /**
   * Empty cluster falls back to configured search window.
   */
  public function testComputeBestClusterLookbackMonthsEmptyClusterUsesConfig(): void {
    $anchor = (new \DateTimeImmutable('2025-06-01', new \DateTimeZone('UTC')))
      ->getTimestamp();
    $matcher = $this->buildMatcher(['series_candidate_date_range_months' => 6]);

    $this->assertSame(
      6,
      $this->invokeProtectedWithMatcher(
        $matcher,
        'computeBestClusterLookbackMonths',
        $anchor,
        [],
        [],
      ),
    );
  }

  /**
   * Builds candidate metadata for clustering tests.
   *
   * @param string $title
   *   Candidate title.
   * @param string $date
   *   ISO publication date.
   * @param int[] $country
   *   Primary country term IDs.
   * @param int[] $format
   *   Content format term IDs.
   * @param int[] $language
   *   Language term IDs.
   *
   * @return array<string, mixed>
   *   Metadata row.
   */
  private function candidateMetadata(
    string $title,
    string $date,
    array $country = [241],
    array $format = [10],
    array $language = [267],
  ): array {
    return [
      'title' => $title,
      'field_original_publication_date' => $date,
      'created' => $date,
      'field_primary_country' => $country,
      'field_content_format' => $format,
      'field_language' => $language,
    ];
  }

  /**
   * Dissimilar weak matches are not admitted to pad a small high-score core.
   */
  public function testCoreAndSupportRejectsDissimilarWeakCandidates(): void {
    $scored = [
      101 => 4,
      102 => 4,
      201 => 1,
      202 => 1,
      203 => 1,
      204 => 1,
    ];
    $metadata = [
      101 => $this->candidateMetadata('Ukraine Operation Overview, January 2026', '2026-01-15'),
      102 => $this->candidateMetadata('Ukraine Operation Overview, March 2026', '2026-03-15'),
      201 => $this->candidateMetadata('Nigeria Flood Flash Update', '2026-02-01', [999], [11], [268]),
      202 => $this->candidateMetadata('Sahel Market Bulletin', '2026-02-10', [998], [12], [269]),
      203 => $this->candidateMetadata('Yemen Health Weekly', '2026-02-20', [997], [13], [270]),
      204 => $this->candidateMetadata('Sudan Displacement Snapshot', '2026-02-25', [996], [14], [271]),
    ];

    $selection = $this->invokeProtected(
      'selectSeriesCandidatesFromCoreAndSupport',
      $scored,
      $metadata,
    );

    $cluster = $selection['cluster'];
    sort($cluster);
    $this->assertSame([101, 102], $cluster);
    $this->assertCount(2, $cluster);
  }

  /**
   * Similar weak candidates are admitted when the high-score core is too small.
   */
  public function testCoreAndSupportAdmitsSimilarWeakCandidate(): void {
    $scored = [
      101 => 4,
      102 => 4,
      201 => 1,
      301 => 1,
    ];
    $metadata = [
      101 => $this->candidateMetadata('Ukraine Operation Overview, January 2026', '2026-01-15'),
      102 => $this->candidateMetadata('Ukraine Operation Overview, March 2026', '2026-03-15'),
      201 => $this->candidateMetadata('Ukraine Operation Overview, February 2026', '2026-02-15'),
      301 => $this->candidateMetadata('Nigeria Flood Flash Update', '2026-02-01', [999], [11], [268]),
    ];

    $selection = $this->invokeProtected(
      'selectSeriesCandidatesFromCoreAndSupport',
      $scored,
      $metadata,
    );

    $cluster = $selection['cluster'];
    sort($cluster);
    $this->assertSame([101, 102, 201], $cluster);
    $this->assertNotContains(301, $selection['cluster']);
  }

  /**
   * When the core already meets the minimum size, weaker matches are ignored.
   */
  public function testCoreAndSupportDoesNotAddWeakWhenCoreMeetsMinimum(): void {
    $scored = [
      101 => 4,
      102 => 4,
      103 => 4,
      201 => 1,
    ];
    $metadata = [
      101 => $this->candidateMetadata('Ukraine Operation Overview, January 2026', '2026-01-15'),
      102 => $this->candidateMetadata('Ukraine Operation Overview, February 2026', '2026-02-15'),
      103 => $this->candidateMetadata('Ukraine Operation Overview, March 2026', '2026-03-15'),
      201 => $this->candidateMetadata('Ukraine Operation Overview, April 2026', '2026-04-15'),
    ];

    $selection = $this->invokeProtected(
      'selectSeriesCandidatesFromCoreAndSupport',
      $scored,
      $metadata,
    );

    $cluster = $selection['cluster'];
    sort($cluster);
    $this->assertSame([101, 102, 103], $cluster);
    $this->assertNotContains(201, $selection['cluster']);
  }

  /**
   * Support ranking prefers candidates inside the core date span.
   */
  public function testRankSupportCandidatesPrefersInsideCoreSpan(): void {
    $core = [101, 102];
    $support = [301, 201, 302];
    $metadata = [
      101 => $this->candidateMetadata('Core A', '2026-01-15'),
      102 => $this->candidateMetadata('Core B', '2026-03-15'),
      // Inside span.
      201 => $this->candidateMetadata('Inside', '2026-02-15'),
      // Outside span, farther then nearer.
      301 => $this->candidateMetadata('Far before', '2025-06-01'),
      302 => $this->candidateMetadata('Near after', '2026-04-01'),
    ];

    $ranked = $this->invokeProtected(
      'rankSupportCandidatesByProximityToCore',
      $support,
      $core,
      $metadata,
    );

    $this->assertSame([201, 302, 301], $ranked);
  }

  /**
   * Sorts candidates by parsed Unix recency timestamps, newest first.
   */
  public function testSortCandidateIdsByRecencyWithUnixTimestamps(): void {
    $newer = (string) (new \DateTimeImmutable('2025-05-01', new \DateTimeZone('UTC')))->getTimestamp();
    $older = (string) (new \DateTimeImmutable('2025-03-15', new \DateTimeZone('UTC')))->getTimestamp();
    $metadata = [
      101 => ['field_original_publication_date' => $older],
      102 => ['field_original_publication_date' => $newer],
    ];

    $sorted = $this->invokeProtected(
      'sortCandidateIdsByRecency',
      [101, 102],
      $metadata,
    );

    $this->assertSame([102, 101], $sorted);
  }

  /**
   * Keeps original title when AI is skipped for missing attachment text.
   */
  public function testGenerateReportTitleSkipsWhenNoAttachmentText(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasField')->with('field_file')->willReturn(FALSE);

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $matcher = $this->buildMatcher([], NULL, $logger_factory);

    $original_title = 'Annual Review 2026';
    $metadata = [
      101 => ['title' => 'Monthly Update January 2026'],
    ];

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitle',
      $entity,
      $original_title,
      [101],
      $metadata,
    );

    $this->assertSame($original_title, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::SkippedNoAttachmentText, $result['source']);
    $this->assertNull($result['aiDurationSeconds']);
  }

  /**
   * Keeps original title when generation fails for missing candidate titles.
   */
  public function testGenerateReportTitleKeepsOriginalWhenNoCandidateTitles(): void {
    $entity = $this->createMock(ContentEntityInterface::class);

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $matcher = $this->buildMatcher([], NULL, $logger_factory);

    $original_title = 'Annual Review 2026';
    $metadata = [
      101 => ['title' => ''],
    ];

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitle',
      $entity,
      $original_title,
      [101],
      $metadata,
    );

    $this->assertSame($original_title, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::FailedNoCandidateTitles, $result['source']);
    $this->assertNull($result['aiDurationSeconds']);
  }

  /**
   * Dominant stem group is selected and capped by example line count.
   */
  public function testSelectConsistentExampleTitlesPicksDominantStem(): void {
    $titles = [
      "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (03 Mai 2026)",
      "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (26 avril 2026)",
      "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (10 Mai 2026)",
      "UNHCR Tchad | Afflux des Réfugiés du Soudan | Statistiques biométriques (au 12 avril 2026)",
      'UNHCR Tchad Mise à jour des arrivées du Soudan (au 06 avril 2026)',
    ];

    $selected = $this->invokeProtected('selectConsistentExampleTitles', $titles);

    $this->assertNotNull($selected);
    $this->assertCount(3, $selected);
    $this->assertSame($titles[0], $selected[0]);
    $this->assertSame($titles[1], $selected[1]);
    $this->assertSame($titles[2], $selected[2]);
  }

  /**
   * Mixed stems below the minimum return NULL.
   */
  public function testSelectConsistentExampleTitlesReturnsNullWhenInconsistent(): void {
    $titles = [
      'Ukraine Operation Overview (January 2026)',
      'Nigeria Flood Flash Update (February 2026)',
      'Yemen Health Weekly (March 2026)',
      'Sudan Displacement Snapshot (April 2026)',
      'Sahel Market Bulletin (May 2026)',
    ];

    $this->assertNull($this->invokeProtected('selectConsistentExampleTitles', $titles));
  }

  /**
   * Equal-sized stem groups prefer the more recent group.
   */
  public function testSelectConsistentExampleTitlesTieBreaksByRecency(): void {
    $titles = [
      'Ukraine Operation Overview (January 2026)',
      'Ukraine Operation Overview (February 2026)',
      'Nigeria Flood Flash Update (March 2026)',
      'Nigeria Flood Flash Update (April 2026)',
    ];

    $selected = $this->invokeProtectedWithMatcher(
      $this->buildMatcher(['ai_title_min_consistent_examples' => 2]),
      'selectConsistentExampleTitles',
      $titles,
    );

    $this->assertNotNull($selected);
    $this->assertCount(2, $selected);
    $this->assertSame($titles[0], $selected[0]);
    $this->assertSame($titles[1], $selected[1]);
  }

  /**
   * Mixed stems without attachment text skip for missing spans, not stem gate.
   */
  public function testGenerateReportTitleSkipsWhenExamplesInconsistent(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasField')->with('field_file')->willReturn(FALSE);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $matcher = $this->buildMatcher([], NULL, $logger_factory);

    $original_title = 'Some Import Title (01 June 2026)';
    $metadata = [
      101 => ['title' => 'Ukraine Operation Overview (January 2026)'],
      102 => ['title' => 'Nigeria Flood Flash Update (February 2026)'],
      103 => ['title' => 'Yemen Health Weekly (March 2026)'],
      104 => ['title' => 'Sudan Displacement Snapshot (April 2026)'],
    ];

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitle',
      $entity,
      $original_title,
      [101, 102, 103, 104],
      $metadata,
    );

    $this->assertSame($original_title, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::SkippedNoAttachmentText, $result['source']);
  }

  /**
   * Keeps original title when it already matches the dominant example stem.
   */
  public function testGenerateReportTitleKeepsOriginalMatchingDominantStem(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $completion = $this->createMock(CompletionPluginManagerInterface::class);
    $completion->expects($this->never())->method('getPlugin');

    $matcher = $this->buildMatcher([], $completion);

    $original_title = "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (01 Juin 2026)";
    $metadata = [
      101 => ['title' => "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (03 Mai 2026)"],
      102 => ['title' => "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (26 avril 2026)"],
      103 => ['title' => "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (10 Mai 2026)"],
      104 => ['title' => 'UNHCR Tchad | Afflux biométrique (au 12 avril 2026)'],
    ];

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitle',
      $entity,
      $original_title,
      [101, 102, 103, 104],
      $metadata,
    );

    $this->assertSame($original_title, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::KeptOriginalPatternMatch, $result['source']);
  }

  /**
   * Soft-keeps original when one series word differs (Flash near-miss).
   */
  public function testGenerateReportTitleKeepsOriginalOnHighTitleSimilarity(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $completion = $this->createMock(CompletionPluginManagerInterface::class);
    $completion->expects($this->never())->method('getPlugin');

    $matcher = $this->buildMatcher();

    $original_title = 'UNHCR Middle East Situation: Emergency Update #15 as of 29 April 2026';
    $metadata = [
      101 => ['title' => 'UNHCR Middle East Situation: Emergency Flash Update #14 as of 21 April 2026'],
      102 => ['title' => 'UNHCR Middle East Situation: Emergency Flash Update #13 as of 16 April 2026'],
      103 => ['title' => 'UNHCR Middle East Situation: Emergency Flash Update #12 as of 14 April 2026'],
    ];

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitle',
      $entity,
      $original_title,
      [101, 102, 103],
      $metadata,
    );

    $this->assertSame($original_title, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::KeptOriginalPatternMatch, $result['source']);
  }

  /**
   * Boosts SQL pattern scores with title-pattern similarity.
   */
  public function testBoostPatternScoresWithTitleSimilarity(): void {
    $matcher = $this->getMockBuilder(ReportSeriesMatcher::class)
      ->setConstructorArgs([
        $this->buildConfigFactory(),
        $this->createMock(LoggerChannelFactoryInterface::class),
        $this->createMock(EntityFieldManagerInterface::class),
        $this->createMock(TimeInterface::class),
        $this->createMock(Connection::class),
        $this->createMock(CompletionPluginManagerInterface::class),
      ])
      ->onlyMethods(['getOriginalTitles'])
      ->getMock();
    $matcher->method('getOriginalTitles')->willReturn([
      101 => 'UNHCR Middle East Situation: Emergency Flash Update #14 as of 21 April 2026',
      102 => 'UNHCR Middle East Situation: Cross Regional Refugee Coordination Weekly Update (21 April 2026)',
    ]);

    $doc = 'UNHCR Middle East Situation: Emergency Update #15 as of 29 April 2026';
    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'boostPatternScoresWithTitleSimilarity',
      $doc,
      [101 => 3, 102 => 3],
      [
        101 => ['title' => 'UNHCR Middle East Situation: Emergency Flash Update #14 as of 21 April 2026'],
        102 => ['title' => 'UNHCR Middle East Situation: Cross Regional Refugee Coordination Weekly Update (21 April 2026)'],
      ],
    );

    $this->assertGreaterThan($result['scores'][102], $result['scores'][101]);
    $this->assertGreaterThanOrEqual(0.90, $result['similarities'][101]);
    $this->assertLessThan(0.75, $result['similarities'][102]);
    $this->assertEqualsWithDelta(
      3.0 + $result['similarities'][101],
      $result['scores'][101],
      0.0001,
    );
  }

  /**
   * Admits import title as page-0 peer and drops low-similarity PDF regions.
   */
  public function testPrepareAiTitleCandidatesAdmitsImportAndFiltersPdf(): void {
    $series = 'UNHCR Middle East Situation: Emergency Flash Update #14 as of 21 April 2026';
    $import = 'UNHCR Middle East Situation: Emergency Update #15 as of 29 April 2026';
    $candidates = [
      [
        'page' => 1,
        'title_region_text' => "Update\nMiddle East Situation",
        'nearby_date' => '29 April 2026',
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
        'confidence' => 0.88,
      ],
      [
        'page' => 2,
        'title_region_text' => "MIDDLE EAST SITUATION | WEEKLY UPDATE\nKey Figures from the Cross-regional Response as of 26 April 2026",
        'nearby_date' => '26 April 2026',
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
        'confidence' => 0.88,
      ],
    ];

    $ranked = $this->invokeProtected(
      'prepareAiTitleCandidates',
      $candidates,
      $import,
      [$series],
    );

    $this->assertCount(1, $ranked);
    $this->assertSame(0, $ranked[0]['page']);
    $this->assertSame($import, $ranked[0]['title_region_text']);
    $this->assertSame('#15', $ranked[0]['nearby_issue']);
    $this->assertSame('29 April 2026', $ranked[0]['nearby_date']);
  }

  /**
   * Skips AI when required series markers are missing from ranked candidates.
   */
  public function testGenerateReportTitleWithAiSkipsWhenRequiredMarkersMissing(): void {
    $pages = [
      [
        [
          'text' => 'UNHCR Middle East Situation Emergency Flash Update',
          'x' => 10.0,
          'y' => 10.0,
          'w' => 200.0,
          'h' => 16.0,
          'size' => 14.0,
        ],
      ],
    ];
    $matched_title = 'UNHCR Middle East Situation: Emergency Flash Update #14 as of 21 April 2026';
    // Region matches series stem but has no issue/date markers; dissimilar
    // import.
    $candidates = [
      [
        'page' => 1,
        'title_region_text' => 'UNHCR Middle East Situation: Emergency Flash Update',
        'nearby_date' => NULL,
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
        'confidence' => 0.95,
      ],
    ];

    $match_result = [
      'matched_titles' => [$matched_title],
      'candidates' => $candidates,
    ];

    $completion = $this->createMock(CompletionPluginManagerInterface::class);
    $completion->expects($this->never())->method('getPlugin');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $matcher = $this->buildMatcherWithSpans($pages, $match_result, $completion, $logger_factory);
    $entity = $this->createMock(ContentEntityInterface::class);
    $original = 'Completely unrelated portal title without markers';

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitleWithAi',
      $entity,
      $original,
      [$matched_title],
    );

    $this->assertSame($original, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::SkippedInsufficientTitleMarkers, $result['source']);
    $this->assertNull($result['aiDurationSeconds']);
  }

  /**
   * High confidence triggers LLM call with JSON candidates and matched titles.
   */
  public function testGenerateReportTitleWithAiUsesMatchedRegionAndExamples(): void {
    $page_spans = [
      [
        'text' => "SITUATION D'URGENCE AU TCHAD",
        'x' => 72.0,
        'y' => 80.0,
        'w' => 400.0,
        'h' => 18.0,
        'size' => 16.0,
      ],
    ];
    $pages = [$page_spans];
    $matched_title = "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (03 Mai 2026)";
    $region = "SITUATION D'URGENCE AU TCHAD\nMise à jour des arrivées du Soudan";
    $generated = "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (03 Mai 2026)";
    $candidates = [
      [
        'page' => 2,
        'title_region_text' => $region,
        'nearby_date' => '03 Mai 2026',
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
        'confidence' => 0.82,
      ],
      [
        'page' => 1,
        'title_region_text' => "SITUATION D'URGENCE",
        'nearby_date' => NULL,
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
        'confidence' => 0.55,
      ],
    ];
    $expected_prompt_candidates = [
      [
        'page' => 2,
        'title_region_text' => $region,
        'nearby_date' => '03 Mai 2026',
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
      ],
    ];
    $expected_prompt = json_encode(
      ['candidates' => $expected_prompt_candidates],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );

    $match_result = [
      'matched_titles' => [$matched_title],
      'candidates' => $candidates,
    ];

    $plugin = $this->createMock(CompletionPluginBase::class);
    $plugin->method('hasCapability')
      ->with(CompletionCapability::StructuredOutput)
      ->willReturn(TRUE);
    $plugin->expects($this->once())
      ->method('queryStructured')
      ->with(
        $this->callback(static function (string $prompt) use ($expected_prompt): bool {
          return $prompt === $expected_prompt;
        }),
        $this->callback(static function (array $schema) use ($matched_title): bool {
          $description = $schema['properties']['title']['description'] ?? '';
          return str_contains($description, $matched_title)
            && !str_contains($description, 'Afflux');
        }),
        $this->anything(),
        $this->anything(),
      )
      ->willReturn(['title' => $generated]);

    $completion = $this->createMock(CompletionPluginManagerInterface::class);
    $completion->method('getPlugin')->willReturn($plugin);

    $matcher = $this->buildMatcherWithSpans($pages, $match_result, $completion);
    $entity = $this->createMock(ContentEntityInterface::class);

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitleWithAi',
      $entity,
      'Import title without series style',
      [
        $matched_title,
        'UNHCR Tchad | Afflux des Réfugiés du Soudan | Statistiques biométriques (au 12 avril 2026)',
      ],
    );

    $this->assertSame($generated, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::AiGenerated, $result['source']);
    $this->assertNotNull($result['aiDurationSeconds']);
  }

  /**
   * AI titles that ignore the series naming stem are rejected.
   */
  public function testGenerateReportTitleWithAiRejectsSeriesPatternMismatch(): void {
    $pages = [
      [
        [
          'text' => 'OPERATIONAL UPDATE – SYRIA',
          'x' => 10.0,
          'y' => 10.0,
          'w' => 200.0,
          'h' => 16.0,
          'size' => 14.0,
        ],
      ],
    ];
    $matched_title = 'UNHCR Syria Operational Update, December 2025';
    $candidates = [
      [
        'page' => 1,
        'title_region_text' => "UNHCR Syria Operational Update\nFebruary 2026",
        'nearby_date' => 'February 2026',
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
        'confidence' => 0.9,
      ],
    ];
    $pdf_verbatim = 'OPERATIONAL UPDATE / SYRIA / FEBRUARY 2026';

    $match_result = [
      'matched_titles' => [$matched_title],
      'candidates' => $candidates,
    ];

    $plugin = $this->createMock(CompletionPluginBase::class);
    $plugin->method('hasCapability')
      ->with(CompletionCapability::StructuredOutput)
      ->willReturn(TRUE);
    $plugin->expects($this->once())
      ->method('queryStructured')
      ->willReturn(['title' => $pdf_verbatim]);

    $completion = $this->createMock(CompletionPluginManagerInterface::class);
    $completion->method('getPlugin')->willReturn($plugin);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $matcher = $this->buildMatcherWithSpans($pages, $match_result, $completion, $logger_factory);
    $entity = $this->createMock(ContentEntityInterface::class);
    $original = 'Import title without series style';

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitleWithAi',
      $entity,
      $original,
      [$matched_title],
    );

    $this->assertSame($original, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::FailedSeriesPatternMismatch, $result['source']);
    $this->assertNotNull($result['aiDurationSeconds']);
  }

  /**
   * Low helper confidence skips AI and does not call the completion plugin.
   */
  public function testGenerateReportTitleWithAiSkipsOnLowMatchConfidence(): void {
    $pages = [
      [
        [
          'text' => 'Unrelated header',
          'x' => 10.0,
          'y' => 10.0,
          'w' => 100.0,
          'h' => 12.0,
          'size' => 10.0,
        ],
      ],
    ];

    $completion = $this->createMock(CompletionPluginManagerInterface::class);
    $completion->expects($this->never())->method('getPlugin');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $matcher = $this->buildMatcherWithSpans($pages, NULL, $completion, $logger_factory);
    $entity = $this->createMock(ContentEntityInterface::class);
    $original = 'Import title (01 June 2026)';

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'generateReportTitleWithAi',
      $entity,
      $original,
      ['Ukraine Operation Overview (January 2026)'],
    );

    $this->assertSame($original, $result['title']);
    $this->assertSame(SeriesMatchTitleSource::SkippedLowTitleMatchConfidence, $result['source']);
    $this->assertNull($result['aiDurationSeconds']);
  }

  /**
   * Grounding corpus includes markers from secondary candidates.
   */
  public function testBuildAiTitleGroundingSourceIncludesSecondaryCandidateMarkers(): void {
    $candidates = [
      [
        'page' => 2,
        'title_region_text' => 'Syrian Arab Republic',
        'nearby_date' => NULL,
        'nearby_issue' => NULL,
        'nearby_week' => NULL,
        'confidence' => 0.8,
      ],
      [
        'page' => 1,
        'title_region_text' => 'OPERATIONAL UPDATE – SYRIA',
        'nearby_date' => 'February 2026',
        'nearby_issue' => 'Issue 12',
        'nearby_week' => NULL,
        'confidence' => 0.5,
      ],
    ];

    $grounding = $this->invokeProtected('buildAiTitleGroundingSource', $candidates);

    $this->assertStringContainsString('Syrian Arab Republic', $grounding);
    $this->assertStringContainsString('OPERATIONAL UPDATE – SYRIA', $grounding);
    $this->assertStringContainsString('February 2026', $grounding);
    $this->assertStringContainsString('Issue 12', $grounding);
  }

  /**
   * Accepts reformatted series stems, rejects verbatim PDF titles.
   */
  public function testGeneratedTitleMatchesSeriesPattern(): void {
    $matched = ['UNHCR Syria Operational Update, December 2025'];

    $this->assertTrue(
      $this->invokeProtected(
        'generatedTitleMatchesSeriesPattern',
        'UNHCR Syria Operational Update, February 2026',
        $matched,
      ),
    );
    $this->assertFalse(
      $this->invokeProtected(
        'generatedTitleMatchesSeriesPattern',
        'OPERATIONAL UPDATE / SYRIA / FEBRUARY 2026',
        $matched,
      ),
    );
  }

  /**
   * Rejects AI titles whose date markers are absent from the source text.
   */
  public function testGeneratedTitleMarkersAreGroundedRejectsHallucinatedDate(): void {
    $title = "SITUATION D'URGENCE AU TCHAD | Mise à jour des arrivées du Soudan (au 11 Mai 2026)";
    $source = "SITUATION D'URGENCE AU TCHAD\nMise à jour des arrivées du Soudan\nDepuis le début du conflit";

    $this->assertFalse(
      $this->invokeProtected('generatedTitleMarkersAreGrounded', $title, $source),
    );
  }

  /**
   * Accepts AI titles when date markers appear in the source text.
   */
  public function testGeneratedTitleMarkersAreGroundedAcceptsDateInSource(): void {
    $title = "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (11 Mai 2026)";
    $source = "SITUATION D'URGENCE AU TCHAD\nMise à jour des arrivées du Soudan\nDonnées au 11 Mai 2026";

    $this->assertTrue(
      $this->invokeProtected('generatedTitleMarkersAreGrounded', $title, $source),
    );
  }

  /**
   * Keeps full retrieval scores while candidateIds lists only the selected set.
   */
  public function testBuildCandidateEvidenceFromSelectionKeepsDiscardedScores(): void {
    $retrieved = [
      101 => 5,
      102 => 5,
      103 => 5,
      104 => 5,
      201 => 3,
      202 => 2,
      203 => 2,
      204 => 1,
      205 => 1,
    ];
    $selected = [101, 102, 103, 104];

    $evidence = $this->invokeProtected(
      'buildCandidateEvidenceFromSelection',
      $retrieved,
      $selected,
    );

    $this->assertSame([101, 102, 103, 104], $evidence['candidateIds']);
    $this->assertSame(
      [101, 102, 103, 104, 201, 202, 203, 204, 205],
      array_keys($evidence['candidatePatternScores']),
    );
    $this->assertSame(3, $evidence['candidatePatternScores'][201]);
    $this->assertCount(9, $evidence['candidatePatternScores']);
  }

  /**
   * Weights cluster share by pattern score (strong core + weak noise).
   */
  public function testComputeBestClusterShareWeightsByPatternScore(): void {
    $retrieved = [
      101 => 5,
      102 => 5,
      103 => 5,
      104 => 5,
      201 => 1,
      202 => 1,
      203 => 1,
      204 => 1,
      205 => 1,
    ];

    $share = $this->invokeProtected(
      'computeBestClusterShare',
      $retrieved,
      [101, 102, 103, 104],
    );

    $this->assertEqualsWithDelta(0.8, $share, 0.0001);
  }

  /**
   * Returns 1.0 when every retrieved candidate is selected.
   */
  public function testComputeBestClusterShareAllSelected(): void {
    $retrieved = [
      101 => 5,
      102 => 3,
    ];

    $share = $this->invokeProtected(
      'computeBestClusterShare',
      $retrieved,
      [101, 102],
    );

    $this->assertEqualsWithDelta(1.0, $share, 0.0001);
  }

  /**
   * Returns 0.0 when there are no scores to weight.
   */
  public function testComputeBestClusterShareEmptyOrZeroTotal(): void {
    $this->assertSame(0.0, $this->invokeProtected(
      'computeBestClusterShare',
      [],
      [101],
    ));
    $this->assertSame(0.0, $this->invokeProtected(
      'computeBestClusterShare',
      [101 => 0, 102 => 0],
      [101],
    ));
  }

  /**
   * Normalizes disaster types: unique, sorted, Complex Emergency excluded.
   */
  public function testNormalizeDisasterTypeIdsExcludesComplexEmergencyAndSorts(): void {
    $normalized = $this->invokeProtected(
      'normalizeDisasterTypeIds',
      [
        4616,
        ReportSeriesMatcher::COMPLEX_EMERGENCY_DISASTER_TYPE_ID,
        4604,
        4616,
      ],
    );

    $this->assertSame([4604, 4616], $normalized);
  }

  /**
   * Keeps series-copied disaster types when no disasters are proposed.
   */
  public function testApplyDisasterTypeDerivationKeepsSeriesTypesWithoutDisasters(): void {
    $values = [
      'field_disaster' => [],
      'field_disaster_type' => [4604, 4616],
    ];
    $sources = [
      'field_disaster' => SeriesMatchFieldUpdateSource::Skipped,
      'field_disaster_type' => SeriesMatchFieldUpdateSource::AllCandidates,
    ];

    $result = $this->invokeProtected(
      'applyDisasterTypeDerivation',
      $values,
      $sources,
    );

    $this->assertSame([4604, 4616], $result['values']['field_disaster_type']);
    $this->assertSame(
      SeriesMatchFieldUpdateSource::AllCandidates,
      $result['sources']['field_disaster_type'],
    );
  }

  /**
   * Derives types from proposed disasters and ignores series type values.
   */
  public function testApplyDisasterTypeDerivationUsesDisasterUnionNotSeriesTypes(): void {
    $matcher = $this->buildMatcherWithDisasterTypeRows([
      (object) ['entity_id' => 100, 'field_disaster_type_target_id' => 4616],
      (object) [
        'entity_id' => 100,
        'field_disaster_type_target_id' => ReportSeriesMatcher::COMPLEX_EMERGENCY_DISASTER_TYPE_ID,
      ],
      (object) ['entity_id' => 200, 'field_disaster_type_target_id' => 4604],
      (object) ['entity_id' => 200, 'field_disaster_type_target_id' => 4616],
    ]);

    $values = [
      'field_disaster' => [200, 100],
      'field_disaster_type' => [9999],
    ];
    $sources = [
      'field_disaster' => SeriesMatchFieldUpdateSource::Merged,
      'field_disaster_type' => SeriesMatchFieldUpdateSource::MostRecent,
    ];

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'applyDisasterTypeDerivation',
      $values,
      $sources,
    );

    $this->assertSame([4604, 4616], $result['values']['field_disaster_type']);
    $this->assertSame(
      SeriesMatchFieldUpdateSource::Merged,
      $result['sources']['field_disaster_type'],
    );
  }

  /**
   * Empty types from disasters do not fall back to series disaster types.
   */
  public function testApplyDisasterTypeDerivationNoFallbackWhenDisastersHaveNoTypes(): void {
    $matcher = $this->buildMatcherWithDisasterTypeRows([]);

    $values = [
      'field_disaster' => [100],
      'field_disaster_type' => [4604],
    ];
    $sources = [
      'field_disaster' => SeriesMatchFieldUpdateSource::MostRecent,
      'field_disaster_type' => SeriesMatchFieldUpdateSource::AllCandidates,
    ];

    $result = $this->invokeProtectedWithMatcher(
      $matcher,
      'applyDisasterTypeDerivation',
      $values,
      $sources,
    );

    $this->assertSame([], $result['values']['field_disaster_type']);
    $this->assertSame(
      SeriesMatchFieldUpdateSource::MostRecent,
      $result['sources']['field_disaster_type'],
    );
  }

  /**
   * Builds a matcher whose DB returns the given disaster-type rows.
   *
   * @param list<object{entity_id: int, field_disaster_type_target_id: int}> $rows
   *   Rows from taxonomy_term__field_disaster_type.
   *
   * @return \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher
   *   Matcher with a stubbed select query.
   */
  private function buildMatcherWithDisasterTypeRows(array $rows): ReportSeriesMatcher {
    $query = $this->createMock(SelectInterface::class);
    $query->method('fields')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('orderBy')->willReturnSelf();
    $query->method('execute')->willReturn($rows);

    $database = $this->createMock(Connection::class);
    $database->method('select')
      ->with('taxonomy_term__field_disaster_type', 'f')
      ->willReturn($query);

    return new ReportSeriesMatcher(
      $this->buildConfigFactory(),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $database,
      $this->createMock(CompletionPluginManagerInterface::class),
    );
  }

}
