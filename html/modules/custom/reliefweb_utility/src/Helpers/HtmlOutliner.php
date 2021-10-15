<?php

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper to fix the heading hierarchy of some HTML.
 *
 * Implements the HTML5 outline algorithms and enables fixing the hierarchy.
 *
 * Adaptation of https://github.com/h5o/h5o-js.
 */
class HtmlOutliner {

  /**
   * Check if a node is a heading.
   *
   * @param \DOMNode $node
   *   DOM node.
   *
   * @return bool
   *   TRUE if the node is a heading.
   */
  public static function isHeading(\DOMNode $node) {
    static $tags = [
      'h1' => TRUE,
      'h2' => TRUE,
      'h3' => TRUE,
      'h4' => TRUE,
      'h5' => TRUE,
      'h6' => TRUE,
      'hgroup' => TRUE,
    ];
    return isset($node, $tags[$node->nodeName]);
  }

  /**
   * Check if a node is a sectioning content.
   *
   * @param \DOMNode $node
   *   DOM node.
   *
   * @return bool
   *   TRUE if the node is a sectioning content.
   */
  public static function isSectioningContent(\DOMNode $node) {
    static $tags = [
      'article' => TRUE,
      'aside' => TRUE,
      'nav' => TRUE,
      'section' => TRUE,
    ];
    return isset($node, $tags[$node->nodeName]);
  }

  /**
   * Check if a node is a sectioning root.
   *
   * @param \DOMNode $node
   *   DOM node.
   *
   * @return bool
   *   TRUE if the node is a sectioning root.
   */
  public static function isSectioningRoot(\DOMNode $node) {
    static $tags = [
      'blockquote' => TRUE,
      'body' => TRUE,
      'details' => TRUE,
      'dialogue' => TRUE,
      'fieldset' => TRUE,
      'figure' => TRUE,
      'td' => TRUE,
    ];
    return isset($node, $tags[$node->nodeName]);
  }

  /**
   * Check if a node is hidden.
   *
   * @param \DOMNode $node
   *   DOM node.
   *
   * @return bool
   *   TRUE if the node is hidden.
   */
  public static function isHidden(\DOMNode $node) {
    return isset($node) && $node->hasAttributes() && $node->hasAttribute('hidden');
  }

  /**
   * Get a heading's rank (= negative level).
   *
   * @param \DOMNode $node
   *   DOM node.
   *
   * @return int
   *   Heading's rank.
   */
  public static function getHeadingRank(\DOMNode $node) {
    $heading = static::getRankingHeading($node);
    return !empty($heading) ? -intval(substr($heading->nodeName, 1)) : -1;
  }

  /**
   * Get the ranking heading.
   *
   * Return the node if it's heading or the top level heading if it's a hgroup.
   *
   * @param \DOMNode $node
   *   DOM node.
   *
   * @return \DOMNode
   *   Ranking heading node.
   */
  public static function getRankingHeading(\DOMNode $node) {
    // Get the top level heading in the hgroup.
    if ($node->nodeName === 'hgroup') {
      for ($i = 1; $i <= 6; $i++) {
        $headings = static::getElementsByTagName($node, 'h' . $i);
        if (count($headings) > 0) {
          return $headings[0];
        }
      }
      return NULL;
    }
    return $node;
  }

  /**
   * Check if heading node associated section is a sub-section.
   *
   * A heading node associated section is a sub-section of the current outline
   * last section if the last section heading is implied or has a higher
   * (or equal) rank than the checked node.
   *
   * @param \Outliner\OutlineTarget|null $outline_target
   *   Outline target.
   * @param \DOMNode $node
   *   DOM node.
   *
   * @return int
   *   Heading's rank.
   */
  public static function isSubSection(?OutlineTarget $outline_target, \DOMNode $node) {
    if (empty($outline_target) || empty($outline_target->outline) || empty($outline_target->outline->sections)) {
      return FALSE;
    }
    $heading = $outline_target->outline->getLastSection()->heading;
    return $heading === TRUE || static::getHeadingRank($node) >= static::getHeadingRank($heading);
  }

