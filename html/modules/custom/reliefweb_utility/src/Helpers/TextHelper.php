<?php

namespace Drupal\reliefweb_utility\Helpers;

use cogpowered\FineDiff\Diff;
use cogpowered\FineDiff\Granularity\Character;
use cogpowered\FineDiff\Granularity\Paragraph;
use cogpowered\FineDiff\Granularity\Sentence;
use cogpowered\FineDiff\Granularity\Word;

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
   * Sanitize a UTF-8 string.
   *
   * This method performs the following operations:
   * 1. Replaces all whitespace characters with a single space.
   * 2. Replaces consecutive spaces with a single space.
   * 3. Removes all Unicode control characters.
   * 4. Removes heading and trailing spaces from the text.
   *
   * Optionally it also preserves new lines but collapses consecutive ones.
   *
   * @param string $text
   *   The input UTF-8 string to be processed.
   * @param bool $preserve_newline
   *   If TRUE, ensure the new lines are preserved.
   * @param int $max_consecutive_newlines
   *   Maximum number of consecutive newlines to preserve (default: 1).
   *   Only applies when $preserve_newline is TRUE and there are multiple
   *   consecutive newlines.
   *
   * @return string
   *   Sanitized text.
   */
  public static function sanitizeText(string $text, bool $preserve_newline = FALSE, int $max_consecutive_newlines = 1): string {
    if ($preserve_newline) {
      // Ensure max_consecutive_newlines is at least 1.
      $max_consecutive_newlines = max(1, $max_consecutive_newlines);

      // Replace consecutive newlines (2 or more) with placeholders
      // This preserves single newlines as-is.
      $text = preg_replace('/(?:\r?\n\r?){2,}/', '{{{{CONSECUTIVE_NEWLINES}}}}', $text);

      // Replace single newlines with a different placeholder.
      $text = preg_replace('/(?:\r?\n\r?)/', '{{{{SINGLE_NEWLINE}}}}', $text);
    }

    // Replace HTML non breaking spaces.
    $text = str_replace(['&nbsp;', '&#160;'], ' ', $text);

    // Replace all whitespace characters (including non-breaking spaces) with
    // a single space.
    $text = preg_replace('/\p{Z}+/u', ' ', $text);

    // Replace consecutive spaces with a single space.
    $text = preg_replace('/\s+/u', ' ', $text);

    // Remove all control and format characters.
    $text = preg_replace('/\p{C}/u', '', $text);

    if ($preserve_newline) {
      // Replace consecutive newline placeholders with the specified maximum.
      $consecutive_replacement = str_repeat("\n", $max_consecutive_newlines);
      $text = str_replace('{{{{CONSECUTIVE_NEWLINES}}}}', $consecutive_replacement, $text);

      // Replace single newline placeholders with single newlines.
      $text = str_replace('{{{{SINGLE_NEWLINE}}}}', "\n", $text);
    }

    return static::trimText($text);
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
   * @param string $granularity
   *   Granularity to use for the diff (character, word, sentence, paragraph).
   *   Defaults to Word to have decent performance while still being able to
   *   highlight the differences between the two texts.
   *
   * @return string
   *   HTML text with the differences between the 2 provided texts highlighted.
   */
  public static function getTextDiff(string $from_text, string $to_text, string $granularity = 'word'): string {
    $diff_granularity = match ($granularity) {
      'character' => new Character(),
      'word' => new Word(),
      'sentence' => new Sentence(),
      'paragraph' => new Paragraph(),
      default => new Word(),
    };
    $diff = new Diff($diff_granularity);
    return $diff->render($from_text, $to_text);
  }

  /**
   * Calculate the percentage of similarity between two Unicode texts.
   *
   * This method uses the Levenshtein distance algorithm to calculate the
   * similarity between two texts. The similarity is calculated as:
   * (1 - (levenshtein_distance / max_length)) * 100
   *
   * @param string $text1
   *   First text to compare.
   * @param string $text2
   *   Second text to compare.
   * @param bool $case_sensitive
   *   Whether the comparison should be case sensitive. Defaults to FALSE.
   * @param bool $normalize_whitespace
   *   Whether to normalize whitespace before comparison. Defaults to TRUE.
   *
   * @return float
   *   Percentage of similarity between the two texts (0-100).
   */
  public static function getTextSimilarity(string $text1, string $text2, bool $case_sensitive = FALSE, bool $normalize_whitespace = TRUE): float {
    // Handle empty strings.
    if ($text1 === '' && $text2 === '') {
      return 100.0;
    }
    if ($text1 === '' || $text2 === '') {
      return 0.0;
    }

    // Normalize texts if requested.
    if (!$case_sensitive) {
      $text1 = mb_strtolower($text1, 'UTF-8');
      $text2 = mb_strtolower($text2, 'UTF-8');
    }

    if ($normalize_whitespace) {
      $text1 = static::sanitizeText($text1);
      $text2 = static::sanitizeText($text2);
    }

    // If both texts are identical after normalization.
    if ($text1 === $text2) {
      return 100.0;
    }

    // Calculate character-based similarity using Levenshtein distance.
    $length1 = mb_strlen($text1, 'UTF-8');
    $length2 = mb_strlen($text2, 'UTF-8');
    $max_length = max($length1, $length2);

    // Calculate Levenshtein distance for Unicode strings.
    $levenshtein_distance = static::calculateUnicodeLevenshteinDistance($text1, $text2);

    // Calculate similarity percentage.
    $similarity = (1 - ($levenshtein_distance / $max_length)) * 100;

    // Ensure the result is between 0 and 100.
    return max(0.0, min(100.0, $similarity));
  }

  /**
   * Calculate Levenshtein distance for Unicode strings.
   *
   * This is a Unicode-safe implementation of the Levenshtein distance
   * algorithm that works with multibyte characters.
   *
   * @param string $string1
   *   First string.
   * @param string $string2
   *   Second string.
   *
   * @return int
   *   The Levenshtein distance between the two strings.
   */
  protected static function calculateUnicodeLevenshteinDistance(string $string1, string $string2): int {
    // Convert strings to arrays of Unicode characters.
    $chars1 = preg_split('//u', $string1, -1, PREG_SPLIT_NO_EMPTY);
    $chars2 = preg_split('//u', $string2, -1, PREG_SPLIT_NO_EMPTY);

    $length1 = count($chars1);
    $length2 = count($chars2);

    // Create a matrix to store distances.
    $matrix = [];

    // Initialize first row and column.
    for ($i = 0; $i <= $length1; $i++) {
      $matrix[$i][0] = $i;
    }
    for ($j = 0; $j <= $length2; $j++) {
      $matrix[0][$j] = $j;
    }

    // Fill the matrix.
    for ($i = 1; $i <= $length1; $i++) {
      for ($j = 1; $j <= $length2; $j++) {
        $cost = ($chars1[$i - 1] === $chars2[$j - 1]) ? 0 : 1;
        $matrix[$i][$j] = min(
          // Deletion.
          $matrix[$i - 1][$j] + 1,
          // Insertion.
          $matrix[$i][$j - 1] + 1,
          // Substitution.
          $matrix[$i - 1][$j - 1] + $cost
        );
      }
    }

    return $matrix[$length1][$length2];
  }

}
