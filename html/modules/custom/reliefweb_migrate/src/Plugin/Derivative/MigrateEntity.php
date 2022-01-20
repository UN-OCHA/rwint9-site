<?php

namespace Drupal\reliefweb_migrate\Plugin\Derivative;

use Drupal\migrate\Plugin\Derivative\MigrateEntity as MigrateEntityBase;

/**
 * Deriver for the reliefweb_migrate reliefweb_entity destinations.
 */
class MigrateEntity extends MigrateEntityBase {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityDefinitions as $entity_type => $entity_info) {
      if (is_subclass_of($entity_info->getClass(), 'Drupal\Core\Entity\ContentEntityInterface')) {
        $this->derivatives[$entity_type] = [
          'id' => "reliefweb_entity:$entity_type",
          'class' => 'Drupal\reliefweb_migrate\Plugin\migrate\destination\Entity',
          'requirements_met' => 1,
          'provider' => $entity_info->getProvider(),
        ];
      }
    }
    return $this->derivatives;
  }

}
