<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto;

/**
 * One embeddable source (entity type + bundle).
 */
final class EmbeddingSourceSettings {

  /**
   * Allowed field machine names for the report source UI / CLI.
   */
  public const ALLOWED_FIELDS = ['title', 'body', 'field_file'];

  /**
   * Constructs EmbeddingSourceSettings.
   *
   * @param bool $enabled
   *   Whether this source is enabled for default Drush runs.
   * @param string[] $fields
   *   Fields to concatenate for the embedding text.
   * @param int $minTextLength
   *   Minimum Unicode length after concatenation.
   */
  public function __construct(
    public readonly bool $enabled,
    public readonly array $fields,
    public readonly int $minTextLength,
  ) {}

  /**
   * Build from a config mapping.
   *
   * @param array<string, mixed> $config
   *   Source config.
   * @param array<string, mixed> $defaults
   *   Defaults for missing keys.
   *
   * @return self
   *   Settings.
   */
  public static function fromConfigArray(array $config, array $defaults): self {
    $fields = [];
    $raw_fields = $config['fields'] ?? $defaults['fields'];
    if (is_array($raw_fields)) {
      foreach ($raw_fields as $field) {
        if (is_string($field) && $field !== '' && in_array($field, self::ALLOWED_FIELDS, TRUE)) {
          $fields[] = $field;
        }
      }
    }
    if ($fields === []) {
      $fields = $defaults['fields'];
    }

    return new self(
      enabled: (bool) ($config['enabled'] ?? $defaults['enabled']),
      fields: array_values(array_unique($fields)),
      minTextLength: max(1, (int) ($config['min_text_length'] ?? $defaults['min_text_length'])),
    );
  }

  /**
   * Config mapping for install / form submit.
   *
   * @return array{enabled: bool, fields: string[], min_text_length: int}
   *   Config array.
   */
  public function toConfigArray(): array {
    return [
      'enabled' => $this->enabled,
      'fields' => $this->fields,
      'min_text_length' => $this->minTextLength,
    ];
  }

}