  /**
   * Enter a node and analyze its type and content.
   *
   * @param \DOMNode $node
   *   DOM node.
   * @param \Outliner\OutlineTarget|null $outline_target
   *   Current outline target.
   * @param \Outliner\Section|null $current_section
   *   Current section.
   * @param array $stack
   *   Stack to track processed elements.
   */
  public static function enterNode(\DOMNode $node, ?OutlineTarget &$outline_target, ?Section &$current_section, array &$stack) {
    // Nothing to do if the top of the stack is hidden or a heading.
    $stackTop = end($stack);
    if (!empty($stackTop) && (static::isHeading($stackTop->node) || static::isHidden($stackTop->node))) {
      return;
    }
    // Push the node to the stack to skip it and its descendants.
    elseif (static::isHidden($node)) {
      $stack[] = new Node($node);
    }
    // Sectioning content.
    elseif (static::isSectioningContent($node)) {
      // Push current outline target to the stack.
      if (!empty($outline_target)) {
        // Mark the current section heading as implied if not defined.
        if (empty($current_section->heading)) {
          $current_section->heading = TRUE;
        }
        $stack[] = $outline_target;
      }

      // New outline target.
      $outline_target = new OutlineTarget($node);

      // New section.
      $current_section = new Section($node);

      // Set the outline of the new outline target.
      $outline_target->outline = new Outline($node, $current_section);
    }
    // Sectioning root.
    elseif (static::isSectioningRoot($node)) {
      // Push current outline target to the stack.
      if (!empty($outline_target)) {
        $stack[] = $outline_target;
      }

      // New outline target.
      $outline_target = new OutlineTarget($node);

      // Set the current section as the outline target parent section.
      $outline_target->parentSection = $current_section;

      // New section.
      $current_section = new Section($node);

      // Set the outline of the new outline target.
      $outline_target->outline = new Outline($node, $current_section);
    }
    // Heading.
    elseif (static::isHeading($node)) {
      // Set the node as the heading of the current section if it has none.
      if (!empty($current_section) && empty($current_section->heading)) {
        $current_section->heading = $node;
      }
      // If the last outline section heading is implied or has a higher rank
      // than the node's one, then create a new section, add it to the outline
      // sections, set the node as its heading and set this section as the
      // current one.
      elseif (static::isSubSection($outline_target, $node)) {
        $section = new Section($node);
        $outline_target->outline->sections[] = $section;
        $current_section = $section;
        $current_section->heading = $node;
      }
      // Otherwise, if the last outline section has a heading and its rank
      // is lower than the node's one then we try to find the parent of the
      // section associated with the node.
      else {
        $candidate = $current_section;

        while ($candidate) {
          // Found a candidate section as parent of the node's one.
          if (!empty($candidate) && static::getHeadingRank($node) < static::getHeadingRank($candidate->heading)) {
            $section = new Section($node);

            $candidate->sections[] = $section;
            $section->container = $candidate;

            $current_section = $section;
            $current_section->heading = $node;
            break;
          }

          $candidate = $candidate->container;
        }
      }

      // Push the node to the stack to skip it and its descendants.
      $stack[] = new Node($node);
    }
  }

  /**
   * Exit a node.
   *
   * @param \DOMNode $node
   *   DOM node.
   * @param \Outliner\OutlineTarget|null $outline_target
   *   Current outline target.
   * @param \Outliner\Section|null $current_section
   *   Current section.
   * @param array $stack
   *   Stack to track processed elements.
   */
  public static function exitNode(\DOMNode $node, ?OutlineTarget &$outline_target, ?Section &$current_section, array &$stack) {
    $stackTop = end($stack);
    if (!empty($stackTop)) {
      // Remove the node from the stack if it's the last added element.
      if ($stackTop->node === $node) {
        array_pop($stack);
      }
      // Nothing to do if the element is a heading or hidden.
      if (static::isHeading($stackTop->node) || static::isHidden($stackTop->node)) {
        return;
      }
    }

    // Go through the stack if it's not empty.
    if (!empty($stack)) {
      // Sectioning content.
      if (static::isSectioningContent($node)) {
        // Set the node as the heading of the current section if it has none.
        if (!empty($current_section) && empty($current_section->heading)) {
          $current_section->heading = TRUE;
        }

        // Keep track of the current outline target which correspond to the
        // outline for the node.
        $exiting_outline_target = $outline_target;

        // Switch the outline target to the last element in the stack which
        // is above in the tree than the exiting one.
        $outline_target = array_pop($stack);

        // Set the current section to the last section of the outline.
        $current_section = $outline_target->outline->getLastSection();

        // Add the sections of the exiting outline as sub-sections of the
        // current section.
        foreach ($exiting_outline_target->outline->sections as $section) {
          $current_section->append($section);
        }
      }
      // Sectioning root.
      elseif (static::isSectioningRoot($node)) {
        // Set the node as the heading of the current section if it has none.
        if (!empty($current_section) && empty($current_section->heading)) {
          $current_section->heading = TRUE;
        }

        // Set the current section as the parent section of the outline target.
        $current_section = $outline_target->parentSection;

        // Set the outline target to the next item in the stack.
        $outline_target = array_pop($stack);
      }
    }
    // The node is the root, we simply set the heading if not defined.
    elseif (static::isSectioningContent($node) || static::isSectioningRoot($node)) {
      // Set the node as the heading of the current section if it has none.
      if (!empty($current_section) && empty($current_section->heading)) {
        $current_section->heading = TRUE;
      }
    }
  }

