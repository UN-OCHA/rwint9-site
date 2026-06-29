<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Enum;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * User posting right levels for jobs, training, and reports.
 */
enum PostingRight: int {

  case Unverified = 0;
  case Blocked = 1;
  case Allowed = 2;
  case Trusted = 3;

  /**
   * Get the machine name used in config and templates.
   *
   * @return string
   *   Machine name.
   */
  public function machineName(): string {
    return match ($this) {
      self::Blocked => 'blocked',
      self::Allowed => 'allowed',
      self::Trusted => 'trusted',
      self::Unverified => 'unverified',
    };
  }

  /**
   * Get the human-readable label.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Human-readable label.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Unverified => new TranslatableMarkup('Unverified'),
      self::Blocked => new TranslatableMarkup('Blocked'),
      self::Allowed => new TranslatableMarkup('Allowed'),
      self::Trusted => new TranslatableMarkup('Trusted'),
    };
  }

  /**
   * Whether the right is blocked.
   *
   * @return bool
   *   TRUE if the right is blocked, FALSE otherwise.
   */
  public function isBlocked(): bool {
    return $this === self::Blocked;
  }

  /**
   * Whether the right is allowed or trusted.
   *
   * @return bool
   *   TRUE if the right is allowed or trusted, FALSE otherwise.
   */
  public function isAllowedOrTrusted(): bool {
    return $this === self::Allowed || $this === self::Trusted;
  }

  /**
   * Whether the right is trusted.
   *
   * @return bool
   *   TRUE if the right is trusted, FALSE otherwise.
   */
  public function isTrusted(): bool {
    return $this === self::Trusted;
  }

  /**
   * Try to create a posting right from a machine name.
   *
   * @param string|null $name
   *   Machine name.
   *
   * @return self|null
   *   Posting right or NULL if the name is empty or not valid.
   */
  public static function tryFromMachineName(?string $name): ?self {
    if ($name === NULL || $name === '') {
      return NULL;
    }
    return match ($name) {
      'blocked' => self::Blocked,
      'allowed' => self::Allowed,
      'trusted' => self::Trusted,
      'unverified' => self::Unverified,
      default => NULL,
    };
  }

  /**
   * Create a posting right from a machine name, defaulting to unverified.
   *
   * @param string|null $name
   *   Machine name.
   *
   * @return self
   *   Posting right.
   */
  public static function fromMachineName(?string $name): self {
    return self::tryFromMachineName($name) ?? self::Unverified;
  }

  /**
   * Try to create a posting right from a stored value.
   *
   * @param int|string|null $value
   *   Stored value.
   *
   * @return self|null
   *   Posting right or NULL if the value is not valid.
   */
  public static function tryFromValue(int|string|null $value): ?self {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return self::tryFrom((int) $value);
  }

  /**
   * Create a posting right from a stored value, defaulting to unverified.
   *
   * @param int|string|null $value
   *   Stored value.
   *
   * @return self
   *   Posting right.
   */
  public static function fromValue(int|string|null $value): self {
    return self::tryFromValue($value) ?? self::Unverified;
  }

  /**
   * Get all valid stored values.
   *
   * @return list<int>
   *   List of valid stored values.
   */
  public static function values(): array {
    return array_map(static fn(self $case): int => $case->value, self::cases());
  }

  /**
   * Get form options keyed by stored value.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Form options keyed by stored value.
   */
  public static function options(): array {
    $options = [];
    foreach (self::cases() as $case) {
      $options[$case->value] = $case->label();
    }
    return $options;
  }

  /**
   * Get labeled cases for UI display indexed by stored value.
   *
   * @return array<int, array{type: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   Labeled cases indexed by stored value.
   */
  public static function labeledCases(): array {
    $cases = [];
    foreach (self::cases() as $case) {
      $cases[$case->value] = [
        'type' => $case->machineName(),
        'label' => $case->label(),
      ];
    }
    return $cases;
  }

  /**
   * Get form options keyed by machine name.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Form options keyed by machine name.
   */
  public static function machineNameOptions(): array {
    $options = [];
    foreach (self::cases() as $case) {
      $options[$case->machineName()] = $case->label();
    }
    return $options;
  }

  /**
   * Get labels indexed by stored value for JavaScript settings.
   *
   * @return array<int, string>
   *   Labels indexed by stored value for JavaScript settings.
   */
  public static function jsLabels(): array {
    $labels = [];
    foreach (self::cases() as $case) {
      $labels[$case->value] = (string) $case->label();
    }
    return $labels;
  }

}
