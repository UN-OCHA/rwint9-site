<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\Unit\Entity;

use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for report published-or-embargoed status detection.
 */
#[CoversClass(Report::class)]
#[Group('reliefweb_entities')]
class ReportEmptyContentTest extends UnitTestCase {

  /**
   * Tests isPublishedOrEmbargoed for each status.
   *
   * @param string $status
   *   Moderation status to check.
   * @param bool $expected
   *   Expected result.
   */
  #[DataProvider('publishedOrEmbargoedProvider')]
  public function testIsPublishedOrEmbargoed(string $status, bool $expected): void {
    $moderation = $this->createMock(ModerationServiceInterface::class);
    $moderation->method('getPublishedStatuses')
      ->willReturn(['to-review', 'published']);

    $report = $this->getMockBuilder(Report::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getModerationService', 'getModerationStatus'])
      ->getMock();
    $report->method('getModerationService')->willReturn($moderation);
    $report->method('getModerationStatus')->willReturn('draft');

    $this->assertSame($expected, $report->isPublishedOrEmbargoed($status));
  }

  /**
   * Published-or-embargoed status cases.
   *
   * @return array<string, array{0: string, 1: bool}>
   *   Test cases.
   */
  public static function publishedOrEmbargoedProvider(): array {
    return [
      'published' => ['published', TRUE],
      'to-review' => ['to-review', TRUE],
      'embargoed' => ['embargoed', TRUE],
      'draft' => ['draft', FALSE],
      'pending' => ['pending', FALSE],
      'on-hold' => ['on-hold', FALSE],
    ];
  }

  /**
   * Defaults to the entity moderation status when none is passed.
   */
  public function testIsPublishedOrEmbargoedDefaultsToEntityStatus(): void {
    $moderation = $this->createMock(ModerationServiceInterface::class);
    $moderation->method('getPublishedStatuses')
      ->willReturn(['to-review', 'published']);

    $report = $this->getMockBuilder(Report::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getModerationService', 'getModerationStatus'])
      ->getMock();
    $report->method('getModerationService')->willReturn($moderation);
    $report->method('getModerationStatus')->willReturn('published');

    $this->assertTrue($report->isPublishedOrEmbargoed());
  }

  /**
   * Falls back when the moderation service is unavailable.
   */
  public function testIsPublishedOrEmbargoedWithoutModerationService(): void {
    $report = $this->getMockBuilder(Report::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getModerationService', 'getModerationStatus'])
      ->getMock();
    $report->method('getModerationService')->willReturn(NULL);
    $report->method('getModerationStatus')->willReturn('draft');

    $this->assertTrue($report->isPublishedOrEmbargoed('published'));
    $this->assertTrue($report->isPublishedOrEmbargoed('embargoed'));
    $this->assertFalse($report->isPublishedOrEmbargoed('draft'));
  }

}
