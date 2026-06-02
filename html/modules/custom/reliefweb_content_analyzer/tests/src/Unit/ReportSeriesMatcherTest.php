<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcher;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchMatcherConfigFixture;
use Drupal\Tests\UnitTestCase;
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

}
