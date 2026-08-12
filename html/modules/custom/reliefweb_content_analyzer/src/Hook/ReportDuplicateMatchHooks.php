<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Hook;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Hook\Order\OrderBefore;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_api\Indexing\ReliefWebApiIndexingSkipStore;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchApplyContext;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcherInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applies report near-duplicate detection on new report create.
 *
 * Two-save flow when any production match is found:
 *
 * Save 1 (isNew): Runs detection in entity_presave. When matches exist,
 * captures the posting-rights-resolved moderation status, forces draft
 * (suppressing publish-path side effects), stashes matcher output on the
 * entity, and skips API indexing and OCHA classification. Series matching
 * is skipped via DuplicateMatchApplyContext. Revision 1 is committed with
 * original form/import field values.
 *
 * entity_after_save: Saves a new revision (rev 2). entity_presave on the
 * nested save sets the final moderation status using the stored pre-draft
 * baseline compared restrictiveness-only against the configured target
 * (default "duplicate").
 *
 * The result is:
 * - Rev 1: original submission snapshot (draft; revertable).
 * - Rev 2: original fields and final duplicate moderation status.
 */
final class ReportDuplicateMatchHooks {

  use StringTranslationTrait;

  /**
   * Module settings.
   */
  protected ImmutableConfig $config;

  /**
   * Lazily loaded report-duplicate matching settings.
   */
  private ?DuplicateMatchSettings $settings = NULL;

  /**
   * Constructs ReportDuplicateMatchHooks.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcherInterface $matcher
   *   Report duplicate matcher.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected readonly ReportDuplicateMatcherInterface $matcher,
    #[Autowire(service: 'current_user')]
    protected readonly AccountInterface $currentUser,
    protected readonly MessengerInterface $messenger,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->config = $config_factory->get('reliefweb_content_analyzer.settings');
  }

  /**
   * Detect near-duplicates and force draft when matches are found.
   *
   * Runs before series matching, reliefweb_moderation (so draft is set before
   * node.status is synced), and ocha_content_classification (so OCHA
   * classification is skipped on rev 1).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  #[Hook('entity_presave', order: new OrderBefore(
    modules: ['ocha_content_classification', 'reliefweb_moderation'],
    classesAndMethods: [
      [ReportSeriesMatchClassificationHooks::class, 'entityPresave'],
      [ReportSeriesMatchClassificationHooks::class, 'entityPresaveModerationAfterPostingRights'],
    ],
  ))]
  public function entityPresave(EntityInterface $entity): void {
    if (!$this->shouldAttemptDetection($entity)) {
      return;
    }

    assert($entity instanceof ContentEntityInterface);
    $result = $this->matcher->findDuplicates($entity);
    if (!$result->hasMatches()) {
      return;
    }

    $target_status = $this->resolveTargetStatus($result);
    if ($target_status === NULL) {
      return;
    }

    $original_log = '';
    if ($entity instanceof RevisionLogInterface) {
      $original_log = trim((string) ($entity->getRevisionLogMessage() ?? ''));
    }

    $pre_draft_status = NULL;
    if ($entity instanceof EntityModeratedInterface) {
      $pre_draft_status = $entity->getModerationStatus();
      $entity->setModerationStatus('draft');
    }

    ReliefWebApiIndexingSkipStore::markSkip($entity);

    DuplicateMatchApplyContext::attach(
      $entity,
      DuplicateMatchApplyContext::createForDetectPass(
        $result,
        !$this->isImportedReport($entity),
        $original_log,
        $pre_draft_status,
        $target_status,
      ),
    );

    $this->appendDetectionRevisionLog($entity, $result, $pre_draft_status);
  }

  /**
   * Apply duplicate status after rev 1 is committed.
   *
   * Saves a new revision (rev 2) with the final moderation status. Shows a
   * messenger warning after form create.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was just saved (rev 1).
   */
  #[Hook('entity_after_save')]
  public function entityAfterSave(EntityInterface $entity): void {
    $context = DuplicateMatchApplyContext::fromEntity($entity);

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

    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionLogMessage($context->originalRevisionLog);
    }

