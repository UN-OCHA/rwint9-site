<?php

declare(strict_types=1);

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Fuzzy string similarity helpers (RapidFuzz-compatible ratios).
 *
 * Scores are in [0, 100]. Ratio / partial / token-set use Indel similarity
 * (insert/delete only), matching rapidfuzz.fuzz — not plain Levenshtein.
 */
final class FuzzyTextHelper {

  /**
   * Unicode grapheme Levenshtein distance (insert/delete/substitute).
   *
   * @param string $a
   *   First string.
   * @param string $b
   *   Second string.
   *
   * @return int
   *   Edit distance in grapheme units.
   */
  public static function levenshteinDistance(string $a, string $b): int {
    if ($a === $b) {
      return 0;
    }
    $distance = grapheme_levenshtein($a, $b);
    return $distance === FALSE ? 0 : $distance;
  }

  /**
   * Indel (LCS) distance: insertions and deletions only.
   *
   * Equivalent to len(a) + len(b) - 2 * LCS(a, b) in grapheme units.
   *
   * @param string $a
   *   First string.
   * @param string $b
   *   Second string.
   *
   * @return int
   *   Indel distance.
   */
  public static function indelDistance(string $a, string $b): int {
    if ($a === $b) {
      return 0;
    }
    $chars_a = self::graphemes($a);
    $chars_b = self::graphemes($b);
    $len_a = count($chars_a);
    $len_b = count($chars_b);
    if ($len_a === 0) {
      return $len_b;
    }
    if ($len_b === 0) {
      return $len_a;
    }
    return $len_a + $len_b - (2 * self::longestCommonSubsequenceLength($chars_a, $chars_b));
  }

  /**
   * RapidFuzz fuzz.ratio — Indel normalized similarity (0–100).
   *
   * @param string $a
   *   First string.
   * @param string $b
   *   Second string.
   * @param bool $case_insensitive
   *   When TRUE, compare lowercased copies.
   *
   * @return float
   *   Similarity score in [0, 100].
   */
  public static function ratio(string $a, string $b, bool $case_insensitive = TRUE): float {
    if ($case_insensitive) {
      $a = mb_strtolower($a, 'UTF-8');
      $b = mb_strtolower($b, 'UTF-8');
    }
    if ($a === '' && $b === '') {
      return 100.0;
    }
    if ($a === '' || $b === '') {
      return 0.0;
    }
    if ($a === $b) {
      return 100.0;
    }

    $len_a = grapheme_strlen($a) ?: 0;
    $len_b = grapheme_strlen($b) ?: 0;
    $total = $len_a + $len_b;
    if ($total === 0) {
      return 100.0;
    }

    $distance = self::indelDistance($a, $b);
    return max(0.0, min(100.0, (1.0 - ($distance / $total)) * 100.0));
  }

  /**
   * RapidFuzz fuzz.partial_ratio — best substring Indel similarity (0–100).
   *
   * @param string $a
   *   First string.
   * @param string $b
   *   Second string.
   * @param bool $case_insensitive
   *   When TRUE, compare lowercased copies.
   *
   * @return float
   *   Similarity score in [0, 100].
   */
  public static function partialRatio(string $a, string $b, bool $case_insensitive = TRUE): float {
    if ($case_insensitive) {
      $a = mb_strtolower($a, 'UTF-8');
      $b = mb_strtolower($b, 'UTF-8');
    }
    if ($a === '' || $b === '') {
      return 0.0;
    }
    if ($a === $b) {
      return 100.0;
    }

    $chars_a = self::graphemes($a);
    $chars_b = self::graphemes($b);
    if (count($chars_a) > count($chars_b)) {
      [$chars_a, $chars_b] = [$chars_b, $chars_a];
      [$a, $b] = [$b, $a];
    }

    $short_len = count($chars_a);
    $long_len = count($chars_b);
    if ($short_len === $long_len) {
      return self::ratio($a, $b, FALSE);
    }

    $best = 0.0;
    for ($offset = 0; $offset <= $long_len - $short_len; $offset++) {
      $window = implode('', array_slice($chars_b, $offset, $short_len));
      $best = max($best, self::ratio($a, $window, FALSE));
      if ($best >= 100.0) {
        return 100.0;
      }
    }
    return $best;
  }

