<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DuplicateMatchResult hard/soft helpers.
 */
#[CoversClass(DuplicateMatchResult::class)]
#[Group('reliefweb_content_analyzer')]
class DuplicateMatchResultTest extends UnitTestCase {

  /**
   * Hard Jaccard matches win the target method over soft embedding.
   */
  public function testHardWinsTargetMethod(): void {
    $result = new DuplicateMatchResult(matches: [
      new DuplicateMatch(1, 'Soft', 0.91, '/node/1', DuplicateMatch::METHOD_EMBEDDING),
      new DuplicateMatch(2, 'Hard', 0.95, '/node/2', DuplicateMatch::METHOD_JACCARD),
    ], reason: 'matched');

    $this->assertTrue($result->hasHardMatches());
    $this->assertSame(DuplicateMatch::METHOD_JACCARD, $result->targetMethod());
  }

  /**
   * Soft-only results target embedding confirmation.
   */
  public function testSoftOnlyTargetMethod(): void {
    $result = new DuplicateMatchResult(matches: [
      new DuplicateMatch(1, 'Soft', 0.91, '/node/1', DuplicateMatch::METHOD_EMBEDDING),
    ], reason: 'matched');

    $this->assertFalse($result->hasHardMatches());
    $this->assertSame(DuplicateMatch::METHOD_EMBEDDING, $result->targetMethod());
  }

  /**
   * Empty results have no target method.
   */
  public function testEmpty(): void {
    $result = new DuplicateMatchResult(reason: 'no_matches');
    $this->assertFalse($result->hasMatches());
    $this->assertFalse($result->hasCandidates());
    $this->assertNull($result->targetMethod());
    $this->assertSame(0, $result->duplicateCandidateCount());
  }

  /**
   * Candidates list can exist without threshold-passing matches.
   */
  public function testCandidatesWithoutMatches(): void {
    $result = new DuplicateMatchResult(
      matches: [],
      reason: 'no_matches',
      candidates: [
        new DuplicateMatchCandidate(
          nid: 1,
          title: 'A',
          url: '/node/1',
          created: 1,
          lengthRatio: 0.9,
          jaccardScore: 0.2,
          tfidfScore: 0.3,
        ),
        new DuplicateMatchCandidate(
          nid: 2,
          title: 'B',
          url: '/node/2',
          created: 2,
          lengthRatio: 0.9,
          jaccardScore: 0.95,
          tfidfScore: 0.96,
          isDuplicate: TRUE,
          method: DuplicateMatch::METHOD_JACCARD,
        ),
      ],
    );

    $this->assertFalse($result->hasMatches());
    $this->assertTrue($result->hasCandidates());
    $this->assertSame(1, $result->duplicateCandidateCount());
  }

}
