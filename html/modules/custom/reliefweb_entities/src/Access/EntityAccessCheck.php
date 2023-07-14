<?php

namespace Drupal\reliefweb_entities\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\reliefweb_entities\Entity\TaxonomyTermBase;

/**
 * Check access to an entity page.
 */
class EntityAccessCheck implements AccessInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * Check access to the entity page.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match) {
    if ($route_match->getRouteName() === 'entity.taxonomy_term.canonical') {
      $entity = $this->getEntityFromRouteMatch($route_match, 'taxonomy_term');

      // Deny access to basic taxonomy terms unless the user can edit them.
      if (!empty($entity) && $entity instanceof TaxonomyTermBase && !$this->currentUser->hasPermission('edit terms in ' . $entity->bundle())) {
        return AccessResult::forbidden();
      }
    }
    // Let other modules decide.
    return AccessResult::allowed();
  }

  /**
   * Get an entity from the route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   * @param string $entity_type
   *   Entity type.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Entity or NULL if not found.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match, $entity_type) {
    $parameters = $route_match->getParameters();
    if ($parameters->has($entity_type)) {
      $entity = $parameters->get($entity_type);
      if ($entity instanceof EntityInterface) {
        return $entity;
      }
    }
    return NULL;
  }

}