  /**
   * Parse a DOM node and return it's outline structure.
   *
   * @param \DOMNode|null $root
   *   Root node.
   *
   * @return \Outliner\Outline|null
   *   Outline object.
   */
  public static function parseNode(?\DOMNode $root) {
    // Skip if the root node is not a sectioning content or root node.
    if (!empty($root) && !static::isSectioningContent($root) && !static::isSectioningRoot($root)) {
      return NULL;
    }

    // Keep track of the outline and sections being parsed.
    $outline_target = NULL;
    $current_section = NULL;
    $stack = [];

    $node = $root;

    // Walk the DOM tree.
    start: while (!empty($node)) {
      static::enterNode($node, $outline_target, $current_section, $stack);

      if (!empty($node->firstChild)) {
        $node = $node->firstChild;
        goto start;
      }

      while (!empty($node)) {
        static::exitNode($node, $outline_target, $current_section, $stack);

        if (!empty($node->nextSibling)) {
          $node = $node->nextSibling;
          goto start;
        }

        $node = $node !== $root ? $node->parentNode : NULL;
      }
    }

    return !empty($outline_target) ? $outline_target->outline : NULL;
  }

  /**
   * Parse an HTML string and return its outline.
   *
   * @param string $html
   *   HTML string.
   *
   * @return \Outliner\Outline|null
   *   Outline object.
   */
  public static function parseHtml($html) {
    if (empty($html)) {
      return NULL;
    }

    // Flags to load the HTML string.
    $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;

    // Adding this meta tag is necessary to tell \DOMDocument we are dealing
    // with UTF-8 encoded html.
    $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $prefix = '<!DOCTYPE html><html><head>' . $meta . '</head><body>';
    $suffix = '</body></html>';
    $dom = new \DOMDocument();
    $dom->loadHTML($prefix . $html . $suffix, $flags);

    return static::parseNode(static::getBody($dom));
  }

  /**
   * Fix the heading hierarchy of the given DOM node.
   *
   * @param \DOMNode $node
   *   DOM node.
   * @param int $level
   *   Current hierarchy level.
   * @param bool $nogap
   *   If TRUE, then the hierarchy will be fixed in a way that ensures there are
   *   no gaps between heading levels. Otherwise the headings will be fixed
   *   according to their level in the outline.
   */
  public static function fixNodeHeadingHierarchy(\DOMNode $node, $level = 0, $nogap = TRUE) {
    if (is_a($node, '\DOMDocument')) {
      $outline = static::parseNode(static::getBody($node));
    }
    else {
      $outline = static::parseNode($node);
    }
    if (!empty($outline)) {
      static::fixSectionHeadingHierarchy($outline->sections, $level, $nogap);
    }
  }

  /**
   * Fix the heading heriarchy of the given sections.
   *
   * @param array $sections
   *   Sections.
   * @param int $level
   *   Current hierarchy level.
   * @param bool $nogap
   *   If TRUE, then the hierarchy will be fixed in a way that ensures there are
   *   no gaps between heading levels. Otherwise the headings will be fixed
   *   according to their level in the outline.
   */
  public static function fixSectionHeadingHierarchy(array $sections, $level = 0, $nogap = TRUE) {
    if (empty($sections)) {
      return;
    }

    // Fix the heading of each section.
    foreach ($sections as $section) {
      $heading = $section->heading;
      $implied = empty($heading) || $heading === TRUE;

      // The rank is one higher than the hierarchical level.
      $rank = $level + 1;

      // Skip if the the heading is not defined or is implied.
      if (!$implied) {
        // For hgroup, we fix all the sub-headings ranks.
        if ($heading->nodeName === 'hgroup') {
          // Extract the headings by rank.
          $headings = [];
          for ($i = 1; $i <= 6; $i++) {
            $nodes = static::getElementsByTagName($heading, 'h' . $i);
            if (count($nodes) > 0) {
              $headings[$rank++] = $nodes;
            }
          }
          // Fix the rank of the heading nodes and append them to parent node
          // to ensure they are ordered by rank.
          foreach ($headings as $rank => $nodes) {
            foreach ($nodes as $node) {
              if ($node->parentNode !== NULL) {
                $node->parentNode->appendChild(static::fixHeadingRank($node, $rank));
              }
            }
          }
        }
        // Simply fix the heading rank.
        else {
          static::fixHeadingRank($heading, $rank);
        }
      }

      // Process the sub-sections. If the heading was implied and no gap was
      // set to TRUE then we keep the same hierarchical level so that there is
      // no gap between the heading ranks. This breaks the outline but is the
      // only way to have a proper rank hierarchy without gaps as recommended
      // for accessibility.
      static::fixSectionHeadingHierarchy($section->sections, $implied && $nogap ? $level : $level + 1);
    }
  }

