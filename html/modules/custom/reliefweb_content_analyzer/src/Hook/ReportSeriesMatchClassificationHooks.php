<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Hook\Order\OrderBefore;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchApplyContext;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcherInterface;
use Drupal\reliefweb_api\Indexing\ReliefWebApiIndexingSkipStore;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Integrates report series matching with OCHA content classification.
 *
 * Two-save flow for new reports where series matching applies:
 *
 * Save 1 (isNew): Runs series detection in entity_presave. When a match
 * should apply, captures the posting-rights-resolved moderation status, forces
 * draft (suppressing all publish-path side effects), stashes matcher output on
 * the entity, and skips API indexing and OCHA classification. Revision 1 is
 * committed with original form/import field values.
 *
 * entity_after_save: Applies the series proposal to the same entity object,
 * saves it as a new revision (rev 2). entity_presave on the nested save sets
 * the final moderation status using the stored pre-draft baseline.
 *
 * The result is:
 * - Rev 1: original submission snapshot (draft; revertable).
 * - Rev 2: series-applied fields and final moderation status.
 */
final class ReportSeriesMatchClassificationHooks {

  /**
   * Module settings for report series matching automation.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * Lazily loaded workflow settings from config.
   */
  private ?SeriesMatchWorkflowSettings $workflowSettings = NULL;

  /**
   * Construct a ReportSeriesMatchClassificationHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcherInterface $reportSeriesMatcher
   *   The report series matcher.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface $contentEntityClassifier
   *   The OCHA content entity classifier.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected readonly ReportSeriesMatcherInterface $reportSeriesMatcher,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
    #[Autowire(service: 'ocha_content_classification.content_entity_classifier')]
    protected readonly ContentEntityClassifierInterface $contentEntityClassifier,
    #[Autowire(service: 'current_user')]
    protected readonly AccountInterface $currentUser,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->config = $config_factory->get('reliefweb_content_analyzer.settings');
  }

  /**
   * Returns typed workflow settings loaded from config.
   */
  protected function workflowSettings(): SeriesMatchWorkflowSettings {
    return $this->workflowSettings ??= SeriesMatchWorkflowSettings::fromConfigArray(
      $this->config->get('report_series_matching.workflow'),
    );
  }

  /**
   * Detect series match on new reports; forces draft when match applies.
   *
   * Runs before reliefweb_moderation (so draft is set before node.status is
   * synced) and before ocha_content_classification (so OCHA classification is
   * correctly skipped on rev 1).
   *
   * When a series match applies:
   * - Captures the posting-rights-resolved moderation status as pre-draft
   *   baseline for the moderation restore on rev 2.
   * - Forces the moderation status to 'draft' so rev 1 does not trigger
   *   publish-path side effects (pathauto, subscriptions, webhooks, OCHA
   *   classification queue, publication notification).
   * - Attaches a SeriesMatchApplyContext to the entity for entity_after_save.
   * - Marks the entity for skip-once API indexing so rev 1 is not indexed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  #[Hook('entity_presave', order: new OrderBefore(modules: ['ocha_content_classification', 'reliefweb_moderation']))]
  public function entityPresave(EntityInterface $entity): void {
    if (!$this->shouldAttemptSeriesMatch($entity)) {
      return;
    }

    $result = $this->reportSeriesMatcher->findSeriesCandidates($entity);
    if (!$this->shouldApplySeriesMatch($result)) {
      return;
    }

    if ($result->calculateSeriesConfidence() === NULL
      || $result->calculateTaggingConfidence() === NULL) {
      return;
    }

    $outcome = SeriesMatchOutcome::resolve($result, $this->workflowSettings());
    if ($outcome === NULL) {
      return;
    }

    // Stash the original revision log before any series annotations so rev 2
    // can restore it as its base, preserving editorial notes from the
    // submitter or import pipeline.
    $original_log = '';
    if ($entity instanceof RevisionLogInterface) {
      $original_log = trim((string) ($entity->getRevisionLogMessage() ?? ''));
    }

    // Capture the moderation status resolved by posting rights / embargo /
    // source blocking before forcing draft. This baseline is used on rev 2
    // to compute the final series-match-adjusted status.
    $pre_draft_status = NULL;
    if ($entity instanceof EntityModeratedInterface) {
      $pre_draft_status = $entity->getModerationStatus();
      $entity->setModerationStatus('draft');
    }

    // Skip API indexing on rev 1; rev 2 will be indexed normally.
    ReliefWebApiIndexingSkipStore::markSkip($entity);

    SeriesMatchApplyContext::attach(
      $entity,
      SeriesMatchApplyContext::createForDetectPass(
        $result,
        $outcome,
        $original_log,
        $pre_draft_status,
      ),
    );

    // Append the rev 1 detection log: series found + interim draft notice.
    $this->appendDetectionRevisionLog($entity, $result, $outcome->seriesConfidence, $pre_draft_status);
  }

  /**
   * Apply the series proposal after rev 1 is committed.
   *
   * Reuses the same entity object (stash flags and original field values are
   * already on it). Saves a new revision (rev 2) with the proposed field
   * values and the final moderation status. Inserts the tracking row pointing
   * at rev 2.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was just saved (rev 1).
   */
  #[Hook('entity_after_save')]
  public function entityAfterSave(EntityInterface $entity): void {
    $context = SeriesMatchApplyContext::fromEntity($entity);

    // Loop guard: the nested save (rev 2) also fires entity_after_save.
    if ($context?->applying) {
      return;
    }

    if (!$context?->pendingApply) {
      return;
    }

    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'report') {
      return;
    }

