<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_api\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_api\Indexing\ReliefWebApiIndexingSkipStore;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ReliefWebApiIndexingSkipStore mark/consume behavior.
 */
#[CoversClass(ReliefWebApiIndexingSkipStore::class)]
#[Group('reliefweb_api')]
class ReliefWebApiIndexingSkipStoreTest extends UnitTestCase {

  /**
   * Consume returns FALSE when no skip was marked.
   */
  public function testConsumeReturnsFalseWhenAbsent(): void {
    $entity = $this->createMock(EntityInterface::class);

    $this->assertFalse(ReliefWebApiIndexingSkipStore::consumeSkip($entity));
  }

  /**
   * Mark then consume returns TRUE once and clears the flag.
   */
  public function testMarkAndConsumeSkipOnce(): void {
    $entity = $this->createMock(EntityInterface::class);

    ReliefWebApiIndexingSkipStore::markSkip($entity);

    $this->assertTrue(ReliefWebApiIndexingSkipStore::consumeSkip($entity));
    $this->assertFalse(ReliefWebApiIndexingSkipStore::consumeSkip($entity));
  }

  /**
   * Skip flags are keyed by entity object identity.
   */
  public function testSkipIsPerEntityInstance(): void {
    $entity_a = $this->createMock(EntityInterface::class);
    $entity_b = $this->createMock(EntityInterface::class);

    ReliefWebApiIndexingSkipStore::markSkip($entity_a);

    $this->assertTrue(ReliefWebApiIndexingSkipStore::consumeSkip($entity_a));
    $this->assertFalse(ReliefWebApiIndexingSkipStore::consumeSkip($entity_b));
  }

}
