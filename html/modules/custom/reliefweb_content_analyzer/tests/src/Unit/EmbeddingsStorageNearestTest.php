<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestHit;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestQuery;
use Drupal\reliefweb_content_analyzer\Services\MariaDbEmbeddingsStorage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests nearest-neighbor DTOs and MariaDB storage validation.
 */
#[CoversClass(EmbeddingNearestQuery::class)]
#[CoversClass(EmbeddingNearestHit::class)]
#[CoversClass(MariaDbEmbeddingsStorage::class)]
class EmbeddingsStorageNearestTest extends UnitTestCase {

  /**
   * Query DTO stores optional filters.
   */
  public function testNearestQueryDefaults(): void {
    $query = new EmbeddingNearestQuery('node', [0.1, 0.2], 5);
    $this->assertSame('node', $query->entityTypeId);
    $this->assertSame([0.1, 0.2], $query->query);
    $this->assertSame(5, $query->limit);
    $this->assertNull($query->bundle);
    $this->assertNull($query->excludeEntityId);
    $this->assertNull($query->entityIdMin);
    $this->assertNull($query->entityIdMax);
    $this->assertNull($query->minSimilarity);
  }

  /**
   * Hit DTO exposes entity id and similarity.
   */
  public function testNearestHit(): void {
    $hit = new EmbeddingNearestHit(42, 0.91);
    $this->assertSame(42, $hit->entityId);
    $this->assertSame(0.91, $hit->similarity);
  }

  /**
   * Missing table: loadVector returns NULL, findNearest returns [].
   */
  public function testSoftBehaviorWhenTableMissing(): void {
    $storage = $this->storageWithTable(FALSE);
    $this->assertNull($storage->loadVector('node', 1));
    $this->assertSame([], $storage->findNearest(new EmbeddingNearestQuery(
      'node',
      [0.1, 0.2],
      10,
    )));
  }

  /**
   * Empty query vector is rejected before querying.
   */
  public function testFindNearestRejectsEmptyVector(): void {
    $storage = $this->storageWithTable(TRUE);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Embedding vector must not be empty.');
    $storage->findNearest(new EmbeddingNearestQuery('node', [], 10));
  }

  /**
   * Non-numeric query vector values are rejected.
   */
  public function testFindNearestRejectsNonNumericVector(): void {
    $storage = $this->storageWithTable(TRUE);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Embedding vector contains a non-numeric value.');
    // @phpstan-ignore-next-line intentional invalid fixture
    $storage->findNearest(new EmbeddingNearestQuery('node', ['bad'], 10));
  }

  /**
   * Build storage whose schema reports table existence.
   *
   * @param bool $exists
   *   Whether the embeddings table exists.
   *
   * @return \Drupal\reliefweb_content_analyzer\Services\MariaDbEmbeddingsStorage
   *   Storage under test.
   */
  private function storageWithTable(bool $exists): MariaDbEmbeddingsStorage {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn($exists);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->expects($this->never())->method('query');
    return new MariaDbEmbeddingsStorage($database);
  }

}
