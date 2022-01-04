<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\node\NodeStorage;

/**
 * Node SQL storage for the migrations that regroup queries.
 */
class AccumulatedNodeStorage extends NodeStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

}
