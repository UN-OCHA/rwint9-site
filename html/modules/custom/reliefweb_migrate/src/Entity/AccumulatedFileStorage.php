<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\file\FileStorage;

/**
 * File SQL storage for the migrations that regroup queries.
 */
class AccumulatedFileStorage extends FileStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

}
