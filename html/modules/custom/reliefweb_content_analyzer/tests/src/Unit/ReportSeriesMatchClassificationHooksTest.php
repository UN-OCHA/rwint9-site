<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;
use Drupal\reliefweb_content_analyzer\Hook\ReportSeriesMatchClassificationHooks;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchApplyContext;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcherInterface;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchWorkflowConfigFixture;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ReportSeriesMatchClassificationHooks presave and after-save behaviors.
 */
#[CoversClass(ReportSeriesMatchClassificationHooks::class)]
#[Group('reliefweb_content_analyzer')]
class ReportSeriesMatchClassificationHooksTest extends UnitTestCase {

  /**
   * Default workflow config shared by hook tests.
   *
   * @param array<string, mixed> $overrides
   *   Values merged over install defaults.
   *
   * @return array<string, mixed>
   *   Workflow config array for SeriesMatchWorkflowSettings::fromConfigArray().
   */
  private static function workflowConfig(array $overrides = []): array {
    return array_merge(SeriesMatchWorkflowConfigFixture::defaults(), $overrides);
  }

  /**
   * Config factory values for hook tests with default workflow settings.
   *
   * @param array<string, mixed> $workflow_overrides
   *   Values merged over default workflow config.
   *
   * @return array<string, mixed>
   *   Values returned from ImmutableConfig::get() keyed by config key.
   */
  private static function hooksConfig(array $workflow_overrides = []): array {
    return [
      'report_series_matching.workflow' => self::workflowConfig($workflow_overrides),
    ];
  }

  /**
   * Builds default workflow settings for outcome resolution in tests.
   */
  private static function defaultWorkflowSettings(): SeriesMatchWorkflowSettings {
    return SeriesMatchWorkflowSettings::fromConfigArray(self::workflowConfig());
  }

  /**
   * Builds a hook instance with all services stubbed.
   *
   * @param array<string, mixed> $config_values
   *   Values returned from ImmutableConfig::get() keyed by config key.
   * @param \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcherInterface|null $matcher
   *   Optional matcher stub (defaults to a createStub instance).
   * @param \Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface|null $classifier
   *   Optional classifier stub.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   Optional current user stub (defaults to granting form automation
   *   permission).
   *
   * @return \Drupal\reliefweb_content_analyzer\Hook\ReportSeriesMatchClassificationHooks
   *   Hook instance ready for testing.
   */
  private function buildHooks(
    array $config_values = [],
    ?ReportSeriesMatcherInterface $matcher = NULL,
    ?ContentEntityClassifierInterface $classifier = NULL,
    ?AccountInterface $account = NULL,
  ): ReportSeriesMatchClassificationHooks {
    $logger_factory = $this->createStub(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createStub(LoggerInterface::class));

    return new ReportSeriesMatchClassificationHooks(
      $this->buildConfigFactory($config_values),
      $matcher ?? $this->createStub(ReportSeriesMatcherInterface::class),
      $this->createStub(EntityFieldManagerInterface::class),
      $this->createStub(Connection::class),
      $this->createStub(TimeInterface::class),
      $classifier ?? $this->createStub(ContentEntityClassifierInterface::class),
      $account ?? $this->buildAccountWithFormAutomationPermission(),
      $logger_factory,
    );
  }

  /**
   * Builds an account stub that grants form-created automation permission.
   */
  private function buildAccountWithFormAutomationPermission(): AccountInterface {
    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $permission === 'apply report series matching automation on form create');

