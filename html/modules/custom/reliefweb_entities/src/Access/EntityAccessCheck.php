<?php

namespace Drupal\reliefweb_entities\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Check access to an entity page.
 */
class EntityAccessCheck implements AccessInterface {

  /**
   * Check access to the entity page.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match) {
    if ($route_match->getRouteName() === 'entity.taxonomy_term.canonical') {
      $entity = $this->getEntityFromRouteMatch($route_match, 'taxonomy_term');

      if (!empty($entity) && $entity instanceof TermInterface) {
        // @todo check the bundle class.
        $accessible = ['country', 'disaster', 'source'];
        if (!in_array($entity->bundle(), $accessible)) {
          return AccessResult::forbidden();
        }
      }
    }
    // Allow access.
    return AccessResult::allowed();
  }

  /**
   * Get an entity from the route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   * @param string $entity_type
   *   Entity type.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Entity or NULL if not found.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match, $entity_type) {
    $parameters = $route_match->getParameters();
    if ($parameters->has($entity_type)) {
      $entity = $parameters->get($entity_type);
      if ($entity instanceof EntityInterface) {
        return $entity;
      }
    }
    return NULL;
  }

}
