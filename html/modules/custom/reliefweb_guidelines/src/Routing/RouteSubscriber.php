<?php

namespace Drupal\reliefweb_guidelines\Routing;

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
    // Set the proper permission to access the guideline json data.
    if ($route = $collection->get('guidelines.form.json')) {
      $route->setRequirement('_permission', 'access editorial guidelines');
    }
    // Set the proper permission to access the guideline sort form and add
    // a custom acccess check handler to be able to deny access to non-list
    // guidelines because they don't have children.
    if ($route = $collection->get('entity.guideline.sort')) {
      $route->setRequirement('_permission', 'sort editorial guidelines');
      $route->setRequirement('_custom_access', '\Drupal\reliefweb_guidelines\Form\GuidelineSortForm::checkAccess');
    }
  }

}
