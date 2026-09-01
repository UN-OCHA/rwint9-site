<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum;

/**
 * Confidence / outcome tier for report series matching.
 */
enum SeriesMatchOutcomeTier: string {

  case Low = 'low';
  case Medium = 'medium';
  case High = 'high';

  /**
   * Severity order; higher means more permissive (less restrictive).
   *
   * @return int
   *   Severity level: 0 for Low, 1 for Medium, 2 for High.
   */
  public function severity(): int {
    return match ($this) {
      self::Low => 0,
      self::Medium => 1,
      self::High => 2,
    };
  }

  /**
   * Returns the lower (more restrictive) of two tiers.
   *
   * @param self $other
   *   Other tier to compare with.
   *
   * @return self
   *   The more restrictive tier.
   */
  public function min(self $other): self {
    return $this->severity() <= $other->severity() ? $this : $other;
  }

  /**
   * Returns the stricter (lower) of this tier and a ceiling.
   *
   * @param self $ceiling
   *   Maximum allowed tier.
   *
   * @return self
   *   This tier when already at or below the ceiling, otherwise the ceiling.
   */
  public function capAt(self $ceiling): self {
    return $this->severity() <= $ceiling->severity() ? $this : $ceiling;
  }

  /**
   * Maps a confidence score to a tier using high/medium thresholds.
   *
   * @param float $score
   *   Confidence score in the range 0–1.
   * @param array{high: float, medium: float} $tiers
   *   Tier thresholds keyed by tier name.
   *
   * @return self
   *   Mapped tier.
   */
  public static function fromScore(float $score, array $tiers): self {
    if ($score >= $tiers['high']) {
      return self::High;
    }
    if ($score >= $tiers['medium']) {
      return self::Medium;
    }
    return self::Low;
  }

}
