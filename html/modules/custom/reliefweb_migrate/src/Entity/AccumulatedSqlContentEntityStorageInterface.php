<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\Core\Entity\EntityInterface;

/**
 * SQL storage for the migrations that regroup queries.
 */
interface AccumulatedSqlContentEntityStorageInterface {

  /**
   * Accumulate the data to save the entity permanently.
   *
   * @see Drupal\Core\Entity\ContentEntityStorageBase::save()
   */
  public function saveAccumulated(EntityInterface $entity);

  /**
   * Save the accumulated table data in the database.
   */
  public function flushAccumulated();

}
