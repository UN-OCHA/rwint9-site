<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\reliefweb_moderation\Services\ReportModeration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the ReportModeration service entityAccess method.
 */
#[CoversClass(ReportModeration::class)]
#[Group('reliefweb_moderation')]
#[RunTestsInSeparateProcesses]
class ReportModerationTest extends NodeModerationServiceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getModerationService(): ModerationServiceInterface {
    return \Drupal::service('reliefweb_moderation.report.moderation');
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedTerminalStatuses(): array {
    return ['refused', 'duplicate', 'archive'];
  }

  /**
   * Test withdrawn is editable without a terminal permission.
   */
  public function testWithdrawnIsEditable(): void {
    $this->assertArrayHasKey('withdrawn', $this->getModerationService()->getStatuses());
    $this->assertTrue($this->getModerationService()->isEditableStatus('withdrawn'));
    $this->assertNotContains('withdrawn', $this->getModerationService()->getTerminalStatuses());
  }

  /**
   * Test submitter Unpublish maps to withdrawn.
   */
  public function testSubmitterUnpublishButton(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Submitter unpublish button',
      'moderation_status' => 'published',
    ]);
    $submitter = $this->createUser(values: [
      'roles' => ['submitter'],
    ]);
    $account_switcher = \Drupal::service('account_switcher');
    $account_switcher->switchTo($submitter);
    try {
      $buttons = $this->getModerationService()->getEntityFormSubmitButtons('published', $entity);
      $this->assertArrayHasKey('withdrawn', $buttons);
      $this->assertArrayNotHasKey('on-hold', $buttons);
    }
    finally {
      $account_switcher->switchBack();
    }
  }

}
