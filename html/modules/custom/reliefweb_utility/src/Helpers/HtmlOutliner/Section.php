<?php

namespace Drupal\reliefweb_utility\Helpers\HtmlOutliner;

/**
 * Section node implementation.
 */
class Section extends Node {

  /**
   * Section heading.
   *
   * Either a DOM node or TRUE if the heading is implied.
   *
   * @var \DOMNode|true
   */
  public $heading = NULL;

  /**
   * Sub-sections.
   *
   * @var array
   */
  public $sections = [];

  /**
   * Section container.
   *
   * @var \Drupal\reliefweb_utility\Helpers\HtmlOutliner\Node
   */
  public $container = NULL;

  /**
   * Append a sub-section.
   *
   * @param \Drupal\reliefweb_utility\Helpers\HtmlOutliner\Section $section
   *   Sub-section.
   */
  public function append(Section $section) {
    $section->container = $this;
    $this->sections[] = $section;
  }

}
