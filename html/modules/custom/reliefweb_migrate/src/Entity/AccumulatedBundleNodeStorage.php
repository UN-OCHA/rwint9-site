<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\reliefweb_entities\BundleNodeStorage;

/**
 * Node SQL storage for the migrations that regroup queries.
 */
class AccumulatedBundleNodeStorage extends BundleNodeStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

}
