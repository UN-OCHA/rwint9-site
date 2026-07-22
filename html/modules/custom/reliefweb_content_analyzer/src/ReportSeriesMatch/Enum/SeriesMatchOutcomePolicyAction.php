<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum;

/**
 * Policy action that ceilings or skips series match application.
 */
enum SeriesMatchOutcomePolicyAction: string {

  case None = 'none';
  case MaxMedium = 'max_medium';
  case MaxLow = 'max_low';
  case SkipMatch = 'skip_match';

  /**
   * Severity order; higher means stricter.
   *
   * @return int
   *   Severity level:
   *   - 0 for None,
   *   - 1 for MaxMedium,
   *   - 2 for MaxLow,
   *   - 3 for SkipMatch.
   */
  public function severity(): int {
    return match ($this) {
      self::None => 0,
      self::MaxMedium => 1,
      self::MaxLow => 2,
      self::SkipMatch => 3,
    };
  }

  /**
   * Returns the stricter of two policy actions.
   *
   * @param self $other
   *   Other policy action to compare with.
   *
   * @return self
   *   The stricter policy action.
   */
  public function stricter(self $other): self {
    return $this->severity() >= $other->severity() ? $this : $other;
  }

  /**
   * Applies a ceiling action to an outcome tier.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier $tier
   *   Current outcome tier.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomeTier
   *   Ceilinged tier, unchanged for None / SkipMatch.
   */
  public function applyToTier(SeriesMatchOutcomeTier $tier): SeriesMatchOutcomeTier {
    $ceiling = match ($this) {
      self::MaxMedium => SeriesMatchOutcomeTier::Medium,
      self::MaxLow => SeriesMatchOutcomeTier::Low,
      self::None, self::SkipMatch => NULL,
    };
    return $ceiling === NULL ? $tier : $tier->capAt($ceiling);
  }

  /**
   * Parses a config action string.
   *
   * @param string $value
   *   Config action value (e.g. none, max_medium, max_low, skip_match).
   *
   * @return self
   *   Matching action case.
   *
   * @throws \InvalidArgumentException
   *   When the value is not a known action.
   */
  public static function fromConfig(string $value): self {
    $action = self::tryFrom($value);
    if ($action === NULL) {
      throw new \InvalidArgumentException("Unknown outcome policy action: {$value}.");
    }
    return $action;
  }

}
