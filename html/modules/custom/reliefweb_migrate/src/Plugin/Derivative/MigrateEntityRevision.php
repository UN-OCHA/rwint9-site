<?php

namespace Drupal\reliefweb_migrate\Plugin\Derivative;

use Drupal\migrate\Plugin\Derivative\MigrateEntityRevision as MigrateEntityRevisionBase;

/**
 * Deriver for the reliefweb_migrate reliefweb_entity_revision destinations.
 */
class MigrateEntityRevision extends MigrateEntityRevisionBase {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityDefinitions as $entity_type => $entity_info) {
      if (is_subclass_of($entity_info->getClass(), 'Drupal\Core\Entity\ContentEntityInterface') && $entity_info->getKey('revision')) {
        $this->derivatives[$entity_type] = [
          'id' => "reliefweb_entity_revision:$entity_type",
          'class' => 'Drupal\reliefweb_migrate\Plugin\migrate\destination\EntityRevision',
          'requirements_met' => 1,
          'provider' => $entity_info->getProvider(),
        ];
      }
    }
    return $this->derivatives;
  }

}
