<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;

/**
 * Helper to summarize an HTML string.
 */
class HtmlSummarizer {

  /**
   * Summarize and truncate a HTML text to a given length.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $html
   *   HTML to summarize.
   * @param int $length
   *   Maximum length of the text.
   * @param bool $plain_text
   *   Return the truncated text as plain text when set to TRUE or as
   *   HTML paragraphs when FALSE.
   *
   * @return string
   *   Truncated text.
   */
  public static function summarize($html, $length = 600, $plain_text = TRUE) {
    if (!is_string($html) && !($html instanceof MarkupInterface)) {
      return '';
    }

    static $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;
    static $pattern = ['/^\s+|\s+$/u', '/\s{2,}/u'];
    static $replacement = ['', ' '];
    static $end_marks = ";.!?。؟ \t\n\r\0\x0B";
    static $tags = [
      'p' => TRUE,
      'blockquote' => TRUE,
      'ul' => TRUE,
      'ol' => TRUE,
      'h1' => TRUE,
      'h2' => TRUE,
      'h3' => TRUE,
      'h4' => TRUE,
      'h5' => TRUE,
      'h6' => TRUE,
    ];

    // Trim.
    $html = trim($html);

    if (empty($html)) {
      return '';
    }

    // Convert break lines to spaces.
    $html = preg_replace('#<br[ /]*>#', ' ', $html);

    // Extract the paragraphs from the html string.
    $paragraphs = [];
    $text_length = 0;
    $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $dom = new \DomDocument();
    $dom->loadHTML($meta . $html, $flags);

    // Try to get the body.
    $body = $dom->getElementsByTagName('body')[0];
    if (!$body) {
      return '';
    }

    foreach ($body->childNodes as $node) {
      if (isset($node->tagName, $tags[$node->tagName])) {
        // Sanitize multiple consecutive white spaces and trim the paragraph.
        $paragraph = preg_replace($pattern, $replacement, $node->textContent);
        $paragraphs[] = $paragraph;
        $text_length += mb_strlen($paragraph);
      }
    }

    // Nothing to return if we couldn't extract paragraphs.
    if (empty($paragraphs)) {
      return '';
    }

    if ($plain_text) {
      $prefix = '';
      $suffix = '';
      $separator = ' ';
      $delta = 1;
    }
    else {
      $prefix = '<p>';
      $suffix = '</p>';
      $separator = '</p><p>';
      $delta = 0;
    }

    // Truncate the text to the given length if longer.
    if ($length > 0 && $text_length > $length) {
      foreach ($paragraphs as $index => $paragraph) {
        $parts = preg_split('/([\s\n\r]+)/u', $paragraph, NULL, PREG_SPLIT_DELIM_CAPTURE);
        $parts_count = count($parts);

        for ($i = 0; $i < $parts_count; ++$i) {
          if (($length -= mb_strlen($parts[$i])) <= 0) {
            // Truncate the paragraph and add an ellipsis.
            $paragraphs[$index] = trim(implode(array_slice($parts, 0, $i)), $end_marks) . '...';
            // Truncate the list of paragraphs.
            $paragraphs = array_slice($paragraphs, 0, $index + 1);
            // Break from both loops.
            break 2;
          }
        }

        // Adjust the length to reflect the added space separator when
        // returning the text as plain text.
        $length -= $delta;
      }
    }

    // If HTML is requested, we escape the paragraphs.
    if (!$plain_text) {
      foreach ($paragraphs as &$paragraph) {
        $paragraph = Html::escape($paragraph);
      }
    }

    $result = $prefix . implode($separator, $paragraphs) . $suffix;
    $result = preg_replace('#\s{2,}#', ' ', $result);
    return trim($result);
  }

}
