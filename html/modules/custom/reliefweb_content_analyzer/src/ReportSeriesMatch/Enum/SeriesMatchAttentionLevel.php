<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum;

/**
 * Editor attention level for a proposed series match field update.
 */
enum SeriesMatchAttentionLevel: string {

  case Ok = 'ok';
  case Info = 'info';
  case Warning = 'warning';
  case Error = 'error';

  /**
   * Returns the emoji indicator for this attention level.
   */
  public function indicator(): string {
    return match ($this) {
      self::Ok => '✅',
      self::Info => 'ℹ️',
      self::Warning => '⚠️',
      self::Error => '❌',
    };
  }

  /**
   * Returns a short English label for legends and tooltips.
   */
  public function label(): string {
    return match ($this) {
      self::Ok => 'High confidence',
      self::Info => 'Review suggested',
      self::Warning => 'Weaker source',
      self::Error => 'Not applied / failed',
    };
  }

}
