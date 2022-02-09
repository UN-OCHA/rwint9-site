<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Entity\EntityInterface;

/**
 * Helper to information about entities.
 */
class EntityHelper {

  /**
   * Attempt to get the entity for the current route.
   *
   * Note: this only works for routes using the "standard" way to declare
   * entity parameters: `entity:entity_type_id`.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity for the route or NULL if none.
   */
  public static function getEntityFromRoute() {
    $route_match = \Drupal::routeMatch();

    $route = $route_match->getRouteObject();
    if (empty($route)) {
      return NULL;
    }

    $parameters = $route->getOption('parameters');
    if (empty($parameters)) {
      return NULL;
    }

    foreach ($parameters as $name => $options) {
      if (isset($options['type']) && strpos($options['type'], 'entity:') === 0) {
        $entity = $route_match->getParameter($name);
        if (!empty($entity) && $entity instanceof EntityInterface) {
          return $entity;
        }
        else {
          return NULL;
        }
      }
    }
  }

}
