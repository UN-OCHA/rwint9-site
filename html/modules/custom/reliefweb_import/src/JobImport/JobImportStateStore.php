<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\JobImport;

use Drupal\reliefweb_entities\Entity\Job;

/**
 * Per-request store for job import state during feed/API imports.
 */
final class JobImportStateStore {

  /**
   * Per-job import contexts for the current request.
   *
   * @var \WeakMap<\Drupal\reliefweb_entities\Entity\Job, \Drupal\reliefweb_import\JobImport\ImportContext>|null
   */
  private static ?\WeakMap $contexts = NULL;

  /**
   * Marks a job as being imported with optional context metadata.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job being imported.
   * @param array<string, mixed> $context
   *   Context options (source, classification_enabled, deferred_fields, etc.).
   */
  public static function markImporting(Job $job, array $context = []): void {
    self::contexts()[$job] = ImportContext::fromArray($context);
  }

  /**
   * Whether the job is currently being imported.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   *
   * @return bool
   *   TRUE when an import context exists and importing is enabled.
   */
  public static function isImporting(Job $job): bool {
    $context = self::getContext($job);
    return $context !== NULL && $context->importing;
  }

  /**
   * Returns the import context for a job, if any.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   *
   * @return \Drupal\reliefweb_import\JobImport\ImportContext|null
   *   The import context or NULL.
   */
  public static function getContext(Job $job): ?ImportContext {
    if (!self::contexts()->offsetExists($job)) {
      return NULL;
    }
    return self::contexts()[$job];
  }

  /**
   * Records a validation error for a field on the job.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   * @param string $field
   *   Field machine name.
   * @param string $message
   *   Error message.
   */
  public static function setError(Job $job, string $field, string $message): void {
    $context = self::getOrCreateContext($job);
    $context->errors[$field] = $message;
  }

  /**
   * Records a non-blocking editorial note for the revision log.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   * @param string $note
   *   Note message.
   */
  public static function addNote(Job $job, string $note): void {
    $context = self::getOrCreateContext($job);
    $context->notes[] = $note;
  }

  /**
   * Returns validation errors keyed by field name.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   *
   * @return array<string, string>
   *   Field errors.
   */
  public static function getErrors(Job $job): array {
    return self::getContext($job)?->errors ?? [];
  }

  /**
   * Returns non-blocking notes for the revision log.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   *
   * @return string[]
   *   Notes.
   */
  public static function getNotes(Job $job): array {
    return self::getContext($job)?->notes ?? [];
  }

  /**
   * Whether any validation errors were recorded.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   *
   * @return bool
   *   TRUE when errors exist.
   */
  public static function hasErrors(Job $job): bool {
    return !empty(self::getErrors($job));
  }

  /**
   * Whether blocking validation errors exist (excluding deferred fields).
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   *
   * @return bool
   *   TRUE when a non-deferred error exists.
   */
  public static function hasBlockingErrors(Job $job): bool {
    $context = self::getContext($job);
    if ($context === NULL || empty($context->errors)) {
      return FALSE;
    }

    $deferred = $context->classificationEnabled ? $context->deferredFields : [];
    foreach (array_keys($context->errors) as $field) {
      if (!in_array($field, $deferred, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Clears import state for a job.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   */
  public static function clear(Job $job): void {
    if (self::contexts()->offsetExists($job)) {
      unset(self::contexts()[$job]);
    }
  }

  /**
   * Returns the per-request context WeakMap.
   *
   * @return \WeakMap<\Drupal\reliefweb_entities\Entity\Job, \Drupal\reliefweb_import\JobImport\ImportContext>
   *   Context map.
   */
  private static function contexts(): \WeakMap {
    return self::$contexts ??= new \WeakMap();
  }

  /**
   * Returns an existing context or creates a minimal one.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   The job entity.
   *
   * @return \Drupal\reliefweb_import\JobImport\ImportContext
   *   Import context.
   */
  private static function getOrCreateContext(Job $job): ImportContext {
    if (!self::contexts()->offsetExists($job)) {
      self::contexts()[$job] = new ImportContext();
    }
    return self::contexts()[$job];
  }

}