  /**
   * RapidFuzz fuzz.token_set_ratio (0–100).
   *
   * @param string $a
   *   First string.
   * @param string $b
   *   Second string.
   * @param bool $case_insensitive
   *   When TRUE, compare lowercased copies.
   *
   * @return float
   *   Similarity score in [0, 100].
   */
  public static function tokenSetRatio(string $a, string $b, bool $case_insensitive = TRUE): float {
    if ($case_insensitive) {
      $a = mb_strtolower($a, 'UTF-8');
      $b = mb_strtolower($b, 'UTF-8');
    }
    if ($a === '' || $b === '') {
      return ($a === '' && $b === '') ? 100.0 : 0.0;
    }

    $tokens_a = self::tokenize($a);
    $tokens_b = self::tokenize($b);
    if ($tokens_a === [] && $tokens_b === []) {
      return 100.0;
    }
    if ($tokens_a === [] || $tokens_b === []) {
      return 0.0;
    }

    $set_a = array_unique($tokens_a);
    $set_b = array_unique($tokens_b);
    $intersection = array_values(array_intersect($set_a, $set_b));
    $diff_a = array_values(array_diff($set_a, $set_b));
    $diff_b = array_values(array_diff($set_b, $set_a));
    sort($intersection, \SORT_STRING);
    sort($diff_a, \SORT_STRING);
    sort($diff_b, \SORT_STRING);

    $sorted_sect = implode(' ', $intersection);
    $combined_a = trim($sorted_sect . ' ' . implode(' ', $diff_a));
    $combined_b = trim($sorted_sect . ' ' . implode(' ', $diff_b));

    return max(
      self::ratio($sorted_sect, $combined_a, FALSE),
      self::ratio($sorted_sect, $combined_b, FALSE),
      self::ratio($combined_a, $combined_b, FALSE),
    );
  }

  /**
   * Component↔line score used by series-title matching.
   *
   * Prefers token_set_ratio; allows partial_ratio only when it is within 10
   * points of token_set (avoids short shared tails like "Tchad").
   *
   * @param string $a
   *   First string (e.g. title component).
   * @param string $b
   *   Second string (e.g. PDF line).
   *
   * @return float
   *   Similarity score in [0, 100].
   */
  public static function fuzzyBestScore(string $a, string $b): float {
    if ($a === '' || $b === '') {
      return 0.0;
    }

    $partial = self::partialRatio($a, $b);
    $token_set = self::tokenSetRatio($a, $b);
    if ($token_set >= $partial) {
      return $token_set;
    }
    if ($token_set >= $partial - 10.0) {
      return $partial;
    }
    return $token_set;
  }

  /**
   * Split a string into grapheme clusters.
   *
   * @param string $string
   *   Input string.
   *
   * @return string[]
   *   Grapheme list.
   */
  private static function graphemes(string $string): array {
    if ($string === '') {
      return [];
    }
    $parts = grapheme_str_split($string);
    return $parts === FALSE ? [] : $parts;
  }

  /**
   * Whitespace tokenization.
   *
   * @param string $string
   *   Input string.
   *
   * @return string[]
   *   Non-empty tokens.
   */
  private static function tokenize(string $string): array {
    $string = trim(preg_replace('/\s+/u', ' ', $string) ?? $string);
    if ($string === '') {
      return [];
    }
    return preg_split('/\s+/u', $string, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  }

  /**
   * Longest common subsequence length for grapheme arrays.
   *
   * @param string[] $a
   *   First grapheme list.
   * @param string[] $b
   *   Second grapheme list.
   *
   * @return int
   *   LCS length.
   */
  private static function longestCommonSubsequenceLength(array $a, array $b): int {
    $len_a = count($a);
    $len_b = count($b);
    // Use two rolling rows to limit memory.
    $prev = array_fill(0, $len_b + 1, 0);
    $curr = array_fill(0, $len_b + 1, 0);
    for ($i = 1; $i <= $len_a; $i++) {
      for ($j = 1; $j <= $len_b; $j++) {
        if ($a[$i - 1] === $b[$j - 1]) {
          $curr[$j] = $prev[$j - 1] + 1;
        }
        else {
          $curr[$j] = max($prev[$j], $curr[$j - 1]);
        }
      }
      $prev = $curr;
      $curr = array_fill(0, $len_b + 1, 0);
    }
    return $prev[$len_b];
  }

}
