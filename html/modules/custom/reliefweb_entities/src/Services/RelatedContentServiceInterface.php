<?php

declare(strict_types=1);

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Entity\EntityInterface;

/**
 * Builds related report content for document entities.
 */
interface RelatedContentServiceInterface {

  /**
   * Get reports related to the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The document entity (report, job, training, etc.).
   * @param int $limit
   *   Maximum number of related reports to return.
   *
   * @return array
   *   Render array for the related reports river.
   */
  public function getRelatedContent(EntityInterface $entity, int $limit = 4): array;

}
