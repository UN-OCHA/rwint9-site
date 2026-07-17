<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher;
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
   *
   * @return \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher
   *   Matcher with stubbed dependencies.
   */
  private function buildMatcher(array $matcher_overrides = []): ReportSeriesMatcher {
    return new ReportSeriesMatcher(
      $this->buildConfigFactory($matcher_overrides),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(CompletionPluginManagerInterface::class),
    );
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

    $matcher = new ReportSeriesMatcher(
      $this->buildConfigFactory(),
      $logger_factory,
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(CompletionPluginManagerInterface::class),
    );

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

    $matcher = new ReportSeriesMatcher(
      $this->buildConfigFactory(),
      $logger_factory,
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(CompletionPluginManagerInterface::class),
    );

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

}
