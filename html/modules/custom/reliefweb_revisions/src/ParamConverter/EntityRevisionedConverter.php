<?php

namespace Drupal\reliefweb_revisions\ParamConverter;

use Drupal\Core\ParamConverter\EntityConverter;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Symfony\Component\Routing\Route;

/**
 * Revisioned entity parameter converter.
 */
class EntityRevisionedConverter extends EntityConverter {

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $entity = parent::convert($value, $definition, $name, $defaults);
    if (!empty($entity) && $entity instanceof EntityRevisionedInterface) {
      return $entity;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    if (!empty($definition['type']) && strpos($definition['type'], 'entity_revisioned:') === 0) {
      $entity_type_id = substr($definition['type'], strlen('entity_revisioned:'));
      if (strpos($definition['type'], '{') !== FALSE) {
        $entity_type_slug = substr($entity_type_id, 1, -1);
        return $name != $entity_type_slug && in_array($entity_type_slug, $route
          ->compile()
          ->getVariables(), TRUE);
      }
      return $this->entityTypeManager
        ->hasDefinition($entity_type_id);
    }
    return FALSE;
  }

}
