<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Component\Render\MarkupInterface;

/**
 * Helper to sanitize HTML.
 */
class HtmlSanitizer {

  /**
   * Flag indicating whether to allow iframes or not.
   *
   * @var bool
   */
  protected $iframe = FALSE;

  /**
   * Offset for the conversion of the headings to perserve the hierarchy.
   *
   * @var int
   */
  protected $headingOffset = 2;

  /**
   * List of attributes that should be preserved (ex: data-disaster-map).
   *
   * @var array
   */
  protected $allowedAttributes = [];

  /**
   * Constructor.
   *
   * @param bool $iframe
   *   Whether to allow iframes or not.
   * @param int $heading_offset
   *   Offset for the conversion of the headings to perserve the hierarchy.
   * @param array $allowed_attributes
   *   List of attributes that should be preserved (ex: data-disaster-map).
   */
  public function __construct($iframe = FALSE, $heading_offset = 2, array $allowed_attributes = []) {
    $this->iframe = $iframe;
    $this->headingOffset = $heading_offset;
    $this->allowedAttributes = $allowed_attributes;
  }

  /**
   * Sanitize an HTML string, removing unallowed tags and attributes.
   *
   * This also attempts to fix the heading hierarchy, at least preventing
   * the use of h1 and h2 in the sanitized content.
   *
   * @param string $html
   *   HTML string to sanitize.
   * @param bool $iframe
   *   Whether to allow iframes or not.
   * @param int $heading_offset
   *   Offset for the conversion of the headings to perserve the hierarchy.
   * @param array $allowed_attributes
   *   List of attributes that should be preserved (ex: data-disaster-map).
   *
   * @return string
   *   Sanitized HTML string.
   */
  public static function sanitize($html, $iframe = FALSE, $heading_offset = 2, array $allowed_attributes = []) {
    $sanitizer = new static($iframe, $heading_offset, $allowed_attributes);
    return $sanitizer->sanitizeHtml($html);
  }

  /**
   * Convert a markdown text to HTML and sanitize the HTML string.
   *
   * This also attempts to fix the heading hierarchy, at least preventing
   * the use of h1 and h2 in the sanitized content.
   *
   * @param string $text
   *   Markdown string to convert and sanitize.
   * @param bool $iframe
   *   Whether to allow iframes or not.
   * @param int $heading_offset
   *   Offset for the conversion of the headings to perserve the hierarchy.
   * @param array $allowed_attributes
   *   List of attributes that should be preserved (ex: data-disaster-map).
   * @param array $internal_hosts
   *   List of internal hosts to determine if a link is external or not.
   *
   * @return string
   *   Sanitized HTML string.
   */
  public static function sanitizeFromMarkdown($text, $iframe = FALSE, $heading_offset = 2, array $allowed_attributes = [], array $internal_hosts = ['reliefweb.int']) {
    $html = MarkdownHelper::convertToHtml($text, $internal_hosts);

    return static::sanitize($html, $iframe, $heading_offset, $allowed_attributes);
  }

