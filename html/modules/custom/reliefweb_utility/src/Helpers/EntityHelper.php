<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Helper to information about entities.
 */
class EntityHelper {

  /**
   * Attempt to get the entity for the given route or the current one.
   *
   * Note: this only works for routes using the "standard" way to declare
   * entity parameters: `entity:entity_type_id`.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface|null $route_match
   *   Optional route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity for the route or NULL if none.
   */
  public static function getEntityFromRoute(?RouteMatchInterface $route_match = NULL) {
    if (!isset($route_match)) {
      $route_match = \Drupal::routeMatch();
    }

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

    return NULL;
  }

  /**
   * Attempt to get the entity for a request.
   *
   * Note: this only works for routes using the "standard" way to declare
   * entity parameters: `entity:entity_type_id`.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity for the route or NULL if none.
   */
  public static function getEntityFromRequest(Request $request) {
    $route_match = RouteMatch::createFromRequest($request);
    return static::getEntityFromRoute($route_match);
  }

  /**
   * Get a bundle's label from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Bundle label.
   */
  public static function getBundleLabelFromEntity(EntityInterface $entity) {
    return static::getBundleLabel($entity->getEntityTypeId(), $entity->bundle());
  }

  /**
   * Get a bundle's label.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $bundle
   *   Entity bundle.
   *
   * @return string
   *   Bundle label.
   */
  public static function getBundleLabel($entity_type_id, $bundle) {
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);
    return $bundle_info[$bundle]['label'] ?? $bundle;
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
