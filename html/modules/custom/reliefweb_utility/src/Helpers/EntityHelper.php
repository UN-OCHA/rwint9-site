<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Markup;

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

  /**
   * Format a revision log message.
   *
   * @param string $message
   *   Revision log message.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Formatted revision log message wrapped in a MarkupInterface so it's not
   *   escaped a second time when rendered in a template.
   */
  public static function formatRevisionLogMessage($message) {
    if (!empty($message)) {
      $message = MarkdownHelper::convertInlinesOnly($message);
      $message = HtmlSanitizer::sanitize($message);
    }
    else {
      $message = '';
    }
    return Markup::create($message);
  }

}
