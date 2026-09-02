<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\JobImport;

/**
 * Per-request import state for a job entity.
 */
final class ImportContext {

  /**
   * Constructs an ImportContext.
   *
   * @param bool $importing
   *   Whether the job is currently being imported.
   * @param array<string, string> $errors
   *   Validation errors keyed by field name.
   * @param string|null $source
   *   Import source identifier (feed, workday, etc.).
   * @param bool $classificationEnabled
   *   Whether automated content classification is enabled for this import.
   * @param string[] $deferredFields
   *   Fields whose validation errors should not block publication.
   * @param string[] $notes
   *   Non-blocking editorial notes for the revision log.
   */
  public function __construct(
    public bool $importing = TRUE,
    public array $errors = [],
    public ?string $source = NULL,
    public bool $classificationEnabled = FALSE,
    public array $deferredFields = [],
    public array $notes = [],
  ) {}

  /**
   * Creates a context from an options array.
   *
   * @param array<string, mixed> $options
   *   Context options.
   *
   * @return self
   *   Import context.
   */
  public static function fromArray(array $options): self {
    return new self(
      importing: (bool) ($options['importing'] ?? TRUE),
      errors: is_array($options['errors'] ?? NULL) ? $options['errors'] : [],
      source: isset($options['source']) ? (string) $options['source'] : NULL,
      classificationEnabled: (bool) ($options['classification_enabled'] ?? FALSE),
      deferredFields: is_array($options['deferred_fields'] ?? NULL) ? $options['deferred_fields'] : [],
      notes: is_array($options['notes'] ?? NULL) ? $options['notes'] : [],
    );
  }

}
