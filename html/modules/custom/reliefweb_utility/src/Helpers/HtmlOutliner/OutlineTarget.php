<?php

namespace Drupal\reliefweb_utility\Helpers\HtmlOutliner;

/**
 * Outline target node implementation.
 */
class OutlineTarget extends Node {

  /**
   * Outline.
   *
   * @var \Drupal\reliefweb_utility\Helpers\HtmlOutliner\Outline
   */
  public $outline = NULL;

  /**
   * Parent section.
   *
   * @var \Drupal\reliefweb_utility\Helpers\HtmlOutliner\Section
   */
  public $parentSection = NULL;

}
