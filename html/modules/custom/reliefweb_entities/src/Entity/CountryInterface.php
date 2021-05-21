<?php

namespace Drupal\reliefweb_entities\Entity;

/**
 * Bundle class interface for country terms.
 */
interface CountryInterface {

  /**
   * Get page sections.
   *
   * @return array
   *   List of sections as render arrays.
   */
  public function getPageSections();

  /**
   * Get page table of content.
   *
   * @return array
   *   Render array of the table of content.
   */
  public function getPageTableOfContent();

}
