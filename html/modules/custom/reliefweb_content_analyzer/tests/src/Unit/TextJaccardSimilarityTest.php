<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\Helpers\TextJaccardSimilarity;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests word n-gram Jaccard similarity helpers.
 */
#[CoversClass(TextJaccardSimilarity::class)]
#[Group('reliefweb_content_analyzer')]
class TextJaccardSimilarityTest extends UnitTestCase {

  /**
   * Identical texts score 1.0.
   */
  public function testIdentical(): void {
    $text = 'the situation continues to deteriorate in affected areas';
    $this->assertSame(1.0, TextJaccardSimilarity::similarity($text, $text));
  }

  /**
   * Disjoint texts score near 0.
   */
  public function testDifferent(): void {
    $a = 'alpha beta gamma delta epsilon zeta eta theta';
    $b = 'one two three four five six seven eight';
    $this->assertLessThan(0.1, TextJaccardSimilarity::similarity($a, $b));
  }

  /**
   * Near-duplicate with small edit stays high.
   */
  public function testNearDuplicate(): void {
    $a = 'humanitarian needs continue to increase among displaced people food insecurity and health services remain limited in conflict affected areas partners response is ongoing';
    $b = 'humanitarian needs continue to increase among displaced people food insecurity and health services remain limited in conflict affected areas partners response is ongoing assessment findings';
    $score = TextJaccardSimilarity::similarity($a, $b);
    $this->assertGreaterThan(0.7, $score);
    $this->assertLessThan(1.0, $score);
  }

  /**
   * Length ratio gates short vs long texts.
   */
  public function testLengthRatio(): void {
    $this->assertSame(1.0, TextJaccardSimilarity::lengthRatio('abc', 'abc'));
    $this->assertEqualsWithDelta(0.5, TextJaccardSimilarity::lengthRatio('ab', 'abcd'), 0.001);
    $this->assertSame(1.0, TextJaccardSimilarity::lengthRatio('', ''));
  }

  /**
   * Short texts still produce a shingle set.
   */
  public function testShortTextShingles(): void {
    $set = TextJaccardSimilarity::wordShingles('one two', 3);
    $this->assertArrayHasKey('one two', $set);
  }

}
