<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportDuplicateMatch;

use Drupal\Core\Entity\EntityInterface;

/**
 * Ephemeral per-request context for the two-save duplicate-match flow.
 *
 * Stored in a WeakMap keyed by entity object identity and carries all state
 * needed across entityPresave → entityAfterSave →
 * entityPresaveModerationAfterPostingRights → skipClassificationAlter.
 *
 * Detect-time fields are fixed at construction. Flow flags and
 * appliedModerationStatus mutate as the two-save flow advances.
 */
final class DuplicateMatchApplyContext {

  /**
   * Per-entity apply contexts for the current request.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, self>|null
   */
  private static ?\WeakMap $contexts = NULL;

  /**
   * Whether rev 2 has been scheduled (pendingApply) and started (applying).
   */
  public bool $pendingApply = TRUE;

  /**
   * Loop guard set at the start of entityAfterSave to prevent re-entry.
   */
  public bool $applying = FALSE;

  /**
   * Set once rev 2 save is underway; gates the moderation and OCHA hooks.
   */
  public bool $applied = FALSE;

  /**
   * When TRUE, OCHA classification is skipped on both rev 1 and rev 2.
   */
  public bool $skipClassification = TRUE;

  /**
   * The moderation status actually applied after restrictiveness comparison.
   */
  public ?string $appliedModerationStatus = NULL;

  /**
   * Constructs a DuplicateMatchApplyContext.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result with matches.
   * @param bool $isFormCreate
   *   TRUE when the report was created via the editorial form (not imported).
   * @param string $originalRevisionLog
   *   Revision log message captured before rev 1 annotations.
   * @param string|null $preDraftModerationStatus
   *   Moderation status captured before forcing draft; NULL when the entity
   *   does not implement EntityModeratedInterface.
   * @param string $targetStatus
   *   Configured target moderation status for any production match.
   */
  public function __construct(
    public readonly DuplicateMatchResult $result,
    public readonly bool $isFormCreate,
    public readonly string $originalRevisionLog,
    public readonly ?string $preDraftModerationStatus,
    public readonly string $targetStatus,
  ) {}

  /**
   * Factory for the detect presave pass.
   *
   * Creates the context with pendingApply and skipClassification pre-set.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result with matches.
   * @param bool $isFormCreate
   *   TRUE when the report was created via the editorial form.
   * @param string $originalRevisionLog
   *   Revision log message before any duplicate annotations.
   * @param string|null $preDraftModerationStatus
   *   Moderation status before forcing draft.
   * @param string $targetStatus
   *   Configured target moderation status.
   *
   * @return self
   *   A new context ready for the two-save flow.
   */
  public static function createForDetectPass(
    DuplicateMatchResult $result,
    bool $isFormCreate,
    string $originalRevisionLog,
    ?string $preDraftModerationStatus,
    string $targetStatus,
  ): self {
    return new self(
      $result,
      $isFormCreate,
      $originalRevisionLog,
      $preDraftModerationStatus,
      $targetStatus,
    );
  }

  /**
   * Transitions from pending to in-progress at the start of entityAfterSave.
   */
  public function beginApplying(): void {
    $this->applying = TRUE;
    $this->pendingApply = FALSE;
  }

  /**
   * Marks the context as applied once the nested save is about to run.
   */
  public function markApplied(): void {
    $this->applied = TRUE;
  }

  /**
   * Records the moderation status applied after restrictiveness comparison.
   *
   * @param string $appliedModerationStatus
   *   The moderation status actually set on rev 2.
   */
  public function recordAppliedModerationStatus(string $appliedModerationStatus): void {
    $this->appliedModerationStatus = $appliedModerationStatus;
  }

  /**
   * Retrieves the context attached to the entity, or NULL if absent.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return self|null
   *   The context, or NULL if none was attached.
   */
  public static function fromEntity(EntityInterface $entity): ?self {
    $context = self::contexts()[$entity] ?? NULL;
    return $context instanceof self ? $context : NULL;
  }

  /**
   * Attaches the context to the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param self $context
   *   The context to attach.
   */
  public static function attach(EntityInterface $entity, self $context): void {
    self::contexts()[$entity] = $context;
  }

  /**
   * Removes the context for the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public static function detach(EntityInterface $entity): void {
    unset(self::contexts()[$entity]);
  }

  /**
   * Returns the per-request context WeakMap.
   *
   * @return \WeakMap<\Drupal\Core\Entity\EntityInterface, self>
   *   The context WeakMap.
   */
  private static function contexts(): \WeakMap {
    return self::$contexts ??= new \WeakMap();
  }

}