  /**
   * Fix the heading rank by replacing it with a new node with the proper rank.
   *
   * @param \DOMNode $heading
   *   Heading to fix.
   * @param int $rank
   *   New heading rank.
   *
   * @return \DOMNode
   *   New heading or same one if there was no need for a change.
   */
  public static function fixHeadingRank(\DOMNode $heading, $rank) {
    // Replace the heading with a strong tag if it goes beyond the 6th rank.
    $tag = $rank > 6 ? 'strong' : 'h' . $rank;
    // Nothing to do if the tag name is unchanged.
    if ($tag === $heading->nodeName) {
      return $heading;
    }
    // Create the new heading node.
    $node = $heading->ownerDocument->createElement($tag);
    // Transfer the heading children to the new node.
    while ($heading->firstChild !== NULL) {
      $node->appendChild($heading->firstChild);
    }
    // Copy the attributes.
    foreach ($heading->attributes as $attribute => $dummy) {
      $node->setAttribute($attribute, $heading->getAttribute($attribute));
    }
    // Replace the heading node with the new one.
    $heading->parentNode->replaceChild($node, $heading);
    return $node;
  }

  /**
   * Get the nodes matching the tag name.
   *
   * \DOMElement::GetElementsByTagName returns a live collection. We convert it
   * to a flat array so that the nodes can be manipulated during the iteration
   * without creating infinite loops for example.
   *
   * @param \DOMNode $node
   *   Node (\DOMDocument or \DOMElement)
   * @param string $tag
   *   Tag name or `*` for all nodes.
   *
   * @return array
   *   List of nodes with the given tag name.
   */
  public static function getElementsByTagName(\DOMNode $node, $tag) {
    $elements = [];
    if (method_exists($node, 'getElementsByTagName')) {
      foreach ($node->getElementsByTagName($tag) as $element) {
        $elements[] = $element;
      }
    }
    return $elements;
  }

  /**
   * Get body.
   *
   * @param \DOMNode $node
   *   Node (\DOMDocument or \DOMElement)
   *
   * @return \DOMNode
   *   Body node.
   */
  public static function getBody(\DOMNode $node) {
    $elements = static::getElementsByTagName($node, 'body');
    return count($elements) > 0 ? $elements[0] : NULL;
  }

}

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
   * @var \Outliner\Node
   */
  public $container = NULL;

  /**
   * Append a sub-section.
   *
   * @param \Outliner\Section $section
   *   Sub-section.
   */
  public function append(Section $section) {
    $section->container = $this;
    $this->sections[] = $section;
  }

}

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
   * @param \Outliner\Section $section
   *   First section of the outline.
   */
  public function __construct(\DOMNode $node, Section $section) {
    $this->node = $node;
    $this->sections[] = $section;
  }

  /**
   * Get the last section of the outline.
   *
   * @return \Outliner\Section
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
        $heading = Outliner::getRankingHeading($section->heading) ?? $section->heading;
        $output[] = $padding . $heading->nodeName . ' ' . trim($heading->textContent);
      }
      if (!empty($section->sections)) {
        $output[] = static::getHeadings($section->sections, $level + 1);
      }
    }
    return implode(PHP_EOL, $output);
  }

}

/**
 * Outline target node implementation.
 */
class OutlineTarget extends Node {

  /**
   * Outline.
   *
   * @var \Outliner\Outline
   */
  public $outline = NULL;

  /**
   * Parent section.
   *
   * @var \Outliner\Section
   */
  public $parentSection = NULL;

}
