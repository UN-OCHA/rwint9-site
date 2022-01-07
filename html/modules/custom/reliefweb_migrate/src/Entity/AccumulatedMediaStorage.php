<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\media\MediaStorage;

/**
 * Media SQL storage for the migrations that regroup queries.
 */
class AccumulatedMediaStorage extends MediaStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

}
