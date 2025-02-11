<?php

namespace Drupal\reliefweb_post_api\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Defines a theme negotiator to determine the theme to use for some routes.
 */
class ThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $routes = [
      'entity.reliefweb_post_api_provider.add_form',
      'entity.reliefweb_post_api_provider.edit_form',
      'entity.reliefweb_post_api_provider.delete_form',
    ];

    return in_array($route_match->getRouteName(), $routes);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    return 'common_design_subtheme';
  }

}
