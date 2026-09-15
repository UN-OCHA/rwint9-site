<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_revisions\Unit;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Smoke tests that EntityRevisionedTrait wires RevisionLogHelper to the field.
 */
#[CoversClass(EntityRevisionedTrait::class)]
class EntityRevisionedTraitTest extends UnitTestCase {

  /**
   * Creates a stub entity that uses the revisioned trait.
   */
  protected function createStubEntity(string $initial_log = ''): object {
    $entity_type = $this->createMock(ContentEntityTypeInterface::class);
    $entity_type->method('getRevisionMetadataKey')
      ->with('revision_log_message')
      ->willReturn('revision_log');

    return new class($entity_type, $initial_log) {
      use EntityRevisionedTrait;

      /**
       * Revision log field value object.
       *
       * @var object
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName -- mirrors entity field name.
      public object $revision_log;

      /**
       * Entity type.
       *
       * @var \Drupal\Core\Entity\EntityTypeInterface
       */
      private EntityTypeInterface $entityType;

      /**
       * Constructs a new entity.
       *
       * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
       *   The entity type.
       * @param string $initial_log
       *   The initial revision log message.
       */
      public function __construct(EntityTypeInterface $entity_type, string $initial_log) {
        $this->entityType = $entity_type;
        $this->revision_log = (object) ['value' => $initial_log];
      }

      /**
       * Returns the entity type.
       *
       * @return \Drupal\Core\Entity\EntityTypeInterface
       *   The entity type.
       */
      public function getEntityType(): EntityTypeInterface {
        return $this->entityType;
      }

      /**
       * Returns the revision log message.
       *
       * @return string
       *   The revision log message.
       */
      public function getRevisionLogMessage(): string {
        return (string) ($this->revision_log->value ?? '');
      }

    };
  }

  /**
   * Trait reads the field, applies the helper, and writes the result back.
   */
  public function testUpdateRevisionLogMessageWritesField(): void {
    $entity = $this->createStubEntity('Existing.');
    $entity->updateRevisionLogMessage('Added.', 'append');
    $this->assertSame('Existing. Added.', $entity->getRevisionLogMessage());
  }

  /**
   * Trait no-ops when the entity type has no revision log metadata key.
   */
  public function testUpdateRevisionLogMessageNoOpWithoutField(): void {
    $entity_type = $this->createMock(ContentEntityTypeInterface::class);
    $entity_type->method('getRevisionMetadataKey')
      ->with('revision_log_message')
      ->willReturn(NULL);

    $entity = new class($entity_type) {
      use EntityRevisionedTrait;

      /**
       * Entity type.
       *
       * @var \Drupal\Core\Entity\EntityTypeInterface
       */
      private EntityTypeInterface $entityType;

      /**
       * Constructs a new entity.
       *
       * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
       *   The entity type.
       */
      public function __construct(EntityTypeInterface $entity_type) {
        $this->entityType = $entity_type;
      }

      /**
       * Returns the entity type.
       *
       * @return \Drupal\Core\Entity\EntityTypeInterface
       *   The entity type.
       */
      public function getEntityType(): EntityTypeInterface {
        return $this->entityType;
      }

    };

    $entity->updateRevisionLogMessage('Should not crash.', 'append');
    $this->assertTrue(TRUE);
  }

}
