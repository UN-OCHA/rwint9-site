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

  /**
   * Add constraints to bundle fields.
   *
   * @param array $fields
   *   Fields for this bundle.
   */
  public static function addFieldConstraints(array &$fields);

}
