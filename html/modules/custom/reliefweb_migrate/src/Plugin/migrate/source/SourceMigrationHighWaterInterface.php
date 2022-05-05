<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

/**
 * Interface for source migration plugins using high water mark.
 */
interface SourceMigrationHighWaterInterface {

  /**
   * Set the high water to latest non imported ID.
   *
   * Note: this mostly useful during development to be able to re-import
   * deleted or modified old content.
   *
   * @param bool $check_only
   *   If TRUE, do not update the high water.
   *
   * @return int
   *   The latest imported ID for the migration.
   */
  public function setHighWaterToLatestNonImported($check_only = FALSE);

}
