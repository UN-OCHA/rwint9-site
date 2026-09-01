<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Helpers;

/**
 * Word n-gram Jaccard similarity for near-duplicate detection.
 */
final class TextJaccardSimilarity {

  /**
   * Compute Jaccard similarity of word n-grams between two texts.
   *
   * @param string $text_a
   *   First normalized plain-text string.
   * @param string $text_b
   *   Second normalized plain-text string.
   * @param int $n
   *   Shingle size in words. Defaults to 3.
   *
   * @return float
   *   Similarity in the range 0.0–1.0.
   */
  public static function similarity(string $text_a, string $text_b, int $n = 3): float {
    if ($n < 1) {
      throw new \InvalidArgumentException('Shingle size must be at least 1.');
    }

    if ($text_a === '' && $text_b === '') {
      return 1.0;
    }
    if ($text_a === '' || $text_b === '') {
      return 0.0;
    }

    if ($text_a === $text_b) {
      return 1.0;
    }

    $set_a = self::wordShingles($text_a, $n);
    $set_b = self::wordShingles($text_b, $n);

    if ($set_a === [] && $set_b === []) {
      return 1.0;
    }

    $intersection = count(array_intersect_key($set_a, $set_b));
    $union = count($set_a) + count($set_b) - $intersection;

    return $union > 0 ? $intersection / $union : 0.0;
  }

  /**
   * Build a set of word n-grams keyed by shingle string.
   *
   * @param string $text
   *   Normalized plain text.
   * @param int $n
   *   Shingle size in words.
   *
   * @return array<string, true>
   *   Set of shingles.
   */
  public static function wordShingles(string $text, int $n = 3): array {
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if ($words === FALSE || $words === []) {
      return [];
    }

    $count = count($words);
    $set = [];
    if ($count < $n) {
      $set[implode(' ', $words)] = TRUE;
      return $set;
    }

    for ($i = 0; $i <= $count - $n; $i++) {
      $set[implode(' ', array_slice($words, $i, $n))] = TRUE;
    }

    return $set;
  }

  /**
   * Length ratio of the shorter string to the longer (0–1).
   *
   * @param string $text_a
   *   First text.
   * @param string $text_b
   *   Second text.
   *
   * @return float
   *   Ratio in 0.0–1.0; 1.0 when both empty.
   */
  public static function lengthRatio(string $text_a, string $text_b): float {
    $len_a = mb_strlen($text_a, 'UTF-8');
    $len_b = mb_strlen($text_b, 'UTF-8');
    if ($len_a === 0 && $len_b === 0) {
      return 1.0;
    }
    $max = max($len_a, $len_b);
    $min = min($len_a, $len_b);
    return $max > 0 ? $min / $max : 0.0;
  }

}