    $context->beginApplying();

    // Restore the original revision log captured before rev 1 annotations so
    // that rev 2 starts from the submitter's/importer's original message.
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionLogMessage($context->originalRevisionLog);
    }

    // Apply proposed field values to the entity.
    $this->applyProposal($entity, $context->result->proposal);

    // Append the full series tagging summary to the rev 2 revision log.
    $this->appendRevisionLog(
      $entity,
      $context->result,
      $context->outcome->seriesConfidence,
      $context->outcome->taggingConfidence,
    );

    // Mark applied so entityPresaveModerationAfterPostingRights runs on the
    // nested save, and skipClassificationAlter skips OCHA on rev 2.
    $context->markApplied();

    // Save as a new revision. Revision user from rev 1 is preserved on the
    // entity object; setNewRevision does not change it.
    $entity->setNewRevision(TRUE);

    try {
      $entity->save();
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(
        'Series match apply save failed for node @id: @message',
        ['@id' => $entity->id(), '@message' => $exception->getMessage()],
      );
      SeriesMatchApplyContext::detach($entity);
      return;
    }

    // Insert the tracking row pointing at rev 2.
    $minimum_series = $this->workflowSettings()->minimumSeriesConfidence;
    $this->insertMatchRecord($entity, $context, $minimum_series);
    SeriesMatchApplyContext::detach($entity);
  }

  /**
   * Set moderation state on rev 2 using the pre-draft baseline.
   *
   * Runs before ocha_content_classification and reliefweb_moderation so that
   * the final moderation status and its revision log clause are set before
   * skipClassificationAlter appends "Automated classification skipped.", and
   * before node.status is synced and PublicationNotificationHooks prepare
   * notification emails.
   *
   * On save 1 (entityPresave detect pass) this returns early because the
   * context's applied flag is not yet set.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  #[Hook('entity_presave', order: new OrderBefore(modules: ['ocha_content_classification', 'reliefweb_moderation']))]
  public function entityPresaveModerationAfterPostingRights(EntityInterface $entity): void {
    $context = SeriesMatchApplyContext::fromEntity($entity);
    if (!$context?->applied) {
      return;
    }

    $outcome = $context->outcome;
    $workflow = $this->workflowSettings();

    if (!$entity instanceof EntityModeratedInterface) {
      $context->recordAppliedModerationStatus('');
      return;
    }

    $current_status = $entity->getModerationStatus();

    // On rev 2 the entity was forced to 'draft' on save 1. Use the captured
    // pre-draft posting-rights status as the baseline for the restrictiveness
    // comparison so we compare the real intended status against the outcome.
    $stored = $context->preDraftModerationStatus;
    $baseline = ($current_status === 'draft' && $stored !== NULL) ? $stored : $current_status;

    $restrictiveness_order = $workflow->restrictivenessOrder;
    $final_status = SeriesMatchOutcome::moreRestrictiveStatus(
      $baseline,
      $outcome->targetModerationStatus,
      $restrictiveness_order,
    );

    if (!isset($entity->getAllowedModerationStatuses()[$final_status])) {
      $final_status = $baseline;
    }

    if ($final_status !== $current_status) {
      $entity->setModerationStatus($final_status);
    }

    $context->recordAppliedModerationStatus($final_status);

    // Always record the final moderation decision, even when the status did
    // not change, so rev 2's log always explains what happened and why.
    $this->appendModerationRevisionLog($entity, $outcome, $final_status, $baseline);
  }

  /**
   * Skip OCHA classification when series matching will be applied.
   *
   * Uses the skipClassification flag (set during detect presave) so this
   * fires on both rev 1 (set in entityPresave) and rev 2 (flag persists on the
   * context object for the nested save).
   *
   * @param bool $skip_classification
   *   Whether to skip classification (altered).
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   * @param array $context
   *   Hook context; must include an 'entity' key when skipping applies.
   */
  #[Hook(
    'ocha_content_classification_skip_classification_alter',
    order: new OrderAfter(modules: ['reliefweb_import', 'reliefweb_entities']),
  )]
  public function skipClassificationAlter(
    bool &$skip_classification,
    ClassificationWorkflowInterface $workflow,
    array $context,
  ): void {
    $entity = $context['entity'] ?? NULL;
    if (!$entity instanceof EntityInterface) {
      return;
    }

    $apply_context = SeriesMatchApplyContext::fromEntity($entity);
    if ($apply_context?->skipClassification) {
      $skip_classification = TRUE;
      $this->appendClassificationSkippedRevisionLog($entity);
    }
  }

  /**
   * Whether automation may run for this entity on presave.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE if series matching should be attempted.
   */
  protected function shouldAttemptSeriesMatch(EntityInterface $entity): bool {
    if (!$entity instanceof ContentEntityInterface) {
      return FALSE;
    }

    if (!$entity->isNew()) {
      return FALSE;
    }

    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'report') {
      return FALSE;
    }

    $workflow = $this->workflowSettings();
    $automation_enabled = $this->isImportedReport($entity)
      ? $workflow->automationEnabledImported
      : $workflow->automationEnabledFormCreated;

    if (!$automation_enabled) {
      return FALSE;
    }

    if (!$this->isImportedReport($entity) && !$this->currentUser->hasPermission('apply report series matching automation on form create')) {
      return FALSE;
    }

    if (!$this->contentEntityClassifier->isEntityClassifiable($entity)) {
      return FALSE;
    }

    return !$this->shouldSkipSeriesMatchForModerationStatus($entity);
  }

  /**
   * Whether series matching should be skipped for moderation status.
   *
   * Evaluated after Report::preSave() posting-rights and source rules,
   * which may set states such as refused before this hook runs.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE when the entity's moderation status is in the configured skip list.
   */
  protected function shouldSkipSeriesMatchForModerationStatus(EntityInterface $entity): bool {
    $skip = $this->workflowSettings()->skipSeriesMatchModerationStatuses;
    if (!$entity instanceof EntityModeratedInterface || $skip === []) {
      return FALSE;
    }

    return in_array($entity->getModerationStatus(), $skip, TRUE);
  }

  /**
   * Whether the report was submitted via the Post API or an import pipeline.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE if field_post_api_provider is set on the entity.
   */
  protected function isImportedReport(ContentEntityInterface $entity): bool {
    return $entity->hasField('field_post_api_provider')
      && !$entity->get('field_post_api_provider')->isEmpty();
  }

  /**
   * Whether the match result meets the series confidence apply threshold.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The series match result from the matcher.
   *
   * @return bool
   *   TRUE if the proposal should be applied to the entity.
   */
  protected function shouldApplySeriesMatch(SeriesMatchResult $result): bool {
    if (!$result->status->passedMinimum) {
      return FALSE;
    }

    if ($result->proposal->updatedFields === []) {
      return FALSE;
    }

    $series_confidence = $result->calculateSeriesConfidence();
    if ($series_confidence === NULL) {
      return FALSE;
    }

    $minimum = $this->workflowSettings()->minimumSeriesConfidence;
    return $series_confidence >= $minimum;
  }

  /**
   * Apply proposed field values to the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being saved.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal $proposal
   *   Proposed values from a successful series match.
   *
   * @return string[]
   *   Machine names of fields that were updated.
   */
  protected function applyProposal(EntityInterface $entity, SeriesMatchProposal $proposal): array {
    $updated = [];

    foreach ($proposal->updatedFields as $field_name => $value) {
      if ($field_name === 'title') {
        if (is_string($value) && $value !== '') {
          $entity->setTitle($value);
          $updated[] = 'title';
        }
        continue;
      }

      if (!$entity->hasField($field_name)) {
        continue;
      }

      if ($value === NULL) {
        continue;
      }

      $entity->set($field_name, $this->formatFieldValue($field_name, $value, $entity));
      $updated[] = $field_name;
    }

    return $updated;
  }

  /**
   * Format a proposal value for assignment to a field.
   *
   * @param string $field_name
   *   Field machine name.
   * @param null|string|string[]|int[] $value
   *   Value from the proposal.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity being updated.
   *
   * @return mixed
   *   Value suitable for Entity::set().
   */
  protected function formatFieldValue(string $field_name, null|string|array $value, EntityInterface $entity): mixed {
    if ($value === [] || $value === '') {
      return [];
    }

    if (is_string($value)) {
      return $value;
    }

    if (!$this->isTaxonomyReferenceField($field_name, $entity)) {
      return $value;
    }

    $ids = array_values(array_map('intval', $value));
    $definition = $this->entityFieldManager->getFieldDefinitions(
      $entity->getEntityTypeId(),
      $entity->bundle(),
    )[$field_name] ?? NULL;

    if ($definition !== NULL && $definition->getFieldStorageDefinition()->getCardinality() === 1) {
      return ['target_id' => $ids[0] ?? NULL];
    }

    return array_map(static fn (int $id): array => ['target_id' => $id], $ids);
  }

  /**
   * Whether the field is a taxonomy term reference field.
   *
   * @param string $field_name
   *   Field machine name.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity whose bundle defines the field.
   *
   * @return bool
   *   TRUE if the field references taxonomy terms.
   */
  protected function isTaxonomyReferenceField(string $field_name, EntityInterface $entity): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions(
      $entity->getEntityTypeId(),
      $entity->bundle(),
    );
    $definition = $definitions[$field_name] ?? NULL;
    if ($definition === NULL) {
      return FALSE;
    }

    return $definition->getType() === 'entity_reference'
      && ($definition->getSetting('target_type') ?? '') === 'taxonomy_term';
  }

  /**
   * Append rev 1 detection log: series found + interim draft moderation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being saved.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The series match result.
   * @param float $series_confidence
   *   Series identification confidence score.
   * @param string|null $pre_draft_status
   *   The moderation status that was captured before forcing draft, or NULL
   *   when the entity does not implement EntityModeratedInterface.
   */
  protected function appendDetectionRevisionLog(
    EntityInterface $entity,
    SeriesMatchResult $result,
    float $series_confidence,
    ?string $pre_draft_status,
  ): void {
    if (!($entity instanceof RevisionLogInterface)) {
      return;
    }

    $series_clause = $this->buildSeriesFoundClause($result, $series_confidence);

    $moderation_clause = '';
    if ($pre_draft_status !== NULL) {
      $moderation_clause = $this->formatRevisionLogClause(
        'Moderation status: draft (original: @original, reason: interim while applying series tagging).',
        ['@original' => $pre_draft_status],
      );
    }

    $parts = array_filter([$series_clause, $moderation_clause], static fn (string $s): bool => $s !== '');
    $message = implode(' ', $parts);
    if ($message === '') {
      return;
    }

    $this->appendToRevisionLog($entity, $message);
  }

  /**
   * Append the full series tagging summary to the rev 2 revision log.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being saved.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The applied series match result.
   * @param float $series_confidence
   *   Series identification confidence score.
   * @param float $tagging_confidence
   *   Tagging proposal confidence score.
   */
  protected function appendRevisionLog(
    EntityInterface $entity,
    SeriesMatchResult $result,
    float $series_confidence,
    float $tagging_confidence,
  ): void {
    if (!($entity instanceof RevisionLogInterface)) {
      return;
    }

    $message = $this->buildRevisionLogMessage($result, $series_confidence, $tagging_confidence);
    if ($message === '') {
      return;
    }

    $this->appendToRevisionLog($entity, $message);
  }

  /**
   * Append the final moderation decision to the rev 2 revision log.
   *
   * Always called on rev 2 once the outcome is resolved, even when the
   * moderation status did not change, so the log always explains what
   * happened and why.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being saved.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome $outcome
   *   The resolved series match outcome.
   * @param string $applied_moderation
   *   The moderation status that was actually set.
   * @param string $baseline
   *   The pre-draft posting-rights moderation status used as the original.
   */
  protected function appendModerationRevisionLog(
    EntityInterface $entity,
    SeriesMatchOutcome $outcome,
    string $applied_moderation,
    string $baseline,
  ): void {
    if (!($entity instanceof RevisionLogInterface)) {
      return;
    }

    $message = $this->buildFinalModerationClause($outcome, $applied_moderation, $baseline);
    $this->appendToRevisionLog($entity, $message);
  }

  /**
   * Build the rev 1 "Series found (...)" sentence for the detection log.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The series match result.
   * @param float $series_confidence
   *   Series identification confidence score.
   *
   * @return string
   *   Sentence fragment ending with a period.
   */
  protected function buildSeriesFoundClause(SeriesMatchResult $result, float $series_confidence): string {
    $series_pct = (int) round($series_confidence * 100);
    $cluster_size = $result->evidence->bestClusterSize;
    $lookback_months = $result->evidence->lookbackMonths;

    $details = $this->formatRevisionLogClause('@count similar reports over @months months, @confidence confidence', [
      '@count' => $cluster_size,
      '@months' => $lookback_months,
      '@confidence' => $series_pct . '%',
    ]);

    return $this->formatRevisionLogClause('Series found (@details).', ['@details' => $details]);
  }

  /**
   * Build the rev 2 final moderation status clause.
   *
   * Format:
   *   "Moderation status: @applied (original: @original, reason: @reason)."
   *
   * The reason is always "{outcomeTier}-confidence series match", which
   * naturally describes both the "restored" case (high/medium tier kept the
   * original status) and the "downgraded" case (low tier forced a more
   * restrictive status).
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome $outcome
   *   The resolved series match outcome.
   * @param string $applied_moderation
   *   The moderation status that was actually set.
   * @param string $baseline
   *   The pre-draft posting-rights moderation status used as the original.
   *
   * @return string
   *   The formatted moderation clause.
   */
  protected function buildFinalModerationClause(
    SeriesMatchOutcome $outcome,
    string $applied_moderation,
    string $baseline,
  ): string {
    $reason = $outcome->outcomeTier . '-confidence series match';

    return $this->formatRevisionLogClause(
      'Moderation status: @applied (original: @original, reason: @reason).',
      [
        '@applied' => $applied_moderation,
        '@original' => $baseline,
        '@reason' => $reason,
      ],
    );
  }

  /**
   * Build the rev 2 full series tagging summary revision log message.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The applied series match result.
   * @param float $series_confidence
   *   Series identification confidence score.
   * @param float $tagging_confidence
   *   Tagging proposal confidence score.
   *
   * @return string
   *   Revision log fragment.
   */
  protected function buildRevisionLogMessage(
    SeriesMatchResult $result,
    float $series_confidence,
    float $tagging_confidence,
  ): string {
    $tagging_pct = (int) round($tagging_confidence * 100);

    $clauses = [
      rtrim($this->buildSeriesFoundClause($result, $series_confidence), '.'),
      $this->formatRevisionLogClause('tags copied (@consistency tag consistency)', [
        '@consistency' => $tagging_pct . '%',
      ]),
      $this->formatTitleClause(
        $result->proposal->titleSource,
        $result->proposal->titleAiDurationSeconds,
      ),
    ];

    $references = $this->formatReferenceReportLinks($result->evidence->candidateIds);
    if ($references !== '') {
      $clauses[] = $this->formatRevisionLogClause('see: :references', [
        ':references' => $references,
      ]);
    }

    return implode('; ', $clauses) . '.';
  }

  /**
   * Format a revision log clause from a template and placeholder values.
   *
   * @param string $template
   *   Clause template with @ or : placeholders.
   * @param array<string, mixed> $args
   *   Placeholder values.
   *
   * @return string
   *   Rendered clause text for the revision log.
   */
  protected function formatRevisionLogClause(string $template, array $args = []): string {
    if ($args === []) {
      return $template;
    }

    return (string) new FormattableMarkup($template, $args);
  }

  /**
   * Build markdown links to the latest series candidate reports.
   *
   * @param int[] $candidate_ids
   *   Series candidate node IDs from the winning cluster.
   * @param int $limit
   *   Maximum number of references to include.
   *
   * @return string
   *   Comma-separated markdown links, highest node ID first.
   */
  protected function formatReferenceReportLinks(array $candidate_ids, int $limit = 3): string {
    if ($candidate_ids === []) {
      return '';
    }

    $ids = array_map('intval', $candidate_ids);
    rsort($ids, SORT_NUMERIC);
    $ids = array_slice($ids, 0, $limit);

    $links = [];
    foreach ($ids as $node_id) {
      if ($node_id <= 0) {
        continue;
      }
      $links[] = '[#' . $node_id . '](/node/' . $node_id . ')';
    }

    return implode(', ', $links);
  }

  /**
   * Describe how the title was chosen for the revision log.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource|null $source
   *   How the proposed title was selected.
   * @param float|null $title_ai_duration_seconds
   *   AI title generation duration in seconds, when applicable.
   *
   * @return string
   *   Short clause for the revision log.
   */
  protected function formatTitleClause(
    ?SeriesMatchTitleSource $source,
    ?float $title_ai_duration_seconds = NULL,
  ): string {
    $clause = match ($source) {
      SeriesMatchTitleSource::KeptOriginalPatternMatch => 'series-pattern title',
      SeriesMatchTitleSource::AiGenerated => 'AI-generated title',
      SeriesMatchTitleSource::AiDisabled => 'title unchanged (AI disabled)',
      SeriesMatchTitleSource::FailedNoCandidateTitles => 'title not generated',
      SeriesMatchTitleSource::FailedNoSourceText => 'title not generated (no source text)',
      SeriesMatchTitleSource::FailedAi => 'title generation failed',
      SeriesMatchTitleSource::FailedEmptyAiOutput => 'title generation failed (empty output)',
      default => 'title unchanged',
    };

    if ($source === SeriesMatchTitleSource::AiGenerated
      && $title_ai_duration_seconds !== NULL) {
      $clause .= ' (' . number_format($title_ai_duration_seconds, 1) . 's)';
    }

    return $clause;
  }

  /**
   * Append a message to an entity's revision log with a space separator.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose revision log is being updated.
   * @param string $message
   *   The message to append.
   */
  protected function appendToRevisionLog(EntityInterface $entity, string $message): void {
    if (!($entity instanceof RevisionLogInterface) || $message === '') {
      return;
    }

    $existing = trim((string) ($entity->getRevisionLogMessage() ?? ''));
    $combined = $existing === '' ? $message : $existing . ' ' . $message;
    $entity->setRevisionLogMessage($combined);
  }

  /**
   * Note in the revision log that OCHA classification was skipped.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being saved.
   */
  protected function appendClassificationSkippedRevisionLog(EntityInterface $entity): void {
    if (!($entity instanceof RevisionLogInterface)) {
      return;
    }

    $message = 'Automated classification skipped.';
    $existing = trim((string) ($entity->getRevisionLogMessage() ?? ''));
    if ($existing !== '' && str_contains($existing, $message)) {
      return;
    }

    $this->appendToRevisionLog($entity, $message);
  }

  /**
   * Inserts a tracking row for an applied series match.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity (rev 2).
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchApplyContext $context
   *   The apply context carrying frozen scores and resolved outcome.
   * @param float $minimum_series_confidence
   *   Configured series confidence threshold at apply time.
   */
  protected function insertMatchRecord(
    EntityInterface $entity,
    SeriesMatchApplyContext $context,
    float $minimum_series_confidence,
  ): void {
    $entity_id = $entity->id();
    $revision_id = $entity->getRevisionId();
    if ($entity_id === NULL || $revision_id === NULL) {
      return;
    }

    $this->database->insert('reliefweb_report_series_match')
      ->fields([
        'entity_type_id' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'entity_id' => (int) $entity_id,
        'revision_id' => (int) $revision_id,
        'series_confidence' => $context->outcome->seriesConfidence,
        'tagging_confidence' => $context->outcome->taggingConfidence,
        'created' => $this->time->getRequestTime(),
        'uid' => $this->resolveRecordUid($entity),
        'data' => json_encode(
          $this->buildRecordDataPayload($context, $minimum_series_confidence),
          JSON_THROW_ON_ERROR,
        ),
      ])
      ->execute();
  }

  /**
   * Resolve the user ID to store with the record.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity.
   *
   * @return int
   *   Revision author, entity owner, or 0 if neither is available.
   */
  protected function resolveRecordUid(EntityInterface $entity): int {
    if ($entity instanceof RevisionLogInterface) {
      $revision_user_id = $entity->getRevisionUserId();
      if ($revision_user_id !== NULL) {
        return (int) $revision_user_id;
      }
    }
    if ($entity instanceof EntityOwnerInterface) {
      $owner_id = $entity->getOwnerId();
      if ($owner_id !== NULL) {
        return (int) $owner_id;
      }
    }
    return 0;
  }

  /**
   * Build the JSON payload for the data column.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchApplyContext $context
   *   The apply context carrying frozen scores and resolved outcome.
   * @param float $minimum_series_confidence
   *   Configured series confidence threshold at apply time.
   *
   * @return array<string, mixed>
   *   Serializable structure for the reliefweb_report_series_match.data column.
   */
  protected function buildRecordDataPayload(
    SeriesMatchApplyContext $context,
    float $minimum_series_confidence,
  ): array {
    $workflow = $this->workflowSettings();

    return [
      'minimum_series_confidence' => $minimum_series_confidence,
      'series_confidence_tiers' => $workflow->seriesConfidenceTiers,
      'tagging_confidence_tiers' => $workflow->taggingConfidenceTiers,
      'minimum_tagging_confidence' => $workflow->minimumTaggingConfidence,
      'series_confidence' => $context->outcome->seriesConfidence,
      'tagging_confidence' => $context->outcome->taggingConfidence,
      'outcome_tier' => $context->outcome->outcomeTier,
      'target_moderation_status' => $context->outcome->targetModerationStatus,
      'applied_moderation_status' => $context->appliedModerationStatus,
      'proposal' => $this->serializeProposal($context->result->proposal),
      'evidence' => $this->serializeEvidence($context->result->evidence),
    ];
  }

  /**
   * Serialize a series match proposal for database storage.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal $proposal
   *   The applied proposal.
   *
   * @return array<string, mixed>
   *   Serializable proposal data.
   */
  protected function serializeProposal(SeriesMatchProposal $proposal): array {
    $sources = [];
    foreach ($proposal->updatedFieldSources as $field => $source) {
      $sources[$field] = $source->value;
    }

    return [
      'updated_fields' => $proposal->updatedFields,
      'updated_field_sources' => $sources,
      'title_source' => $proposal->titleSource?->value,
      'title_ai_duration_seconds' => $proposal->titleAiDurationSeconds,
      'most_recent_candidate_id' => $proposal->mostRecentCandidateId,
    ];
  }

  /**
   * Serialize series match evidence for database storage.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence $evidence
   *   Evidence from the match run.
   *
   * @return array<string, mixed>
   *   Serializable evidence data.
   */
  protected function serializeEvidence(SeriesMatchEvidence $evidence): array {
    return [
      'candidate_ids' => $evidence->candidateIds,
      'candidate_pattern_scores' => $evidence->candidatePatternScores,
      'title_match_count' => $evidence->titleMatchCount,
      'url_match_count' => $evidence->urlMatchCount,
      'both_signals_count' => $evidence->bothSignalsCount,
      'merged_count' => $evidence->mergedCount,
      'merged_after_limit_count' => $evidence->mergedAfterLimitCount,
      'cluster_count' => $evidence->clusterCount,
      'cluster_sizes' => $evidence->clusterSizes,
      'best_cluster_size' => $evidence->bestClusterSize,
      'best_cluster_share' => $evidence->bestClusterShare,
      'cluster_score' => $evidence->clusterScore,
      'cluster_score_size' => $evidence->clusterScoreSize,
      'cluster_score_pattern' => $evidence->clusterScorePattern,
      'cluster_score_tagging' => $evidence->clusterScoreTagging,
      'lookback_months' => $evidence->lookbackMonths,
    ];
  }

  /**
   * Get the logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  protected function getLogger(): LoggerInterface {
    return $this->loggerFactory->get('reliefweb_content_analyzer');
  }

}
