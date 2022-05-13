<?php

namespace Drupal\reliefweb_users\Routing;

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
    if ($route = $collection->get('entity.user.collection')) {
      $route->setDefaults([
        '_controller' => '\Drupal\reliefweb_users\Controller\UserController::getContent',
        '_title' => 'People',
      ]);
    }

    // Define custom access for the user routes.
    foreach ($collection->getIterator() as $route) {
      if (strpos($route->getPath(), '/user/{user}') === 0) {
        $route->setRequirement('_reliefweb_user_access_check', 'Drupal\reliefweb_users\Access\SystemUserAccessCheck::access');
      }
    }
  }

}
