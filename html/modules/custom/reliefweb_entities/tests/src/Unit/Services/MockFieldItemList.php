<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\Unit\Services;

/**
 * Minimal iterable field list for RelatedContentService tests.
 */
final class MockFieldItemList implements \IteratorAggregate {

  /**
   * Constructs a MockFieldItemList object.
   *
   * @param bool $empty
   *   Whether the field is empty.
   * @param array $items
   *   Reference field items with target_id properties.
   * @param mixed $value
   *   Scalar field value.
   * @param array $referenced_entities
   *   Referenced entities for country fields on jobs.
   */
  public function __construct(
    public readonly bool $empty,
    public readonly array $items = [],
    public mixed $value = NULL,
    public readonly array $referenced_entities = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return $this->empty;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Traversable {
    return new \ArrayIterator($this->items);
  }

  /**
   * Returns referenced entities.
   *
   * @return array
   *   Referenced entities.
   */
  public function referencedEntities(): array {
    return $this->referenced_entities;
  }

}
