<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\reliefweb_moderation\Services\TrainingModeration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the TrainingModeration service entityAccess method.
 */
#[CoversClass(TrainingModeration::class)]
#[Group('reliefweb_moderation')]
#[RunTestsInSeparateProcesses]
class TrainingModerationTest extends NodeModerationServiceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getModerationService(): ModerationServiceInterface {
    return \Drupal::service('reliefweb_moderation.training.moderation');
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
        'field_registration_deadline' => [
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
      'field_registration_deadline' => [
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
      'field_registration_deadline' => [
        'value' => time() + 86400,
      ],
    ]);
  }

}
