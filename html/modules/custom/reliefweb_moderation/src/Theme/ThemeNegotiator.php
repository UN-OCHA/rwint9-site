<?php

namespace Drupal\reliefweb_moderation\Theme;

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
      'reliefweb_moderation.domain_posting_rights.overview',
      'reliefweb_moderation.domain_posting_rights.edit',
      'reliefweb_moderation.user_posting_rights.edit',
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
