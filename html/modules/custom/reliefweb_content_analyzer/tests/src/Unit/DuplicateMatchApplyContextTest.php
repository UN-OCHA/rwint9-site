<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchApplyContext;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DuplicateMatchApplyContext factory and transitions.
 */
#[CoversClass(DuplicateMatchApplyContext::class)]
#[Group('reliefweb_content_analyzer')]
class DuplicateMatchApplyContextTest extends UnitTestCase {

  /**
   * Builds a result with one Jaccard match.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult
   *   Result with matches.
   */
  private function buildResult(): DuplicateMatchResult {
    return new DuplicateMatchResult(
      matches: [
        new DuplicateMatch(1, 'Other', 0.95, '/node/1', DuplicateMatch::METHOD_JACCARD),
      ],
      reason: 'matched',
    );
  }

  /**
   * Factory sets pendingApply and skipClassification by default.
   */
  public function testCreateForDetectPassSetsDefaultFlags(): void {
    $result = $this->buildResult();
    $context = DuplicateMatchApplyContext::createForDetectPass(
      $result,
      TRUE,
      'Original log.',
      'published',
      'duplicate',
    );

    $this->assertTrue($context->pendingApply);
    $this->assertTrue($context->skipClassification);
    $this->assertFalse($context->applying);
    $this->assertFalse($context->applied);
    $this->assertSame($result, $context->result);
    $this->assertTrue($context->isFormCreate);
    $this->assertSame('Original log.', $context->originalRevisionLog);
    $this->assertSame('published', $context->preDraftModerationStatus);
    $this->assertSame('duplicate', $context->targetStatus);
  }

  /**
   * BeginApplying clears pendingApply and sets applying.
   */
  public function testBeginApplying(): void {
    $context = DuplicateMatchApplyContext::createForDetectPass(
      $this->buildResult(),
      FALSE,
      '',
      NULL,
      'duplicate',
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

    $this->assertNull(DuplicateMatchApplyContext::fromEntity($entity));
  }

  /**
   * Attach and fromEntity round-trip the same context instance.
   */
  public function testAttachAndFromEntity(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $context = DuplicateMatchApplyContext::createForDetectPass(
      $this->buildResult(),
      FALSE,
      '',
      NULL,
      'duplicate',
    );

    DuplicateMatchApplyContext::attach($entity, $context);

    $this->assertSame($context, DuplicateMatchApplyContext::fromEntity($entity));
  }

  /**
   * Detach removes the context so fromEntity returns NULL.
   */
  public function testDetach(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $context = DuplicateMatchApplyContext::createForDetectPass(
      $this->buildResult(),
      FALSE,
      '',
      NULL,
      'duplicate',
    );

    DuplicateMatchApplyContext::attach($entity, $context);
    DuplicateMatchApplyContext::detach($entity);

    $this->assertNull(DuplicateMatchApplyContext::fromEntity($entity));
  }

  /**
   * RecordAppliedModerationStatus stores the final status only.
   */
  public function testRecordAppliedModerationStatus(): void {
    $context = DuplicateMatchApplyContext::createForDetectPass(
      $this->buildResult(),
      FALSE,
      '',
      NULL,
      'duplicate',
    );

    $context->recordAppliedModerationStatus('duplicate');

    $this->assertSame('duplicate', $context->appliedModerationStatus);
  }

}
