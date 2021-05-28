<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for bundle entities.
 */
interface BundleEntityInterface {

  /**
   * Get the content of the full entity page for the bundle.
   *
   * Note: this may differs from one bundle to another.
   *
   * @return array
   *   Get the page content.
   *
   * @todo we probably need intermediary classes/interfaces.
   */
  public function getPageContent();

}
