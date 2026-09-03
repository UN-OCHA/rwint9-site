<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_api\Indexing\ReliefWebApiIndexingSkipStore;
use Drupal\reliefweb_content_analyzer\Hook\ReportDuplicateMatchHooks;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchApplyContext;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;
use Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcherInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests ReportDuplicateMatchHooks presave and after-save behaviors.
 */
#[CoversClass(ReportDuplicateMatchHooks::class)]
#[Group('reliefweb_content_analyzer')]
class ReportDuplicateMatchHooksTest extends UnitTestCase {

  /**
   * Builds a hook instance with stubbed services.
   *
   * @param array<string, mixed> $config_values
   *   Values returned from ImmutableConfig::get() keyed by config key.
   * @param \Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcherInterface|null $matcher
   *   Optional matcher stub.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   Optional current user stub.
   *
   * @return \Drupal\reliefweb_content_analyzer\Hook\ReportDuplicateMatchHooks
   *   Hook instance ready for testing.
   */
  private function buildHooks(
    array $config_values = [],
    ?ReportDuplicateMatcherInterface $matcher = NULL,
    ?AccountInterface $account = NULL,
  ): ReportDuplicateMatchHooks {
    $logger_factory = $this->createStub(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createStub(LoggerInterface::class));

    return new ReportDuplicateMatchHooks(
      $this->buildConfigFactory($config_values),
      $matcher ?? $this->createStub(ReportDuplicateMatcherInterface::class),
      $account ?? $this->buildAccountWithFormAutomationPermission(),
      $this->createStub(MessengerInterface::class),
      $logger_factory,
    );
  }

  /**
   * Builds an account stub that grants form-created duplication permission.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   Account stub granting form automation permission only.
   */
  private function buildAccountWithFormAutomationPermission(): AccountInterface {
    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $permission === 'apply report duplication automation on form create');

