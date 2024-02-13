<?php

namespace Drupal\reliefweb_utility\Helpers;

use cogpowered\FineDiff\Diff;

/**
 * Helper to manipulate texts.
 */
class TextHelper {

  /**
   * Clean a text.
   *
   * 1. Replace tabulations with double spaces.
   * 2. Replace non breaking spaces with normal spaces.
   * 3. Remove control characters (except line feed).
   * 4. Optionally, replace line breaks and consecutive whitespaces.
   * 5. Trim the text.
   *
   * @param string $text
   *   Text to clean.
   * @param array $options
   *   Associative array with the following replacement options:
   *   - line_breaks (boolean): replace line breaks with spaces.
   *   - consecutive (boolean): replace consecutive whitespaces with single
   *     space.
   *
   * @return string
   *   Cleaned text.
   */
  public static function cleanText($text, array $options = []) {
    if ($text === '') {
      return '';
    }
    $patterns = ['/[\t]/u', '/[\xA0]+/u', '/[\x00-\x09\x0B-\x1F\x7F]/u'];
    $replacements = ['  ', ' ', ''];
    // Replace (consecutive) line breaks with a single space.
    if (!empty($options['line_breaks'])) {
      $patterns[] = '/[\x0A]+/u';
      $replacements[] = ' ';
    }
    // Replace consecutive whitespaces with single space.
    if (!empty($options['consecutive'])) {
      $patterns[] = '/\s{2,}/u';
      $replacements[] = ' ';
    }
    return static::trimText(preg_replace($patterns, $replacements, $text));
  }

  /**
   * Trim a text (extended version, removing Z and C unicode categories).
   *
   * @param string $text
   *   Text to trim.
   *
   * @return string
   *   Trimmed text.
   */
  public static function trimText($text) {
    return preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $text);
  }

  /**
   * Remove embedded content in html or markdown format from the given text.
   *
   * Note: it's using a very basic pattern matching that may not work with
   * broken html (missing </iframe> ending tag for example)
   *
   * @param string $text
   *   Text to clean.
   *
   * @return string
   *   Cleaned up text.
   */
  public static function stripEmbeddedContent($text) {
    $patterns = [
      "<embed [^>]+>",
      "<img [^>]+>",
      "<param [^>]+>",
      "<source [^>]+>",
      "<track [^>]+>",
      "<audio [^>]+>.*</audio>",
      "<iframe [^>]+>.*</iframe>",
      "<map [^>]+>.*</map>",
      "<object [^>]+>.*</object>",
      "<video [^>]+>.*</video>",
      "<svg [^>]+>.*</svg>",
      "!\[[^\]]*\]\([^\)]+\)",
      "\[iframe[^\]]*\]\([^\)]+\)",
    ];
    return preg_replace('@' . implode("|", $patterns) . '@i', '', $text);
  }

  /**
   * Get the formatted difference between 2 texts.
   *
   * @param string $from_text
   *   Original text.
   * @param string $to_text
   *   Modified text.
   *
   * @return string
   *   HTML text with the differences between the 2 provided texts highlighted.
   */
  public static function getTextDiff($from_text, $to_text) {
    $diff = new Diff();
    return $diff->render($from_text, $to_text);
  }

}