  /**
   * Sanitize an HTML string, removing unallowed tags and attributes.
   *
   * This also attempts to fix the heading hierarchy, at least preventing
   * the use of h1 and h2 in the sanitized content.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $html
   *   HTML string to sanitize.
   *
   * @return string
   *   Sanitized HTML string.
   */
  public function sanitizeHtml($html) {
    // Skip if html is not a string.
    if (!is_string($html) && !($html instanceof MarkupInterface)) {
      return '';
    }

    // Skip if the html string is empty.
    $html = trim($html);
    if (empty($html)) {
      return '';
    }

    // Flags to load the HTML string.
    $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;

    // Supported tags an whether they can be empty (no children) or not.
    $tags = [
      'html' => FALSE,
      'head' => FALSE,
      'meta' => TRUE,
      'body' => FALSE,
      'div' => FALSE,
      'article' => FALSE,
      'section' => FALSE,
      'header' => FALSE,
      'footer' => FALSE,
      'aside' => FALSE,
      'span' => FALSE,
      // No children.
      'br' => TRUE,
      'a' => FALSE,
      'em' => FALSE,
      'i' => FALSE,
      'strong' => FALSE,
      'b' => FALSE,
      'big' => FALSE,
      'cite' => FALSE,
      'code' => FALSE,
      'strike' => FALSE,
      'ul' => FALSE,
      'ol' => FALSE,
      'li' => FALSE,
      'dl' => FALSE,
      'dt' => FALSE,
      'dd' => FALSE,
      'blockquote' => FALSE,
      'p' => FALSE,
      'pre' => FALSE,
      'h1' => FALSE,
      'h2' => FALSE,
      'h3' => FALSE,
      'h4' => FALSE,
      'h5' => FALSE,
      'h6' => FALSE,
      'table' => FALSE,
      'caption' => FALSE,
      'thead' => FALSE,
      'tbody' => FALSE,
      'th' => FALSE,
      'td' => FALSE,
      'tr' => FALSE,
      'sup' => FALSE,
      'sub' => FALSE,
      'small' => FALSE,
      'font' => FALSE,
      // No children.
      'img' => TRUE,
    ];

    $convert = [
      'i' => 'em',
      'b' => 'strong',
      'big' => 'strong',
    ];

    $headings = [
      'h1' => TRUE,
      'h2' => TRUE,
      'h3' => TRUE,
      'h4' => TRUE,
      'h5' => TRUE,
      'h6' => TRUE,
    ];

    // Allow iframes if instructed to.
    if (!empty($this->iframe)) {
      // No children.
      $tags['iframe'] = TRUE;
    }

    // Convert all '&nbsp;' to normal spaces.
    $html = str_replace('&nbsp;', ' ', $html);

    // Adding this meta tag is necessary to tell \DOMDocument we are dealing
    // with UTF-8 encoded html.
    $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $prefix = '<!DOCTYPE html><html><head>' . $meta . '</head><body>';
    $suffix = '</body></html>';
    $dom = new \DOMDocument();
    $dom->loadHTML($prefix . $html . $suffix, $flags);

    // Fix the heading hierarchy.
    HtmlOutliner::fixNodeHeadingHierarchy($dom, $this->headingOffset);

    // Parse all the dom nodes.
    foreach ($this->getElementsByTagName($dom, '*') as $node) {
      // Skip orphan nodes (for example from manipulations below).
      if (empty($node) || empty($node->parentNode)) {
        continue;
      }

      // Remove style and event attributes.
      $this->sanitizeAttributes($node);

      // Get the node tag name to determine processing.
      $tag = $node->tagName;

      // Remove unrecognized/unallowed tags.
      if (!isset($tags[$tag])) {
        $this->removeChild($node);
      }
      // Remove tags that should not be empty.
      elseif ($tags[$tag] === FALSE && $this->isEmpty($node)) {
        $this->removeChild($node);
      }
      // Process headings, keeping only ids.
      elseif (isset($headings[$tag])) {
        $this->handleHeading($node);
      }
      // Process links, removing invalid ones.
      elseif ($tag === 'a') {
        $this->handleLink($node);
      }
      // Process images.
      elseif ($tag === 'img') {
        $this->handleImage($node);
      }
      // Process iframes.
      elseif ($tag === 'iframe') {
        $this->handleIframe($node);
      }
      // Process tables.
      elseif ($tag === 'table') {
        $this->handleTable($node);
      }
      // Process list items.
      elseif ($tag === 'li') {
        $this->handleListItem($node);
      }
      // Process tables.
      elseif ($tag === 'font') {
        $this->stripTag($node);
      }
      // Process the node, converting if necessary and removing attributes.
      else {
        if (isset($convert[$tag])) {
          $this->changeTag($node, $convert[$tag]);
        }
        else {
          $this->removeAttributes($node);
        }
      }
    }
    $html = $dom->saveHTML();

    // Search for the body tag and return its content.
    $start = mb_strpos($html, '<body>');
    $end = mb_strrpos($html, '</body>');
    if ($start !== FALSE && $end !== FALSE) {
      $start += 6;
      return trim(mb_substr($html, $start, $end - $start));
    }

    return '';
  }

  /**
   * Check if a node is empty (empty or only whitespaces).
   *
   * @param \DOMNode $node
   *   Node to check.
   *
   * @return bool
   *   TRUE if the node is considered empty.
   */
  protected function isEmpty(\DOMNode $node) {
    // Trim the content, including non-breaking spaces.
    $content = preg_replace('/(?:^\s+)|(?:\s+$)/u', '', $node->textContent);
    // If the element contains images or iframes keep it.
    if (empty($content)) {
      foreach (['meta', 'img', 'iframe'] as $tag) {
        $children = $this->getElementsByTagName($node, $tag);
        if (!empty($children)) {
          return FALSE;
        }
      }
    }
    return empty($content);
  }

  /**
   * Sanitize heading attributes.
   *
   * @param \DOMNode $node
   *   Heading node.
   */
  protected function handleHeading(\DOMNode $node) {
    // Remove all the attributes except the 'id' that we keep to allow
    // internal links.
    $this->removeAttributes($node, ['id']);
  }