    return $account;
  }

  /**
   * Builds an account stub that denies all permissions.
   */
  private function buildAccountWithoutPermissions(): AccountInterface {
    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    return $account;
  }

  /**
   * Configures an entity mock as an imported report (Post API provider set).
   *
   * @param \PHPUnit\Framework\MockObject\MockObject&\Drupal\Tests\reliefweb_content_analyzer\Unit\SeriesMatchTestEntityInterface $entity
   *   Entity mock to configure.
   */
  private function configureImportedReport(SeriesMatchTestEntityInterface $entity): void {
    $provider_field = $this->createStub(FieldItemListInterface::class);
    $provider_field->method('isEmpty')->willReturn(FALSE);

    $entity->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_post_api_provider');
    $entity->method('get')
      ->willReturnCallback(static function (string $field_name) use ($provider_field): FieldItemListInterface {
        if ($field_name === 'field_post_api_provider') {
          return $provider_field;
        }
        throw new \InvalidArgumentException("Unexpected field: {$field_name}");
      });
  }

  /**
   * Builds a ConfigFactoryInterface stub from a flat key-to-value map.
   *
   * @param array<string, mixed> $config_values
   *   Values returned from ImmutableConfig::get() keyed by config key.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   A stub that delegates get() calls to the values map.
   */
  private function buildConfigFactory(array $config_values): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => $config_values[$key] ?? NULL,
    );

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    return $factory;
  }

  /**
   * Creates an entity mock that satisfies all hook interface checks.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject&\Drupal\Tests\reliefweb_content_analyzer\Unit\SeriesMatchTestEntityInterface
   *   A mock entity implementing all interfaces required by the hook class.
   */
  private function buildEntityMock(): SeriesMatchTestEntityInterface {
    return $this->createMock(SeriesMatchTestEntityInterface::class);
  }

  /**
   * Builds a SeriesMatchResult with confidence scores above the high tier.
   *
   * Series confidence: 0.40*1 + 0.25*1 + 0.20*(3/3) + 0.15 = 1.00.
   * Tagging confidence: 0.70*1.0 + 0.30*1.0 = 1.00.
   * Both above the high threshold (0.80) → outcome tier 'high'.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   A high-confidence result resolving to the 'high' outcome tier.
   */
  private function buildHighConfidenceResult(): SeriesMatchResult {
    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: ['field_theme' => [12]],
        updatedFieldSources: ['field_theme' => SeriesMatchFieldUpdateSource::AllCandidates],
        titleSource: SeriesMatchTitleSource::KeptOriginalPatternMatch,
      ),
      new SeriesMatchEvidence(
        candidateIds: [1, 2, 3],
        bestClusterShare: 1.0,
        clusterScore: 1.0,
        clusterCount: 1,
        bestClusterSize: 3,
        mergedAfterLimitCount: 3,
        bothSignalsCount: 3,
        lookbackMonths: 24,
      ),
    );
  }

  /**
   * Builds a SeriesMatchResult whose series confidence falls into 'low' tier.
   *
   * Series confidence: 0.40*0.5 + 0.25*0.5 + 0.20*(2/4) + 0 = 0.425.
   * 0.425 < 0.60 → series tier 'low' → outcome tier 'low' (min of tiers).
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   A low-confidence result resolving to the 'low' outcome tier.
   */
  private function buildLowConfidenceResult(): SeriesMatchResult {
    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      new SeriesMatchProposal(
        updatedFields: ['field_theme' => [12]],
        updatedFieldSources: ['field_theme' => SeriesMatchFieldUpdateSource::AllCandidates],
        titleSource: SeriesMatchTitleSource::KeptOriginalPatternMatch,
      ),
      new SeriesMatchEvidence(
        candidateIds: [1, 2, 3],
        bestClusterShare: 0.5,
        clusterScore: 0.5,
        clusterCount: 2,
        bestClusterSize: 3,
        mergedAfterLimitCount: 4,
        bothSignalsCount: 2,
        lookbackMonths: 24,
      ),
    );
  }

  /**
   * Resolves a SeriesMatchOutcome for a test result using default config.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The matcher result.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome
   *   Resolved outcome for test fixtures.
   */
  private function resolveOutcomeForResult(SeriesMatchResult $result): SeriesMatchOutcome {
    $outcome = SeriesMatchOutcome::resolve($result, self::defaultWorkflowSettings());
    $this->assertNotNull($outcome, 'Test fixture must resolve to a non-null outcome.');
    return $outcome;
  }

  /**
   * Attaches an applied SeriesMatchApplyContext to the entity.
   *
   * Shorthand for moderation presave tests that need the context's applied
   * flag set along with a result and optional pre-draft moderation status.
   *
   * @param \Drupal\Tests\reliefweb_content_analyzer\Unit\SeriesMatchTestEntityInterface $entity
   *   The entity to attach the context to.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The matcher result to stash in the context.
   * @param string|null $pre_draft_status
   *   Pre-draft moderation status, or NULL if not captured.
   */
  private function attachAppliedContext(
    SeriesMatchTestEntityInterface $entity,
    SeriesMatchResult $result,
    ?string $pre_draft_status = NULL,
  ): void {
    $context = SeriesMatchApplyContext::createForDetectPass(
      $result,
      $this->resolveOutcomeForResult($result),
      '',
      $pre_draft_status,
    );
    $context->markApplied();
    SeriesMatchApplyContext::attach($entity, $context);
  }

  /**
   * Loop guard: returns immediately when applying is already set.
   *
   * This covers the nested-save re-entry case where after_save fires again
   * for rev 2 and must not trigger a third save.
   */
  public function testEntityAfterSaveReturnsWhenApplyingAlreadySet(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    $result = $this->buildHighConfidenceResult();
    $context = SeriesMatchApplyContext::createForDetectPass(
      $result,
      $this->resolveOutcomeForResult($result),
      '',
      NULL,
    );
    $context->applying = TRUE;
    SeriesMatchApplyContext::attach($entity, $context);

    // If the loop guard fails, getEntityTypeId would be called next.
    $entity->expects($this->never())->method('getEntityTypeId');
    $entity->expects($this->never())->method('bundle');

    $hooks->entityAfterSave($entity);
  }

  /**
   * Returns immediately when pending_apply is not set.
   */
  public function testEntityAfterSaveReturnsWhenNoPendingApply(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    // No context attached — fromEntity returns NULL, pendingApply is falsy.
    $entity->expects($this->never())->method('getEntityTypeId');

    $hooks->entityAfterSave($entity);
  }

  /**
   * Returns immediately when the entity is not a report node.
   */
  public function testEntityAfterSaveReturnsForNonReportEntity(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    $result = $this->buildHighConfidenceResult();
    $context = SeriesMatchApplyContext::createForDetectPass(
      $result,
      $this->resolveOutcomeForResult($result),
      '',
      NULL,
    );
    SeriesMatchApplyContext::attach($entity, $context);

    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('job');

    // No save should occur since bundle is not 'report'.
    $entity->expects($this->never())->method('save');

    $hooks->entityAfterSave($entity);
  }

  /**
   * Returns immediately when the applied flag is not set.
   */
  public function testModerationPresaveReturnsWhenNotApplied(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    // No context attached — applied is falsy, hook returns immediately.
    $entity->expects($this->never())->method('getModerationStatus');

    $hooks->entityPresaveModerationAfterPostingRights($entity);
  }

  /**
   * Uses the stored pre-draft baseline when current status is 'draft'.
   *
   * Posting rights resolved to 'to-review'; series matching forced 'draft'.
   * On rev 2 the entity is still draft at presave time. The hook must restore
   * 'to-review' as baseline, then compute the more restrictive of 'to-review'
   * and the high-tier target 'published' — result: 'to-review'.
   */
  public function testModerationPresaveUsesPreDraftBaselineWhenCurrentIsDraft(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    $this->attachAppliedContext($entity, $this->buildHighConfidenceResult(), 'to-review');

    $entity->method('getModerationStatus')->willReturn('draft');
    $entity->method('getAllowedModerationStatuses')->willReturn([
      'pending' => 'Pending',
      'to-review' => 'To review',
      'published' => 'Published',
    ]);

    // More restrictive of 'to-review' (baseline) and 'published' (high-tier
    // target) is 'to-review'.
    $entity->expects($this->once())
      ->method('setModerationStatus')
      ->with('to-review');

    $hooks->entityPresaveModerationAfterPostingRights($entity);

    $this->assertSame(
      'to-review',
      SeriesMatchApplyContext::fromEntity($entity)?->appliedModerationStatus,
    );
  }

  /**
   * Uses the current status as baseline when current status is not 'draft'.
   *
   * Posting rights set 'refused' (source blocked) on rev 2 before our hook
   * runs. The hook uses 'refused' as baseline — more restrictive than the
   * high-tier target 'published' — so the final status is 'refused'. Since the
   * entity is already at 'refused', setModerationStatus is NOT called.
   */
  public function testModerationPresaveUsesCurrentStatusWhenNotDraft(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    $this->attachAppliedContext($entity, $this->buildHighConfidenceResult(), 'to-review');

    $entity->method('getModerationStatus')->willReturn('refused');
    $entity->method('getAllowedModerationStatuses')->willReturn([
      'refused' => 'Refused',
      'to-review' => 'To review',
      'published' => 'Published',
    ]);

    // 'refused' is more restrictive than 'published'. The entity is already at
    // 'refused', so the final status equals the current — no setter call.
    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresaveModerationAfterPostingRights($entity);

    $this->assertSame(
      'refused',
      SeriesMatchApplyContext::fromEntity($entity)?->appliedModerationStatus,
    );
  }

  /**
   * Skips setModerationStatus when the final status equals the current status.
   *
   * Current status is 'pending', outcome target is 'published' (high tier),
   * and 'pending' is more restrictive — same as current — so no setter call.
   */
  public function testModerationPresaveSkipsSetWhenFinalEqualsCurrentStatus(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    $this->attachAppliedContext($entity, $this->buildHighConfidenceResult());

    $entity->method('getModerationStatus')->willReturn('pending');
    $entity->method('getAllowedModerationStatuses')->willReturn([
      'pending' => 'Pending',
      'to-review' => 'To review',
      'published' => 'Published',
    ]);

    // Final status ('pending') equals current — no setter should fire.
    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresaveModerationAfterPostingRights($entity);

    $this->assertSame(
      'pending',
      SeriesMatchApplyContext::fromEntity($entity)?->appliedModerationStatus,
    );
  }

  /**
   * When the entity is not new, entityPresave returns immediately.
   *
   * ShouldAttemptSeriesMatch() requires isNew() === TRUE; existing nodes
   * (including the rev 2 nested save) are always skipped.
   */
  public function testEntityPresaveSkipsExistingEntities(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(FALSE);

    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresave($entity);
  }

  /**
   * Skips form-created automation when the current user lacks permission.
   */
  public function testEntityPresaveSkipsFormCreatedWithoutPermission(): void {
    $matcher = $this->createMock(ReportSeriesMatcherInterface::class);
    $matcher->expects($this->never())->method('findSeriesCandidates');

    $classifier = $this->createStub(ContentEntityClassifierInterface::class);
    $classifier->method('isEntityClassifiable')->willReturn(TRUE);

    $hooks = $this->buildHooks(
      self::hooksConfig(),
      $matcher,
      $classifier,
      $this->buildAccountWithoutPermissions(),
    );

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturn(FALSE);
    $entity->method('getModerationStatus')->willReturn('published');

    $hooks->entityPresave($entity);

    $this->assertNull(SeriesMatchApplyContext::fromEntity($entity));
  }

  /**
   * Runs form-created automation when the current user has permission.
   */
  public function testEntityPresaveRunsFormCreatedWithPermission(): void {
    $matcher = $this->createMock(ReportSeriesMatcherInterface::class);
    $matcher->expects($this->once())
      ->method('findSeriesCandidates')
      ->willReturn(SeriesMatchResult::stopped(SeriesMatchReason::BelowMinimumCluster));

    $classifier = $this->createStub(ContentEntityClassifierInterface::class);
    $classifier->method('isEntityClassifiable')->willReturn(TRUE);

    $hooks = $this->buildHooks(
      self::hooksConfig(),
      $matcher,
      $classifier,
      $this->buildAccountWithFormAutomationPermission(),
    );

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturn(FALSE);
    $entity->method('getModerationStatus')->willReturn('published');

    $hooks->entityPresave($entity);
  }

  /**
   * Runs imported automation without the form-create permission.
   */
  public function testEntityPresaveRunsImportedWithoutFormPermission(): void {
    $matcher = $this->createMock(ReportSeriesMatcherInterface::class);
    $matcher->expects($this->once())
      ->method('findSeriesCandidates')
      ->willReturn(SeriesMatchResult::stopped(SeriesMatchReason::BelowMinimumCluster));

    $classifier = $this->createStub(ContentEntityClassifierInterface::class);
    $classifier->method('isEntityClassifiable')->willReturn(TRUE);

    $hooks = $this->buildHooks(
      self::hooksConfig(),
      $matcher,
      $classifier,
      $this->buildAccountWithoutPermissions(),
    );

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $this->configureImportedReport($entity);
    $entity->method('getModerationStatus')->willReturn('published');

    $hooks->entityPresave($entity);
  }

  /**
   * Skips series matching when moderation status is in the skip list.
   *
   * Posting-rights and source rules may set refused before detect presave; the
   * matcher must not run and no apply context must be attached.
   */
  public function testEntityPresaveSkipsWhenModerationStatusIsRefused(): void {
    $matcher = $this->createMock(ReportSeriesMatcherInterface::class);
    $matcher->expects($this->never())->method('findSeriesCandidates');

    $classifier = $this->createStub(ContentEntityClassifierInterface::class);
    $classifier->method('isEntityClassifiable')->willReturn(TRUE);

    $hooks = $this->buildHooks(
      self::hooksConfig(),
      $matcher,
      $classifier,
    );

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturn(FALSE);
    $entity->method('getModerationStatus')->willReturn('refused');

    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresave($entity);

    $this->assertNull(SeriesMatchApplyContext::fromEntity($entity));
  }

  /**
   * Runs the matcher when moderation status is not in the skip list.
   */
  public function testEntityPresaveRunsMatcherWhenStatusNotInSkipList(): void {
    $matcher = $this->createMock(ReportSeriesMatcherInterface::class);
    $matcher->expects($this->once())
      ->method('findSeriesCandidates')
      ->willReturn(SeriesMatchResult::stopped(SeriesMatchReason::BelowMinimumCluster));

    $classifier = $this->createStub(ContentEntityClassifierInterface::class);
    $classifier->method('isEntityClassifiable')->willReturn(TRUE);

    $hooks = $this->buildHooks(
      self::hooksConfig(),
      $matcher,
      $classifier,
    );

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturn(FALSE);
    $entity->method('getModerationStatus')->willReturn('published');

    $hooks->entityPresave($entity);

    $this->assertNull(SeriesMatchApplyContext::fromEntity($entity));
  }

  /**
   * No context is attached when the matcher result falls below threshold.
   *
   * When the matcher returns a stopped result (below threshold), no context is
   * attached and draft is not forced on the entity.
   */
  public function testEntityPresaveDoesNotSetFlagsWhenNoMatchApplies(): void {
    $stopped = SeriesMatchResult::stopped(SeriesMatchReason::BelowMinimumCluster);

    $matcher = $this->createStub(ReportSeriesMatcherInterface::class);
    $matcher->method('findSeriesCandidates')->willReturn($stopped);

    $classifier = $this->createStub(ContentEntityClassifierInterface::class);
    $classifier->method('isEntityClassifiable')->willReturn(TRUE);

    $hooks = $this->buildHooks(self::hooksConfig(), $matcher, $classifier);

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturn(FALSE);
    $entity->method('getModerationStatus')->willReturn('published');

    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresave($entity);

    $this->assertNull(SeriesMatchApplyContext::fromEntity($entity));
  }

  /**
   * Builds hooks + matcher stubs for a full entityPresave "match applies" run.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The result returned by the matcher.
   *
   * @return \Drupal\reliefweb_content_analyzer\Hook\ReportSeriesMatchClassificationHooks
   *   Configured hooks instance.
   */
  private function buildPresaveMatchHooks(
    SeriesMatchResult $result,
  ): ReportSeriesMatchClassificationHooks {
    $matcher = $this->createStub(ReportSeriesMatcherInterface::class);
    $matcher->method('findSeriesCandidates')->willReturn($result);

    $classifier = $this->createStub(ContentEntityClassifierInterface::class);
    $classifier->method('isEntityClassifiable')->willReturn(TRUE);

    return $this->buildHooks(
      self::hooksConfig(),
      $matcher,
      $classifier,
    );
  }

  /**
   * Stashes the original log and appends a detection message.
   *
   * The detection message must contain the "Series found" clause and the
   * interim draft moderation clause, both appended after the original log.
   */
  public function testEntityPresaveStashesOriginalLogAndAppendsDetectionMessage(): void {
    $hooks = $this->buildPresaveMatchHooks($this->buildHighConfidenceResult());

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturn(FALSE);
    $entity->method('getModerationStatus')->willReturn('published');
    $entity->method('getRevisionLogMessage')->willReturn('Import log.');

    $capturedMessage = NULL;
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessage): void {
        $capturedMessage = $msg;
      });

    $hooks->entityPresave($entity);

    // Original log is stashed in the context before any series annotation.
    $this->assertSame(
      'Import log.',
      SeriesMatchApplyContext::fromEntity($entity)?->originalRevisionLog,
    );

    // A detection message was appended to the revision log.
    $this->assertNotNull($capturedMessage, 'setRevisionLogMessage should have been called.');

    // The original log is preserved at the start of the combined message.
    $this->assertStringStartsWith('Import log.', $capturedMessage);

    // The series found clause is present.
    $this->assertStringContainsString(
      'Series found (3 similar reports over 24 months, 100% confidence).',
      $capturedMessage,
    );

    // The interim draft moderation clause is present.
    $draft_clause = 'Moderation status: draft (original: published, reason: interim while applying series tagging).';
    $this->assertStringContainsString($draft_clause, $capturedMessage);
  }

  /**
   * Omits the moderation clause when the entity is not moderated.
   *
   * This exercises the NULL pre_draft_status branch in
   * appendDetectionRevisionLog.
   */
  public function testEntityPresaveDetectionLogOmitsModerationClauseForNonModeratedEntity(): void {
    // Build a non-moderated entity (no EntityModeratedInterface).
    // We use a plain RevisionLogInterface + ContentEntityInterface mock.
    $nonModerated = $this->createMock(RevisionLogInterface::class);

    // Since the entity does not extend ContentEntityInterface,
    // shouldAttemptSeriesMatch will return FALSE immediately. So we test the
    // builder directly via reflection.
    $hooks = $this->buildHooks();
    $result = $this->buildHighConfidenceResult();

    // Call buildSeriesFoundClause and appendDetectionRevisionLog directly.
    $buildClause = new \ReflectionMethod(
      ReportSeriesMatchClassificationHooks::class,
      'buildSeriesFoundClause',
    );
    $clause = $buildClause->invoke($hooks, $result, 1.0);

    $this->assertSame(
      'Series found (3 similar reports over 24 months, 100% confidence).',
      $clause,
    );

    // appendDetectionRevisionLog with NULL pre_draft_status should only append
    // the series found clause (no moderation line).
    $capturedMessages = [];
    $nonModerated->method('getRevisionLogMessage')->willReturn('');
    $nonModerated->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessages): void {
        $capturedMessages[] = $msg;
      });

    $appendMethod = new \ReflectionMethod(
      ReportSeriesMatchClassificationHooks::class,
      'appendDetectionRevisionLog',
    );
    $appendMethod->invoke($hooks, $nonModerated, $result, 1.0, NULL);

    $this->assertCount(1, $capturedMessages);
    $this->assertSame(
      'Series found (3 similar reports over 24 months, 100% confidence).',
      $capturedMessages[0],
    );
    $this->assertStringNotContainsString('Moderation status', $capturedMessages[0]);
  }

  /**
   * Appends a high-confidence revision log clause when the baseline is kept.
   *
   * Baseline 'to-review' is more restrictive than the high-tier target
   * 'published', so the final status stays 'to-review'. The revision log
   * clause must report the status was preserved by a high-confidence match.
   */
  public function testModerationPresaveAppendsHighConfidenceRevisionLogClause(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    $this->attachAppliedContext($entity, $this->buildHighConfidenceResult(), 'to-review');

    $entity->method('getModerationStatus')->willReturn('draft');
    $entity->method('getAllowedModerationStatuses')->willReturn([
      'pending' => 'Pending',
      'to-review' => 'To review',
      'published' => 'Published',
    ]);
    $entity->method('getRevisionLogMessage')->willReturn('Series found (...).');

    $capturedMessages = [];
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessages): void {
        $capturedMessages[] = $msg;
      });

    $hooks->entityPresaveModerationAfterPostingRights($entity);

    $this->assertNotEmpty($capturedMessages);
    $final = end($capturedMessages);
    $high_clause = 'Moderation status: to-review (original: to-review, reason: high-confidence series match).';
    $this->assertStringContainsString($high_clause, $final);
  }

  /**
   * Appends a low-confidence log clause when the status is downgraded.
   *
   * The outcome tier is 'low' and its target 'pending' is more restrictive
   * than the baseline 'published', so the status is downgraded. The revision
   * log clause must report the downgrade and the reason.
   */
  public function testModerationPresappendsLowConfidenceRevisionLogClauseOnDowngrade(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    $this->attachAppliedContext($entity, $this->buildLowConfidenceResult(), 'published');

    $entity->method('getModerationStatus')->willReturn('draft');
    $entity->method('getAllowedModerationStatuses')->willReturn([
      'pending' => 'Pending',
      'published' => 'Published',
    ]);
    $entity->method('getRevisionLogMessage')->willReturn('Series found (...).');

    $capturedMessages = [];
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessages): void {
        $capturedMessages[] = $msg;
      });

    $hooks->entityPresaveModerationAfterPostingRights($entity);

    // Status was downgraded from 'published' to 'pending'.
    $this->assertSame(
      'pending',
      SeriesMatchApplyContext::fromEntity($entity)?->appliedModerationStatus,
    );

    // Revision log records the actual applied status, original, and reason.
    $this->assertNotEmpty($capturedMessages);
    $final = end($capturedMessages);
    $low_clause = 'Moderation status: pending (original: published, reason: low-confidence series match).';
    $this->assertStringContainsString($low_clause, $final);
  }

  /**
   * Appends the moderation log clause even when the status is unchanged.
   *
   * Current and final are both 'pending' (baseline more restrictive than the
   * high-tier target 'published'), so no status change occurs, but the log
   * still records the decision.
   */
  public function testModerationPresaveAppendsRevisionLogEvenWhenStatusUnchanged(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    $this->attachAppliedContext($entity, $this->buildHighConfidenceResult());
    // No pre-draft status: baseline = current.
    $entity->method('getModerationStatus')->willReturn('pending');
    $entity->method('getAllowedModerationStatuses')->willReturn([
      'pending' => 'Pending',
      'to-review' => 'To review',
      'published' => 'Published',
    ]);
    $entity->method('getRevisionLogMessage')->willReturn('Series found (...).');

    $capturedMessages = [];
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessages): void {
        $capturedMessages[] = $msg;
      });

    // Status is already 'pending' (equal to final) — no status change.
    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresaveModerationAfterPostingRights($entity);

    // The revision log clause is appended despite no status setter call.
    $this->assertNotEmpty(
      $capturedMessages,
      'Revision log should be updated even when status does not change.',
    );
    $final = end($capturedMessages);
    $this->assertStringContainsString('Moderation status: pending', $final);
    $this->assertStringContainsString('high-confidence series match', $final);
  }

  /**
   * Builds the expected "Series found (...)" sentence.
   */
  public function testBuildSeriesFoundClause(): void {
    $hooks = $this->buildHooks();
    $result = $this->buildHighConfidenceResult();

    $method = new \ReflectionMethod(
      ReportSeriesMatchClassificationHooks::class,
      'buildSeriesFoundClause',
    );
    $actual = $method->invoke($hooks, $result, 0.8);

    $this->assertSame(
      'Series found (3 similar reports over 24 months, 80% confidence).',
      $actual,
    );
  }

  /**
   * Data provider for buildFinalModerationClause.
   *
   * @return array<string, array<int, string>>
   *   Keys: outcomeTier, targetModeration, appliedModeration, baseline,
   *   expected.
   */
  public static function buildFinalModerationClauseProvider(): array {
    return [
      'high tier status kept' => [
        'outcomeTier' => 'high',
        'targetModeration' => 'published',
        'appliedModeration' => 'to-review',
        'baseline' => 'to-review',
        'expected' => 'Moderation status: to-review (original: to-review, reason: high-confidence series match).',
      ],
      'low tier status downgraded' => [
        'outcomeTier' => 'low',
        'targetModeration' => 'pending',
        'appliedModeration' => 'pending',
        'baseline' => 'published',
        'expected' => 'Moderation status: pending (original: published, reason: low-confidence series match).',
      ],
      'medium tier status kept' => [
        'outcomeTier' => 'medium',
        'targetModeration' => 'to-review',
        'appliedModeration' => 'to-review',
        'baseline' => 'to-review',
        'expected' => 'Moderation status: to-review (original: to-review, reason: medium-confidence series match).',
      ],
    ];
  }

  /**
   * Formats the moderation status revision log line correctly.
   *
   * @param string $outcomeTier
   *   The outcome tier (high/medium/low).
   * @param string $targetModeration
   *   The target moderation status from the outcome.
   * @param string $appliedModeration
   *   The moderation status actually applied.
   * @param string $baseline
   *   The pre-draft baseline moderation status.
   * @param string $expected
   *   The expected formatted string.
   */
  #[DataProvider('buildFinalModerationClauseProvider')]
  public function testBuildFinalModerationClause(
    string $outcomeTier,
    string $targetModeration,
    string $appliedModeration,
    string $baseline,
    string $expected,
  ): void {
    $hooks = $this->buildHooks();

    $outcome = new SeriesMatchOutcome(
      seriesConfidence: 1.0,
      taggingConfidence: 1.0,
      seriesTier: $outcomeTier,
      taggingTier: $outcomeTier,
      outcomeTier: $outcomeTier,
      targetModerationStatus: $targetModeration,
    );

    $method = new \ReflectionMethod(
      ReportSeriesMatchClassificationHooks::class,
      'buildFinalModerationClause',
    );
    $actual = $method->invoke($hooks, $outcome, $appliedModeration, $baseline);

    $this->assertSame($expected, $actual);
  }

}
