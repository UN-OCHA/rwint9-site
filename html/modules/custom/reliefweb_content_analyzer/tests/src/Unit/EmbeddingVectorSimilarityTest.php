<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\Helpers\EmbeddingVectorSimilarity;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests EmbeddingVectorSimilarity.
 */
#[CoversClass(EmbeddingVectorSimilarity::class)]
#[Group('reliefweb_content_analyzer')]
class EmbeddingVectorSimilarityTest extends UnitTestCase {

  /**
   * Identical unit vectors have cosine 1.
   */
  public function testIdentical(): void {
    $v = [1.0, 0.0, 0.0];
    $this->assertEqualsWithDelta(1.0, EmbeddingVectorSimilarity::cosine($v, $v), 1e-9);
  }

  /**
   * Orthogonal vectors have cosine 0.
   */
  public function testOrthogonal(): void {
    $this->assertEqualsWithDelta(0.0, EmbeddingVectorSimilarity::cosine(
      [1.0, 0.0],
      [0.0, 1.0],
    ), 1e-9);
  }

  /**
   * Length mismatch returns NULL.
   */
  public function testLengthMismatch(): void {
    $this->assertNull(EmbeddingVectorSimilarity::cosine([1.0], [1.0, 0.0]));
  }

  /**
   * Empty vectors return NULL.
   */
  public function testEmpty(): void {
    $this->assertNull(EmbeddingVectorSimilarity::cosine([], []));
  }

  /**
   * Zero vector returns NULL.
   */
  public function testZeroMagnitude(): void {
    $this->assertNull(EmbeddingVectorSimilarity::cosine([0.0, 0.0], [1.0, 0.0]));
  }

}
