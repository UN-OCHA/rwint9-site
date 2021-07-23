<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for bundle entities.
 */
interface BundleEntityInterface {

  /**
   * Get the API resources for the entity bundle.
   *
   * @return string
   *   API resource.
   */
  public function getApiResource();

}
