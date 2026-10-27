<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_ai\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\reliefweb_ai\Service\ReportLanguageDetectionDoneStore;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the per-request language detection done store.
 */
#[CoversClass(ReportLanguageDetectionDoneStore::class)]
#[Group('reliefweb_ai')]
class ReportLanguageDetectionDoneStoreTest extends UnitTestCase {

  /**
   * Test mark and isDone for the same entity object.
   */
  public function testMarkAndIsDone(): void {
    $entity = $this->createMock(ContentEntityInterface::class);

    $this->assertFalse(ReportLanguageDetectionDoneStore::isDone($entity));
    ReportLanguageDetectionDoneStore::mark($entity);
    $this->assertTrue(ReportLanguageDetectionDoneStore::isDone($entity));
  }

  /**
   * Test different entity objects are tracked independently.
   */
  public function testDifferentEntitiesAreIndependent(): void {
    $entity_a = $this->createMock(ContentEntityInterface::class);
    $entity_b = $this->createMock(ContentEntityInterface::class);

    ReportLanguageDetectionDoneStore::mark($entity_a);

    $this->assertTrue(ReportLanguageDetectionDoneStore::isDone($entity_a));
    $this->assertFalse(ReportLanguageDetectionDoneStore::isDone($entity_b));
  }

}
