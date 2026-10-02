<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\reliefweb_moderation\Services\JobModeration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the JobModeration service entityAccess method.
 */
#[CoversClass(JobModeration::class)]
#[Group('reliefweb_moderation')]
#[RunTestsInSeparateProcesses]
class JobModerationTest extends NodeModerationServiceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getModerationService(): ModerationServiceInterface {
    return \Drupal::service('reliefweb_moderation.job.moderation');
  }

  /**
   * Create test nodes with different moderation statuses.
   */
  protected function createTestNodes(): void {
    $statuses = $this->getTestStatuses();

    foreach ($statuses as $status) {
      $this->testNodes[$status] = $this->createNode([
        'type' => $this->getBundle(),
        'title' => 'Test ' . ucfirst($this->getBundle()) . ' Own ' . ucfirst($status),
        'uid' => $this->testUsers['edit_own_bundle']->id(),
        'moderation_status' => $status,
        'field_source' => [
          ['target_id' => $this->testSource->id()],
        ],
        'field_job_closing_date' => [
          'value' => time() + 86400,
        ],
      ]);
    }

    $this->testNodes['affiliated_draft'] = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Test ' . ucfirst($this->getBundle()) . ' Affiliated Draft',
      'uid' => $this->testUsers['edit_affiliated_bundle']->id(),
      'moderation_status' => 'draft',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
      'field_job_closing_date' => [
        'value' => time() + 86400,
      ],
    ]);
    $this->testNodes['affiliated_published'] = $this->createNode([
      'type' => $this->getBundle(),
      'title' => 'Test ' . ucfirst($this->getBundle()) . ' Affiliated Published',
      'uid' => $this->testUsers['edit_affiliated_bundle']->id(),
      'moderation_status' => 'published',
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
      'field_job_closing_date' => [
        'value' => time() + 86400,
      ],
    ]);
  }

  /**
   * Test Close Job maps to withdrawn except when already expired.
   */
  public function testCloseJobButtonStatusMapping(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Close job button',
      'moderation_status' => 'published',
      'field_job_closing_date' => [
        'value' => time() + 86400,
      ],
    ]);
    $advertiser = $this->createUser(values: [
      'roles' => ['advertiser'],
    ]);
    $account_switcher = \Drupal::service('account_switcher');
    $account_switcher->switchTo($advertiser);
    try {
      $buttons = $this->getModerationService()->getEntityFormSubmitButtons('published', $entity);
      $this->assertArrayHasKey('withdrawn', $buttons);
      $this->assertArrayNotHasKey('expired', $buttons);

      $buttons = $this->getModerationService()->getEntityFormSubmitButtons('expired', $entity);
      $this->assertArrayHasKey('expired', $buttons);
    }
    finally {
      $account_switcher->switchBack();
    }
  }

}
