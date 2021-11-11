<?php

namespace Drupal\reliefweb_docstore\Routing;

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
    // Replace the file and image style download controllers so we can control
    // the access to the reliefweb private files. Otherwise access is always
    // denied by other modules because they temporary private files.
    if ($route = $collection->get('system.files')) {
      $route->setDefault('_controller', '\Drupal\reliefweb_docstore\Controller\FileDownloadController::download');
    }
    if ($route = $collection->get('image.style_private')) {
      $route->setDefault('_controller', '\Drupal\reliefweb_docstore\Controller\ImageStyleDownloadController::deliver');
    }
  }

}
