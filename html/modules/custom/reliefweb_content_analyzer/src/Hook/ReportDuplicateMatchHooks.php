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
use Drupal\Core\Hook\Order\OrderBefore;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchApplyContext;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcherInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applies report near-duplicate detection on new report create.
 *
 * Runs before series matching. Hard Jaccard matches apply the configured
 * Jaccard target status (default "duplicate"), which causes series automation
 * to be skipped via the series skip list. Soft embedding-confirmed matches
 * apply a softer demotion target (default "to-review") without blocking series
 * matching.
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
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected readonly ReportDuplicateMatcherInterface $matcher,
    #[Autowire(service: 'current_user')]
    protected readonly AccountInterface $currentUser,
    protected readonly MessengerInterface $messenger,
  ) {
    $this->config = $config_factory->get('reliefweb_content_analyzer.settings');
  }

  /**
   * Detect near-duplicates and demote moderation status when found.
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
    if ($target_status !== NULL && $entity instanceof EntityModeratedInterface) {
      $current = $entity->getModerationStatus() ?? '';
      $final = SeriesMatchOutcome::moreRestrictiveStatus(
        $current,
        $target_status,
        $this->restrictivenessOrder(),
      );
      if ($final !== $current) {
        $entity->setModerationStatus($final);
      }
    }

    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionLogMessage($this->buildRevisionLogMessage($result));
    }

    DuplicateMatchApplyContext::set(
      $entity,
      new DuplicateMatchApplyContext(
        result: $result,
        isFormCreate: !$this->isImportedReport($entity),
      ),
    );
  }

  /**
   * Show a messenger warning with links after form create.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity.
   */
  #[Hook('entity_after_save')]
  public function entityAfterSave(EntityInterface $entity): void {
    $context = DuplicateMatchApplyContext::get($entity);
    if ($context === NULL) {
      return;
    }

    DuplicateMatchApplyContext::clear($entity);

    if (!$context->isFormCreate || !$context->result->hasMatches()) {
      return;
    }

    $this->messenger->addWarning($this->buildMessengerMessage($context->result));
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
    $method = $result->targetMethod();
    if ($method === NULL) {
      return NULL;
    }

    $settings = $this->settings();
    return $method === DuplicateMatch::METHOD_JACCARD
      ? $settings->jaccardTargetStatus
      : $settings->tfidfTargetStatus;
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

    $prefix = $result->hasHardMatches()
      ? 'Near-duplicate of'
      : 'Possible near-duplicate of';

    return $prefix . ': ' . implode('; ', $parts);
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

    $label = $result->hasHardMatches()
      ? $this->t('Possible duplicate of:')
      : $this->t('Possible duplicate (soft match) — demoted for review:');

    return new FormattableMarkup('@label @links', [
      '@label' => $label,
      '@links' => new FormattableMarkup(implode(', ', $links), []),
    ]);
  }

}
