<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Helpers;

/**
 * Cosine similarity for equal-length float embedding vectors.
 */
final class EmbeddingVectorSimilarity {

  /**
   * Cosine similarity of two embedding vectors.
   *
   * @param float[] $a
   *   First vector.
   * @param float[] $b
   *   Second vector.
   *
   * @return float|null
   *   Similarity in roughly [-1, 1], or NULL when lengths differ or a vector
   *   has zero magnitude.
   */
  public static function cosine(array $a, array $b): ?float {
    if ($a === [] || count($a) !== count($b)) {
      return NULL;
    }

    $dot = 0.0;
    $norm_a = 0.0;
    $norm_b = 0.0;
    foreach ($a as $i => $value_a) {
      if ((!is_int($value_a) && !is_float($value_a))
        || (!is_int($b[$i]) && !is_float($b[$i]))) {
        return NULL;
      }
      $va = (float) $value_a;
      $vb = (float) $b[$i];
      $dot += $va * $vb;
      $norm_a += $va * $va;
      $norm_b += $vb * $vb;
    }

    if ($norm_a <= 0.0 || $norm_b <= 0.0) {
      return NULL;
    }

    return $dot / (sqrt($norm_a) * sqrt($norm_b));
  }

}
