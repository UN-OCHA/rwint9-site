<?php

namespace Drupal\reliefweb_entities\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Add a requirement to check the entity access.
    if ($route = $collection->get('entity.taxonomy_term.canonical')) {
      $requirements = ['_reliefweb_entities_entity_access_check' => '{taxonomy_term}'];
      $requirements += $route->getRequirements();
      $route->setRequirements($requirements);
    }

    // Ensure all the admin content pages are considered as admin routes as this
    // cannot be defined in the views UI...
    // @see https://www.drupal.org/project/drupal/issues/2719797
    foreach ($collection->all() as $route) {
      if (strpos($route->getPath(), '/admin/content') === 0) {
        $route->setOption('_admin_route', TRUE);
      }
    }
  }

}
