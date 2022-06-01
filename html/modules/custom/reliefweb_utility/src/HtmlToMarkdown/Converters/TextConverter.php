<?php

namespace Drupal\reliefweb_utility\HtmlToMarkdown\Converters;

use League\HTMLToMarkdown\Converter\TextConverter as TextConverterBase;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Text converter that prevents unwanted escaping.
 */
class TextConverter extends TextConverterBase {

  /**
   * {@inheritdoc}
   */
  public function convert(ElementInterface $element): string {
    $markdown = $element->getValue();

    // Remove leftover "\n" at the beginning of the line.
    $markdown = ltrim($markdown, "\n");

    // Replace sequences of invisible characters with spaces.
    $markdown = preg_replace('~\s+~u', ' ', $markdown);

    // Skip escaping if we are in a DIV.
    $parent = $element->getParent();
    if (!$parent || $parent->getTagName() !== 'div') {
      // Split the text so we can determine if we are in a URL and skip
      // character escaping in that case.
      $parts = explode(' ', $markdown);
      foreach ($parts as $key => $part) {
        if (preg_match('~^https?://~u', $part) !== 1) {
          // Escape the following characters: '*', '_', '[', ']' and '\'.
          $parts[$key] = preg_replace('~([*_\\[\\]\\\\])~u', '\\\\$1', $part);
        }
      }
      $markdown = implode(' ', $parts);
    }

    // Escape starting '#' characters to prevent transformation to headings.
    $markdown = preg_replace('~^#~u', '\\\\#', $markdown);

    // Trim if there is no sibling or the sibling is a block.
    if ($markdown === ' ') {
      $next = $element->getNext();
      if (!$next || $next->isBlock()) {
        $markdown = '';
      }
    }

    // Preserve "html entities".
    $markdown = preg_replace('~&(([0-9a-z]+)|(&#x[0-9a-f]+)|(&#[0-9]+));~i', '&amp;$1;', $markdown);

    return $markdown;
  }

}
