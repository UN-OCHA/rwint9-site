<?php

namespace Drupal\reliefweb_files\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
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
    // denied by other modules because they are temporary private files.
    if ($route = $collection->get('system.files')) {
      $route->setDefault('_controller', '\Drupal\reliefweb_files\Controller\FileDownloadController::download');
    }
    if ($route = $collection->get('image.style_private')) {
      $route->setDefault('_controller', '\Drupal\reliefweb_files\Controller\ImageStyleDownloadController::deliver');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    // Ensure we run after stage_file_proxy so that it doesn't override our
    // controller for private image styles.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -1];
    return $events;
  }

}
