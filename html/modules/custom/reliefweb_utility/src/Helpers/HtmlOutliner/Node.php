<?php

namespace Drupal\reliefweb_utility\Helpers\HtmlOutliner;

/**
 * DOM node wrapper base class.
 */
class Node {

  /**
   * DOM node.
   *
   * @var \DOMNode
   */
  public $node;

  /**
   * Construct the node wrapper.
   *
   * @param \DOMNode $node
   *   DOM node.
   */
  public function __construct(\DOMNode $node) {
    $this->node = $node;
  }

}