  /**
   * Validate link url and sanitize attributes.
   *
   * @todo check if the url is outside reliefweb and add the rel and target
   * attributes.
   *
   * @param \DOMNode $node
   *   Link node.
   */
  protected function handleLink(\DOMNode $node) {
    $url = $node->getAttribute('href');

    // Remove links with an invalid url.
    // @todo check if anchors are preserved.
    if (!$this->validateUrl($url)) {
      // Replace the link with its content.
      if ($node->hasChildNodes()) {
        $fragment = $node->ownerDocument->createDocumentFragment();
        while ($node->firstChild !== NULL) {
          $fragment->appendChild($node->firstChild);
        }
        $node->parentNode->replaceChild($fragment, $node);
      }
      // Remove the link otherwise.
      else {
        $this->removeChild($node);
      }
    }
    // Remove all the attributes except the 'href' and optional 'target'.
    else {
      $allowed_attributes = ['href'];

      // We preserve the target attribute to open in a new tab/window.
      $target = $node->getAttribute('target');
      if ($target === '_blank') {
        // Set the rel attribute to avoid exploitation of the window.opener Api.
        // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/a
        $node->setAttribute('rel', 'noreferrer noopener');
        $allowed_attributes[] = 'target';
        $allowed_attributes[] = 'rel';
      }

      $this->removeAttributes($node, $allowed_attributes);
    }
  }

  /**
   * Validate image url and sanitize attributes.
   *
   * @param \DOMNode $node
   *   Image node.
   */
  protected function handleImage(\DOMNode $node) {
    $url = $node->getAttribute('src');

    // Remove images with an invalid url.
    if (!$this->validateUrl($url)) {
      // Remove the node.
      $this->removeChild($node);
    }
    // Remove all the attributes except the 'src', 'alt' and 'title' ones.
    else {
      // Ensure there is always an alt attribute.
      if (!$node->hasAttribute('alt')) {
        $node->setAttribute('alt', '');
      }
      $this->removeAttributes($node, ['src', 'alt', 'title']);
    }
  }

  /**
   * Validate iframe url and wrap it for responsivity.
   *
   * @todo handle `srcdoc` attribute?
   *
   * @param \DOMNode $node
   *   Iframe node.
   */
  protected function handleIframe(\DOMNode $node) {
    $url = $node->getAttribute('src');

    // Remove iframes with an invalid url.
    if (!$this->validateUrl($url)) {
      $this->removeChild($node);
    }
    else {
      // Create a wrapper for the iframe so it can be made responsive.
      $wrapper = $node->ownerDocument->createElement('div');
      $wrapper->setAttribute('class', 'iframe-wrapper');

      // Extract the aspect ratio of the iframe.
      $width = intval($node->getAttribute('width'), 10);
      $height = intval($node->getAttribute('height'), 10);
      // Default to a 2/1 ratio.
      $ratio = '50';
      if (!empty($width) && !empty($height)) {
        $ratio = 100 * $height / $width;
      }
      // Set a padding top equal to the ratio to the parent so that the
      // the aspect ratio of the iframe can be preserved.
      $wrapper->setAttribute('style', 'padding-top:' . $ratio . '%');

      // Remove most of the attributes.
      $this->removeAttributes($node, ['src', 'title', 'allowfullscreen']);

      // We allow scripts to run in the sandboxed iframe as mostly they
      // contain dynamic content like maps or videos. We also allow opening
      // links into new pages/tabs.
      $node->setAttribute('sandbox', 'allow-same-origin allow-scripts allow-popups');
      $node->setAttribute('target', '_blank');

      // Replace the iframe by the wrapper and append the iframe to it.
      $node->parentNode->replaceChild($wrapper, $node);
      $wrapper->appendChild($node);
    }
  }

  /**
   * Wrap a table for responsivity.
   *
   * @param \DOMNode $node
   *   Table node.
   */
  protected function handleTable(\DOMNode $node) {
    $parent = $node->parentNode;
    $parentClass = $parent->getAttribute('class');
    // Add a wrapper around the table.
    if (empty($parentClass) || strpos($parentClass, 'table-wrapper') === FALSE) {
      // Create a wrapper for the table so it can be made responsive.
      $wrapper = $node->ownerDocument->createElement('div');
      $wrapper->setAttribute('class', 'table-wrapper');

      // Replace the table by the wrapper and append the table to it.
      $parent->replaceChild($wrapper, $node);
      $wrapper->appendChild($node);
    }
  }

  /**
   * Ensure list items have a proper UL or OL parent.
   *
   * @param \DOMNode $node
   *   List item node.
   */
  protected function handleListItem(\DOMNode $node) {
    // Add a list parent to orphan list items.
    if ($node->parentNode->tagName !== 'ul' && $node->parentNode->tagName !== 'ol') {
      $listElement = $node->ownerDocument->createElement('ul');
      $node->parentNode->insertBefore($listElement, $node);
      $sibling = $node;
      while ($sibling !== NULL && ($sibling->nodeType !== 1 || $sibling->tagName === 'li')) {
        $next = $sibling->nextSibling;
        $listElement->appendChild($sibling);
        $sibling = $next;
      }
    }
  }

