<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for the opportunity document entities like jobs and training.
 */
interface OpportunityDocumentInterface {

  /**
   * Check if the opportunity has expired.
   *
   * @return bool
   *   TRUE if the opportunity has expired.
   */
  public function hasExpired();

}
