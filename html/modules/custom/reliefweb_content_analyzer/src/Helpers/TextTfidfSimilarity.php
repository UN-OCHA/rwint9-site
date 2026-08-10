<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Helpers;

/**
 * Pairwise TF-IDF cosine similarity for soft near-duplicate detection.
 */
final class TextTfidfSimilarity {

  /**
   * Compute cosine similarity of pairwise TF-IDF vectors.
   *
   * IDF is computed over the two-document corpus only. Input texts should
   * already be normalized plain text (same as Jaccard scoring). Stopwords are
   * removed using {@see Stopwords} for the given language codes.
   *
   * @param string $text_a
   *   First normalized plain-text string.
   * @param string $text_b
   *   Second normalized plain-text string.
   * @param string[] $language_codes
   *   ISO 639-1 codes for stopword filtering. Empty uses all supported lists.
   *
   * @return float
   *   Similarity in the range 0.0–1.0.
   */
  public static function similarity(string $text_a, string $text_b, array $language_codes = []): float {
    if ($text_a === '' && $text_b === '') {
      return 1.0;
    }
    if ($text_a === '' || $text_b === '') {
      return 0.0;
    }

    if ($text_a === $text_b) {
      return 1.0;
    }

    $tf_a = self::termFrequencies($text_a, $language_codes);
    $tf_b = self::termFrequencies($text_b, $language_codes);
    if ($tf_a === [] || $tf_b === []) {
      return 0.0;
    }

    $vocab = $tf_a + $tf_b;
    $vector_a = [];
    $vector_b = [];
    foreach (array_keys($vocab) as $term) {
      $df = (isset($tf_a[$term]) ? 1 : 0) + (isset($tf_b[$term]) ? 1 : 0);
      // Smoothed IDF over a two-document corpus.
      $idf = log((2 + 1) / ($df + 1)) + 1.0;
      $vector_a[$term] = ($tf_a[$term] ?? 0) * $idf;
      $vector_b[$term] = ($tf_b[$term] ?? 0) * $idf;
    }

    return self::cosine($vector_a, $vector_b);
  }

  /**
   * Build term frequency map from whitespace-separated tokens.
   *
   * @param string $text
   *   Normalized plain text.
   * @param string[] $language_codes
   *   ISO 639-1 codes for stopword filtering.
   *
   * @return array<string, int>
   *   Term => count.
   */
  public static function termFrequencies(string $text, array $language_codes = []): array {
    $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === FALSE || $tokens === []) {
      return [];
    }

    $stopwords = Stopwords::setForLanguages($language_codes);
    $counts = [];
    foreach ($tokens as $token) {
      $folded = mb_strtolower($token, 'UTF-8');
      if (isset($stopwords[$folded])) {
        continue;
      }
      $counts[$folded] = ($counts[$folded] ?? 0) + 1;
    }
    return $counts;
  }

  /**
   * Cosine similarity of two sparse non-negative vectors.
   *
   * @param array<string, float> $vector_a
   *   First vector.
   * @param array<string, float> $vector_b
   *   Second vector.
   *
   * @return float
   *   Cosine in 0.0–1.0.
   */
  protected static function cosine(array $vector_a, array $vector_b): float {
    $dot = 0.0;
    $norm_a = 0.0;
    $norm_b = 0.0;

    foreach ($vector_a as $term => $weight_a) {
      $norm_a += $weight_a * $weight_a;
      if (isset($vector_b[$term])) {
        $dot += $weight_a * $vector_b[$term];
      }
    }
    foreach ($vector_b as $weight_b) {
      $norm_b += $weight_b * $weight_b;
    }

    if ($norm_a <= 0.0 || $norm_b <= 0.0) {
      return 0.0;
    }

    return $dot / (sqrt($norm_a) * sqrt($norm_b));
  }

}
