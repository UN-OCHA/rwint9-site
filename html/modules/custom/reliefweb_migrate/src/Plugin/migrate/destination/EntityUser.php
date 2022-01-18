<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\migrate\Event\MigrateImportEvent;

/**
 * Entity migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_entity:user"
 * )
 */
class EntityUser extends Entity {

  /**
   * {@inheritdoc}
   */
  public function preImport(MigrateImportEvent $event) {
    // We don't have an easy way to detect changes made to user entities and
    // updating or rolling back is really slow and has unwanted consequences
    // due to some entity hooks. So we "simply" delete all the users from the
    // database and re-import everything.
    if (!empty($this->configuration['delete_before_import'])) {
      $this->deleteImported();
    }
  }

}
