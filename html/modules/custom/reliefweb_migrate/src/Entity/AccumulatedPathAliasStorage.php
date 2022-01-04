<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\path_alias\PathAliasStorage;

/**
 * Path Alias SQL storage for the migrations that regroup queries.
 */
class AccumulatedPathAliasStorage extends PathAliasStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

}
