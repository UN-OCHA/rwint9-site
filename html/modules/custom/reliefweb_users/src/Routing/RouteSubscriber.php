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
  }

}
