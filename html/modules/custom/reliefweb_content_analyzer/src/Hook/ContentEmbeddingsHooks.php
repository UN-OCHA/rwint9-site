<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\reliefweb_content_analyzer\Services\EmbeddingsStorageInterface;

/**
 * Keeps stored embeddings in sync with entity deletions.
 */
final class ContentEmbeddingsHooks {

  /**
   * Constructs ContentEmbeddingsHooks.
   *
   * @param \Drupal\reliefweb_content_analyzer\Services\EmbeddingsStorageInterface $storage
   *   Embeddings storage.
   */
  public function __construct(
    protected readonly EmbeddingsStorageInterface $storage,
  ) {}

  /**
   * Remove stored embedding when an entity is deleted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Deleted entity.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $id = $entity->id();
    if ($id === NULL || $id === '') {
      return;
    }
    $this->storage->delete($entity->getEntityTypeId(), (int) $id);
  }

}
