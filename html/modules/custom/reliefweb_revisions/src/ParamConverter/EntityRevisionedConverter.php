<?php

namespace Drupal\reliefweb_revisions\ParamConverter;

use Drupal\Core\ParamConverter\EntityConverter;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;

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

}