  /**
   * Remove attributes from a node.
   *
   * @param \DOMNode $node
   *   Node from which to remove attributes.
   * @param array $allowed_attributes
   *   List of allowed attributes.
   */
  protected function removeAttributes(\DOMNode $node, array $allowed_attributes = []) {
    if ($node->hasAttributes()) {
      // Merge the globally allowed attributes with the local ones.
      $allowed_attributes = array_merge($allowed_attributes, $this->allowedAttributes);

      // Flip the attributes for easier checking.
      $allowed_attributes = array_flip($allowed_attributes);

      // Remove unallowed attributes.
      $attributes = $node->attributes;
      for ($i = $attributes->length - 1; $i >= 0; $i--) {
        $attribute = $attributes->item($i)->name;
        if (!isset($allowed_attributes[$attribute])) {
          $node->removeAttribute($attribute);
        }
      }
    }
  }

  /**
   * Remove style and event attributes from a node.
   *
   * @param \DOMNode $node
   *   Node from which to remove attributes.
   */
  protected function sanitizeAttributes(\DOMNode $node) {
    if ($node->hasAttributes()) {
      // Remove unallowed attributes.
      $attributes = $node->attributes;
      for ($i = $attributes->length - 1; $i >= 0; $i--) {
        $attribute = $attributes->item($i)->name;
        if ($attribute === 'style' || strpos($attribute, 'on') === 0) {
          $node->removeAttribute($attribute);
        }
      }
    }
  }

  /**
   * Replace a node by a node with the new tag, moving content and attributes.
   *
   * @param \DOMNode $node
   *   Node to replace.
   * @param string $tag
   *   New tag name.
   * @param array $allowed_attributes
   *   Attributes to move to the new node.
   */
  protected function changeTag(\DOMNode $node, $tag, array $allowed_attributes = []) {
    if (!empty($tag)) {
      $newNode = $node->ownerDocument->createElement($tag);
    }
    else {
      $newNode = $node->ownerDocument->createDocumentFragment();
    }
    // Move the content.
    while ($node->firstChild !== NULL) {
      $newNode->appendChild($node->firstChild);
    }

    // Merge the globally allowed attributes with the local ones.
    $allowed_attributes = array_merge($allowed_attributes, $this->allowedAttributes);

    // Copy the attributes.
    $allowed_attributes = array_flip($allowed_attributes);
    if (!empty($allowed_attributes) && $node->hasAttributes()) {
      foreach ($node->attributes as $attribute) {
        if (isset($allowed_attributes[$attribute->name])) {
          $newNode->setAttribute($attribute->name, $attribute->value);
        }
      }
    }
    $node->parentNode->replaceChild($newNode, $node);
    return $newNode;
  }

  /**
   * Get the nodes matching the tag name.
   *
   * \DOMElement::GetElementsByTagName returns a live collection. We convert it
   * to a flat array so that the nodes can be manipulated during the iteration
   * without creating infinite loops for example when adding iframe wrappers.
   *
   * @param \DOMNode $node
   *   Node (\DOMDocument or \DOMElement)
   * @param string $tag
   *   Tag name or `*` for all nodes.
   *
   * @return array
   *   List of nodes with the given tag name.
   */
  protected function getElementsByTagName(\DOMNode $node, $tag) {
    $elements = [];
    if (method_exists($node, 'getElementsByTagName')) {
      foreach ($node->getElementsByTagName($tag) as $element) {
        $elements[] = $element;
      }
    }
    return $elements;
  }

  /**
   * Remove a child node and its parents if they become empty.
   *
   * @param \DOMNode $node
   *   Node to remove.
   */
  protected function removeChild(\DOMNode $node) {
    $parent = $node->parentNode;
    if (!empty($node->parentNode)) {
      // Remove the node then if the parent is empty remove it as well.
      // Calling `$this->removeChild()` will remove parents until a non empty
      // one is found.
      $parent->removeChild($node);
      if ($this->isEmpty($parent)) {
        $this->removeChild($parent);
      }
    }
  }

  /**
   * Strip a tag.
   *
   * @param \DOMNode $node
   *   Heading node.
   */
  protected function stripTag(\DOMNode $node) {
    $parent = $node->parentNode;
    // Move the content to the parent.
    while ($node->firstChild !== NULL) {
      $parent->insertBefore($node->firstChild, $node);
    }
    // Remove the node.
    $this->removeChild($node);
  }

  /**
   * Validate a URL.
   *
   * @param string $url
   *   URL to validate.
   *
   * @return bool
   *   TRUE if valid.
   *
   * @todo use something more robust than valid_url.
   */
  protected function validateUrl($url) {
    return UrlHelper::isValid($url, UrlHelper::isExternal($url));
  }

}
