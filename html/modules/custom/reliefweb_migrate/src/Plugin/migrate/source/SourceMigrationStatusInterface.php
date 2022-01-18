<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

/**
 * Base entity source plugin for reliefweb migrations.
 */
interface SourceMigrationStatusInterface {

  /**
   * Get the number of imported, updated, deleted and unprocessed entities.
   *
   * @return array
   *   Associative array with the number of importable, imported, new, updated
   *   and deleted entities.
   */
  public function getMigrationStatus();

}
