<?php

namespace Drupal\reliefweb_utility\Helpers\HtmlOutliner;

use Drupal\reliefweb_utility\Helpers\HtmlOutliner as HtmlOutlinerHelper;

/**
 * Outline node implementation.
 */
class Outline extends Node {

  /**
   * Sections.
   *
   * @var array
   */
  public $sections = [];

  /**
   * Construct the node wrapper.
   *
   * @param \DOMNode $node
   *   DOM node.
   * @param \Drupal\reliefweb_utility\Helpers\HtmlOutliner\Section $section
   *   First section of the outline.
   */
  public function __construct(\DOMNode $node, Section $section) {
    $this->node = $node;
    $this->sections[] = $section;
  }

  /**
   * Get the last section of the outline.
   *
   * @return \Drupal\reliefweb_utility\Helpers\HtmlOutliner\Section
   *   Last outline section.
   */
  public function getLastSection() {
    return !empty($this->sections) ? end($this->sections) : NULL;
  }

  /**
   * Convert the outline to string.
   */
  public function __toString() {
    return static::getHeadings($this->sections) . PHP_EOL;
  }

  /**
   * Get the stringify version.
   *
   * @param array $sections
   *   List of sections.
   * @param int $level
   *   Current level in the hierarchy.
   */
  public static function getHeadings(array $sections, $level = 0) {
    if (empty($sections)) {
      return '';
    }
    $padding = str_pad('', $level * 4);
    $output = [];
    foreach ($sections as $section) {
      if (empty($section->heading) || $section->heading === TRUE) {
        $output[] = $padding . '?? untitled section';
      }
      else {
        $heading = HtmlOutlinerHelper::getRankingHeading($section->heading) ?? $section->heading;
        $output[] = $padding . $heading->nodeName . ' ' . trim($heading->textContent);
      }
      if (!empty($section->sections)) {
        $output[] = static::getHeadings($section->sections, $level + 1);
      }
    }
    return implode(PHP_EOL, $output);
  }

}
