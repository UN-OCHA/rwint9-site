<?php

namespace Drupal\reliefweb_revisions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller to display an entity's history.
 */
class EntityHistory extends ControllerBase {

  /**
   * Get an entity's revision history.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $entity
   *   Entity for which to retrieve the history.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the entity revision history.
   */
  public function view($entity_type_id, EntityRevisionedInterface $entity) {
    return new JsonResponse($entity->getHistory());
  }

}
