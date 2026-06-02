<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch;

use Drupal\Core\Entity\EntityInterface;

/**
 * Ephemeral per-request context for the two-save series match flow.
 *
 * Attached to the entity as a single dynamic property
 * (reliefweb_report_series_match_context) and carries all state needed across
 * entityPresave → entityAfterSave → entityPresaveModerationAfterPostingRights
 * → skipClassificationAlter without storing twelve individual properties.
 *
 * Detect-time fields (result, outcome, revision log, pre-draft moderation) are
 * fixed at construction. Flow flags and appliedModerationStatus mutate as the
 * two-save flow advances.
 */
class SeriesMatchApplyContext {

  /**
   * The entity property name used to attach and retrieve this context.
   */
  const ENTITY_PROPERTY = 'reliefweb_report_series_match_context';

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
   * Constructs a series match apply context.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The matcher output from detect presave.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome $outcome
   *   Resolved outcome (confidences, tiers, target moderation) at detect time.
   * @param string $originalRevisionLog
   *   Revision log message captured before rev 1 annotations.
   * @param string|null $preDraftModerationStatus
   *   Moderation status captured before forcing draft; NULL when the entity
   *   does not implement EntityModeratedInterface.
   */
  public function __construct(
    public readonly SeriesMatchResult $result,
    public readonly SeriesMatchOutcome $outcome,
    public readonly string $originalRevisionLog,
    public readonly ?string $preDraftModerationStatus,
  ) {}

  /**
   * Factory for the detect presave pass.
   *
   * Creates the context with pendingApply and skipClassification pre-set.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The matcher output.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome $outcome
   *   Resolved outcome at detect time.
   * @param string $originalRevisionLog
   *   Revision log message before any series annotations.
   * @param string|null $preDraftModerationStatus
   *   Moderation status before forcing draft.
   *
   * @return self
   *   A new context ready for the two-save flow.
   */
  public static function createForDetectPass(
    SeriesMatchResult $result,
    SeriesMatchOutcome $outcome,
    string $originalRevisionLog,
    ?string $preDraftModerationStatus,
  ): self {
    return new self($result, $outcome, $originalRevisionLog, $preDraftModerationStatus);
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
    $context = $entity->{self::ENTITY_PROPERTY} ?? NULL;
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
    $entity->{self::ENTITY_PROPERTY} = $context;
  }

}
