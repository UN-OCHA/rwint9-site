<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchApplyContext;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchWorkflowConfigFixture;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesMatchApplyContext factory and transitions.
 */
#[CoversClass(SeriesMatchApplyContext::class)]
#[Group('reliefweb_content_analyzer')]
class SeriesMatchApplyContextTest extends UnitTestCase {

  /**
   * Builds a minimal scorable result for context tests.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult
   *   A result with computable confidence scores.
   */
  private function buildResult(): SeriesMatchResult {
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
   * Builds a resolved outcome for tests.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome
   *   Resolved high-tier outcome.
   */
  private function buildOutcome(): SeriesMatchOutcome {
    $result = $this->buildResult();
    $outcome = SeriesMatchOutcome::resolve(
      $result,
      SeriesMatchWorkflowSettings::fromConfigArray(SeriesMatchWorkflowConfigFixture::defaults()),
    );
    $this->assertNotNull($outcome);
    return $outcome;
  }

  /**
   * Factory sets pendingApply and skipClassification by default.
   */
  public function testCreateForDetectPassSetsDefaultFlags(): void {
    $result = $this->buildResult();
    $outcome = $this->buildOutcome();

    $context = SeriesMatchApplyContext::createForDetectPass(
      $result,
      $outcome,
      'Original log.',
      'published',
    );

    $this->assertTrue($context->pendingApply);
    $this->assertTrue($context->skipClassification);
    $this->assertFalse($context->applying);
    $this->assertFalse($context->applied);
    $this->assertSame($result, $context->result);
    $this->assertSame($outcome, $context->outcome);
    $this->assertSame('Original log.', $context->originalRevisionLog);
    $this->assertSame('published', $context->preDraftModerationStatus);
  }

  /**
   * BeginApplying clears pendingApply and sets applying.
   */
  public function testBeginApplying(): void {
    $context = SeriesMatchApplyContext::createForDetectPass(
      $this->buildResult(),
      $this->buildOutcome(),
      '',
      NULL,
    );

    $context->beginApplying();

    $this->assertTrue($context->applying);
    $this->assertFalse($context->pendingApply);
  }

  /**
   * FromEntity returns NULL when no context is attached.
   */
  public function testFromEntityReturnsNullWhenAbsent(): void {
    $entity = $this->createMock(ContentEntityInterface::class);

    $this->assertNull(SeriesMatchApplyContext::fromEntity($entity));
  }

  /**
   * Attach and fromEntity round-trip the same context instance.
   */
  public function testAttachAndFromEntity(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $context = SeriesMatchApplyContext::createForDetectPass(
      $this->buildResult(),
      $this->buildOutcome(),
      '',
      NULL,
    );

    SeriesMatchApplyContext::attach($entity, $context);

    $this->assertSame($context, SeriesMatchApplyContext::fromEntity($entity));
  }

  /**
   * RecordAppliedModerationStatus stores the final status only.
   */
  public function testRecordAppliedModerationStatus(): void {
    $context = SeriesMatchApplyContext::createForDetectPass(
      $this->buildResult(),
      $this->buildOutcome(),
      '',
      NULL,
    );

    $context->recordAppliedModerationStatus('to-review');

    $this->assertSame('to-review', $context->appliedModerationStatus);
  }

}