    $this->appendToRevisionLog($entity, $this->buildRevisionLogMessage($context->result));

    $context->markApplied();
    $entity->setNewRevision(TRUE);

    try {
      $entity->save();
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(
        'Duplicate match apply save failed for node @id: @message',
        ['@id' => $entity->id(), '@message' => $exception->getMessage()],
      );
      DuplicateMatchApplyContext::detach($entity);
      return;
    }

    if ($context->isFormCreate && $context->result->hasMatches()) {
      $this->messenger->addWarning($this->buildMessengerMessage($context->result));
    }

    DuplicateMatchApplyContext::detach($entity);
  }

  /**
   * Set moderation state on rev 2 using the pre-draft baseline.
   *
   * Runs before ocha_content_classification and reliefweb_moderation so that
   * the final moderation status and its revision log clause are set before
   * skipClassificationAlter appends "Automated classification skipped.", and
   * before node.status is synced.
   *
   * On save 1 (entityPresave detect pass) this returns early because the
   * context's applied flag is not yet set.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  #[Hook('entity_presave', order: new OrderBefore(
    modules: ['ocha_content_classification', 'reliefweb_moderation'],
    classesAndMethods: [
      [ReportSeriesMatchClassificationHooks::class, 'entityPresave'],
      [ReportSeriesMatchClassificationHooks::class, 'entityPresaveModerationAfterPostingRights'],
    ],
  ))]
  public function entityPresaveModerationAfterPostingRights(EntityInterface $entity): void {
    $context = DuplicateMatchApplyContext::fromEntity($entity);
    if (!$context?->applied) {
      return;
    }

    if (!$entity instanceof EntityModeratedInterface) {
      $context->recordAppliedModerationStatus('');
      return;
    }

    $current_status = $entity->getModerationStatus();
    $stored = $context->preDraftModerationStatus;
    $baseline = ($current_status === 'draft' && $stored !== NULL) ? $stored : $current_status;

    $final_status = SeriesMatchOutcome::moreRestrictiveStatus(
      $baseline,
      $context->targetStatus,
      $this->restrictivenessOrder(),
    );

    if (!isset($entity->getAllowedModerationStatuses()[$final_status])) {
      $final_status = $baseline;
    }

    if ($final_status !== $current_status) {
      $entity->setModerationStatus($final_status);
    }

    $context->recordAppliedModerationStatus($final_status);
    $this->appendModerationRevisionLog($entity, $final_status, $baseline);
  }

  /**
   * Skip OCHA classification when duplicate matching will be applied.
   *
   * Uses the skipClassification flag (set during detect presave) so this
   * fires on both rev 1 and rev 2 (flag persists on the context object for
   * the nested save).
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

    $apply_context = DuplicateMatchApplyContext::fromEntity($entity);
    if ($apply_context?->skipClassification) {
      $skip_classification = TRUE;
      $this->appendClassificationSkippedRevisionLog($entity);
    }
  }

  /**
   * Whether detection may run for this entity on presave.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity being saved.
   *
   * @return bool
   *   TRUE when detection should run.
   */
  protected function shouldAttemptDetection(EntityInterface $entity): bool {
    if (!$entity instanceof ContentEntityInterface) {
      return FALSE;
    }

    if (!$entity->isNew()) {
      return FALSE;
    }

    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'report') {
      return FALSE;
    }

    $settings = $this->settings();
    $imported = $this->isImportedReport($entity);
    $automation_enabled = $imported
      ? $settings->automationEnabledImported
      : $settings->automationEnabledFormCreated;

    if (!$automation_enabled) {
      return FALSE;
    }

    if (!$imported && !$this->currentUser->hasPermission('apply report duplication automation on form create')) {
      return FALSE;
    }

    if ($entity instanceof EntityModeratedInterface) {
      $status = $entity->getModerationStatus();
      if (in_array($status, $settings->skipModerationStatuses, TRUE)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Whether the report was submitted via Post API or import.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE when field_post_api_provider is set.
   */
  protected function isImportedReport(ContentEntityInterface $entity): bool {
    return $entity->hasField('field_post_api_provider')
      && !$entity->get('field_post_api_provider')->isEmpty();
  }

  /**
   * Typed settings.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings
   *   Settings.
   */
  protected function settings(): DuplicateMatchSettings {
    return $this->settings ??= DuplicateMatchSettings::fromConfigArray(
      $this->config->get('report_duplicate_matching'),
    );
  }

  /**
   * Resolve the configured target moderation status for a result.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result with matches.
   *
   * @return string|null
   *   Target status machine name, or NULL when no matches.
   */
  protected function resolveTargetStatus(DuplicateMatchResult $result): ?string {
    if (!$result->hasMatches()) {
      return NULL;
    }

    return $this->settings()->targetStatus;
  }

  /**
   * Restrictiveness order from series workflow settings.
   *
   * @return string[]
   *   Status machine names, most restrictive first.
   */
  protected function restrictivenessOrder(): array {
    $order = $this->config->get('report_series_matching.workflow.restrictiveness_order');
    if (!is_array($order) || $order === []) {
      return [
        'refused',
        'duplicate',
        'draft',
        'on-hold',
        'pending',
        'to-review',
        'embargoed',
        'reference',
        'published',
      ];
    }
    return array_values(array_filter(
      $order,
      static fn($status): bool => is_string($status) && $status !== '',
    ));
  }

  /**
   * Append the rev 1 detection log: matches found + interim draft notice.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being saved.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result with matches.
   * @param string|null $pre_draft_status
   *   Moderation status captured before forcing draft.
   */
  protected function appendDetectionRevisionLog(
    EntityInterface $entity,
    DuplicateMatchResult $result,
    ?string $pre_draft_status,
  ): void {
    if (!($entity instanceof RevisionLogInterface)) {
      return;
    }

    $parts = [$this->buildRevisionLogMessage($result)];
    if ($pre_draft_status !== NULL) {
      $parts[] = 'Moderation status: draft (original: ' . $pre_draft_status . ', reason: interim while applying duplicate status).';
    }

    $this->appendToRevisionLog($entity, implode(' ', $parts));
  }

  /**
   * Append the final moderation decision to the rev 2 revision log.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being saved.
   * @param string $applied_moderation
   *   The moderation status that was actually set.
   * @param string $baseline
   *   The pre-draft posting-rights moderation status used as the original.
   */
  protected function appendModerationRevisionLog(
    EntityInterface $entity,
    string $applied_moderation,
    string $baseline,
  ): void {
    $this->appendToRevisionLog(
      $entity,
      'Moderation status: ' . $applied_moderation . ' (original: ' . $baseline . ', reason: near-duplicate detection).',
    );
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
   * Build revision log text listing matched reports.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result.
   *
   * @return string
   *   Plain-text revision log message.
   */
  protected function buildRevisionLogMessage(DuplicateMatchResult $result): string {
    $parts = [];
    foreach ($result->matches as $match) {
      assert($match instanceof DuplicateMatch);
      $parts[] = sprintf(
        '%s (nid %d, %s %s)',
        $match->title,
        $match->nid,
        $match->method,
        $match->similarityPercentage(),
      );
    }

    return 'Near-duplicate of: ' . implode('; ', $parts);
  }

  /**
   * Build messenger warning with links to matched reports.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Message for the messenger.
   */
  protected function buildMessengerMessage(DuplicateMatchResult $result): FormattableMarkup {
    $links = [];
    foreach ($result->matches as $match) {
      assert($match instanceof DuplicateMatch);
      $url = Url::fromRoute('entity.node.canonical', ['node' => $match->nid])->toString();
      $links[] = '<a href="' . Html::escape($url) . '">' . Html::escape($match->title) . '</a> (' . Html::escape($match->method) . ' ' . Html::escape($match->similarityPercentage()) . ')';
    }

    return new FormattableMarkup('@label @links', [
      '@label' => $this->t('Possible duplicate of:'),
      '@links' => new FormattableMarkup(implode(', ', $links), []),
    ]);
  }

  /**
   * Returns the module logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   Logger channel.
   */
  protected function getLogger(): LoggerInterface {
    return $this->loggerFactory->get('reliefweb_content_analyzer');
  }

}