    return $account;
  }

  /**
   * Builds an account stub that denies all permissions.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   Account stub that denies every permission check.
   */
  private function buildAccountWithoutPermissions(): AccountInterface {
    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    return $account;
  }

  /**
   * Configures an entity mock as an imported report.
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
   * Builds a detection result with one match of the given method.
   *
   * @param string $method
   *   DuplicateMatch::METHOD_JACCARD or METHOD_EMBEDDING.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult
   *   Result with matches.
   */
  private function buildMatchResult(string $method = DuplicateMatch::METHOD_JACCARD): DuplicateMatchResult {
    return new DuplicateMatchResult(
      matches: [
        new DuplicateMatch(42, 'Matched report', 0.95, '/node/42', $method),
      ],
      reason: 'matched',
    );
  }

  /**
   * Default duplicate-matching config plus optional restrictiveness order.
   *
   * @return array<string, mixed>
   *   Values for ImmutableConfig::get().
   */
  private static function hooksConfig(): array {
    return [
      'report_duplicate_matching' => DuplicateMatchSettings::defaultConfig(),
      'report_series_matching.workflow.restrictiveness_order' => [
        'refused',
        'duplicate',
        'draft',
        'on-hold',
        'pending',
        'to-review',
        'embargoed',
        'reference',
        'published',
      ],
    ];
  }

  /**
   * Configures a new report entity for a detect-presave run.
   *
   * @param \PHPUnit\Framework\MockObject\MockObject&\Drupal\Tests\reliefweb_content_analyzer\Unit\SeriesMatchTestEntityInterface $entity
   *   Entity mock.
   * @param string $status
   *   Current moderation status.
   * @param string $revision_log
   *   Current revision log.
   */
  private function configureNewReport(
    SeriesMatchTestEntityInterface $entity,
    string $status = 'published',
    string $revision_log = 'Import log.',
  ): void {
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturn(FALSE);
    $entity->method('getModerationStatus')->willReturn($status);
    $entity->method('getRevisionLogMessage')->willReturn($revision_log);
  }

  /**
   * Jaccard and embedding matches both force draft and attach apply context.
   *
   * @param string $method
   *   Scoring method on the match.
   */
  #[DataProvider('matchMethodProvider')]
  public function testEntityPresaveForcesDraftAndAttachesContext(string $method): void {
    $result = $this->buildMatchResult($method);
    $matcher = $this->createStub(ReportDuplicateMatcherInterface::class);
    $matcher->method('findDuplicates')->willReturn($result);

    $hooks = $this->buildHooks(self::hooksConfig(), $matcher);

    $entity = $this->buildEntityMock();
    $this->configureNewReport($entity);
    $entity->expects($this->once())->method('setModerationStatus')->with('draft');

    $capturedMessage = NULL;
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessage): void {
        $capturedMessage = $msg;
      });

    $hooks->entityPresave($entity);

    $context = DuplicateMatchApplyContext::fromEntity($entity);
    $this->assertNotNull($context);
    $this->assertTrue($context->pendingApply);
    $this->assertTrue($context->skipClassification);
    $this->assertSame($result, $context->result);
    $this->assertSame('Import log.', $context->originalRevisionLog);
    $this->assertSame('published', $context->preDraftModerationStatus);
    $this->assertSame('duplicate', $context->targetStatus);
    $this->assertTrue(ReliefWebApiIndexingSkipStore::consumeSkip($entity));

    $this->assertNotNull($capturedMessage);
    $this->assertStringStartsWith('Import log.', $capturedMessage);
    $this->assertStringContainsString('Near-duplicate of: Matched report (nid 42, ' . $method . ' 95%)', $capturedMessage);
    $this->assertStringContainsString(
      'Moderation status: draft (original: published, reason: interim while applying duplicate status).',
      $capturedMessage,
    );
  }

  /**
   * Scoring methods that both apply as production matches.
   *
   * @return array<string, array{0: string}>
   *   Method cases.
   */
  public static function matchMethodProvider(): array {
    return [
      'jaccard' => [DuplicateMatch::METHOD_JACCARD],
      'embedding' => [DuplicateMatch::METHOD_EMBEDDING],
    ];
  }

  /**
   * No context is attached when the matcher finds no matches.
   */
  public function testEntityPresaveDoesNotAttachContextWhenNoMatches(): void {
    $matcher = $this->createMock(ReportDuplicateMatcherInterface::class);
    $matcher->expects($this->once())
      ->method('findDuplicates')
      ->willReturn(new DuplicateMatchResult(reason: 'no_matches'));

    $hooks = $this->buildHooks(self::hooksConfig(), $matcher);

    $entity = $this->buildEntityMock();
    $this->configureNewReport($entity);
    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresave($entity);

    $this->assertNull(DuplicateMatchApplyContext::fromEntity($entity));
  }

  /**
   * Detection is skipped when moderation status is already refused.
   */
  public function testEntityPresaveSkipsWhenModerationStatusIsRefused(): void {
    $matcher = $this->createMock(ReportDuplicateMatcherInterface::class);
    $matcher->expects($this->never())->method('findDuplicates');

    $hooks = $this->buildHooks(self::hooksConfig(), $matcher);

    $entity = $this->buildEntityMock();
    $this->configureNewReport($entity, 'refused');
    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresave($entity);

    $this->assertNull(DuplicateMatchApplyContext::fromEntity($entity));
  }

  /**
   * Existing entities (including rev 2) skip detection.
   */
  public function testEntityPresaveSkipsExistingEntities(): void {
    $matcher = $this->createMock(ReportDuplicateMatcherInterface::class);
    $matcher->expects($this->never())->method('findDuplicates');

    $hooks = $this->buildHooks(self::hooksConfig(), $matcher);

    $entity = $this->buildEntityMock();
    $entity->method('isNew')->willReturn(FALSE);
    $entity->expects($this->never())->method('setModerationStatus');

    $hooks->entityPresave($entity);
  }

  /**
   * Skips form-created automation when the current user lacks permission.
   */
  public function testEntityPresaveSkipsFormCreatedWithoutPermission(): void {
    $matcher = $this->createMock(ReportDuplicateMatcherInterface::class);
    $matcher->expects($this->never())->method('findDuplicates');

    $hooks = $this->buildHooks(
      self::hooksConfig(),
      $matcher,
      $this->buildAccountWithoutPermissions(),
    );

    $entity = $this->buildEntityMock();
    $this->configureNewReport($entity);

    $hooks->entityPresave($entity);

    $this->assertNull(DuplicateMatchApplyContext::fromEntity($entity));
  }

  /**
   * Runs imported automation without the form-create permission.
   */
  public function testEntityPresaveRunsImportedWithoutFormPermission(): void {
    $matcher = $this->createMock(ReportDuplicateMatcherInterface::class);
    $matcher->expects($this->once())
      ->method('findDuplicates')
      ->willReturn(new DuplicateMatchResult(reason: 'no_matches'));

    $hooks = $this->buildHooks(
      self::hooksConfig(),
      $matcher,
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
   * Loop guard: returns immediately when applying is already set.
   */
  public function testEntityAfterSaveReturnsWhenApplyingAlreadySet(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    $context = DuplicateMatchApplyContext::createForDetectPass(
      $this->buildMatchResult(),
      FALSE,
      '',
      NULL,
      'duplicate',
    );
    $context->applying = TRUE;
    DuplicateMatchApplyContext::attach($entity, $context);

    $entity->expects($this->never())->method('getEntityTypeId');
    $entity->expects($this->never())->method('save');

    $hooks->entityAfterSave($entity);
  }

  /**
   * Returns immediately when pending apply is not set.
   */
  public function testEntityAfterSaveReturnsWhenNoPendingApply(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    $entity->expects($this->never())->method('getEntityTypeId');

    $hooks->entityAfterSave($entity);
  }

  /**
   * Restores log, appends match, then detaches.
   */
  public function testEntityAfterSaveRestoresOriginalLogAndSavesNewRevision(): void {
    $result = $this->buildMatchResult();
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');

    $currentLog = 'draft detection log';
    $entity->method('getRevisionLogMessage')
      ->willReturnCallback(static function () use (&$currentLog): string {
        return $currentLog;
      });
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$currentLog): void {
        $currentLog = $msg;
      });

    $entity->expects($this->once())->method('setNewRevision')->with(TRUE);
    $entity->expects($this->once())->method('save');

    DuplicateMatchApplyContext::attach(
      $entity,
      DuplicateMatchApplyContext::createForDetectPass(
        $result,
        FALSE,
        'Import log.',
        'published',
        'duplicate',
      ),
    );

    $hooks->entityAfterSave($entity);

    $this->assertNull(DuplicateMatchApplyContext::fromEntity($entity));
    $this->assertStringStartsWith('Import log.', $currentLog);
    $this->assertStringContainsString('Near-duplicate of: Matched report (nid 42, jaccard 95%)', $currentLog);
  }

  /**
   * Rev 2 moderation uses the pre-draft baseline and demotes to duplicate.
   */
  public function testModerationPresaveDemotesToDuplicateUsingPreDraftBaseline(): void {
    $hooks = $this->buildHooks(self::hooksConfig());

    $entity = $this->buildEntityMock();
    $context = DuplicateMatchApplyContext::createForDetectPass(
      $this->buildMatchResult(),
      FALSE,
      'Import log.',
      'published',
      'duplicate',
    );
    $context->markApplied();
    DuplicateMatchApplyContext::attach($entity, $context);

    $entity->method('getModerationStatus')->willReturn('draft');
    $entity->method('getAllowedModerationStatuses')->willReturn([
      'draft' => 'Draft',
      'duplicate' => 'Duplicate',
      'published' => 'Published',
    ]);
    $entity->method('getRevisionLogMessage')->willReturn('Near-duplicate of: Matched report (nid 42, jaccard 95%)');
    $entity->expects($this->once())->method('setModerationStatus')->with('duplicate');

    $capturedMessage = NULL;
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessage): void {
        $capturedMessage = $msg;
      });

    $hooks->entityPresaveModerationAfterPostingRights($entity);

    $this->assertSame('duplicate', $context->appliedModerationStatus);
    $this->assertNotNull($capturedMessage);
    $this->assertStringContainsString(
      'Moderation status: duplicate (original: published, reason: near-duplicate detection).',
      $capturedMessage,
    );
  }

  /**
   * Skip-classification alter is true when apply context is attached.
   */
  public function testSkipClassificationAlterWhenContextPresent(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    DuplicateMatchApplyContext::attach(
      $entity,
      DuplicateMatchApplyContext::createForDetectPass(
        $this->buildMatchResult(),
        FALSE,
        '',
        'published',
        'duplicate',
      ),
    );

    $entity->method('getRevisionLogMessage')->willReturn('Near-duplicate of: Matched report (nid 42, jaccard 95%)');
    $capturedMessage = NULL;
    $entity->method('setRevisionLogMessage')
      ->willReturnCallback(static function (string $msg) use (&$capturedMessage): void {
        $capturedMessage = $msg;
      });

    $skip = FALSE;
    $hooks->skipClassificationAlter(
      $skip,
      $this->createStub(ClassificationWorkflowInterface::class),
      ['entity' => $entity],
    );

    $this->assertTrue($skip);
    $this->assertNotNull($capturedMessage);
    $this->assertStringContainsString('Automated classification skipped.', $capturedMessage);
  }

  /**
   * Skip-classification alter is unchanged when no duplicate context exists.
   */
  public function testSkipClassificationAlterWhenNoContext(): void {
    $hooks = $this->buildHooks();

    $entity = $this->buildEntityMock();
    $skip = FALSE;
    $hooks->skipClassificationAlter(
      $skip,
      $this->createStub(ClassificationWorkflowInterface::class),
      ['entity' => $entity],
    );

    $this->assertFalse($skip);
  }

}
