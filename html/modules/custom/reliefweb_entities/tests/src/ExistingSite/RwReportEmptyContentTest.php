<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\file\Entity\File;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests empty body/attachment publication prevention for reports.
 */
#[Group('reliefweb_entities')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class RwReportEmptyContentTest extends ExistingSiteBase {

  /**
   * Original empty_content config.
   *
   * @var array|null
   */
  protected ?array $originalEmptyContentConfig = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalEmptyContentConfig = \Drupal::config('reliefweb_entities.settings')
      ->get('empty_content');
    $this->setEmptyContentConfig(TRUE, 'on-hold');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalEmptyContentConfig !== NULL) {
      \Drupal::configFactory()
        ->getEditable('reliefweb_entities.settings')
        ->set('empty_content', $this->originalEmptyContentConfig)
        ->save();
    }
    parent::tearDown();
  }

  /**
   * Empty published reports are demoted to on-hold.
   */
  public function testEmptyPublishedReportIsDemotedToOnHold(): void {
    $report = $this->createReport([
      'moderation_status' => 'published',
      'body' => NULL,
    ]);

    $this->assertSame('on-hold', $report->getModerationStatus());
    $status_label = (string) ($report->getAllowedModerationStatuses()['on-hold'] ?? 'on-hold');
    $this->assertStringContainsString(
      strtr('No body or attachment: saved as **@status**.', [
        '@status' => $status_label,
      ]),
      (string) $report->get('revision_log')->value,
    );
  }

  /**
   * Empty to-review reports are demoted to on-hold.
   */
  public function testEmptyToReviewReportIsDemotedToOnHold(): void {
    $report = $this->createReport([
      'moderation_status' => 'to-review',
      'body' => NULL,
    ]);

    $this->assertSame('on-hold', $report->getModerationStatus());
  }

  /**
   * Reports with a body can stay published.
   */
  public function testBodyOnlyReportStaysPublished(): void {
    $report = $this->createReport([
      'moderation_status' => 'published',
      'body' => [
        'value' => 'Meaningful report body content.',
        'format' => 'markdown',
      ],
    ]);

    $this->assertSame('published', $report->getModerationStatus());
  }

  /**
   * Whitespace-only body is treated as empty.
   */
  public function testWhitespaceOnlyBodyIsDemoted(): void {
    $report = $this->createReport([
      'moderation_status' => 'published',
      'body' => [
        'value' => "<p>  </p>\n",
        'format' => 'markdown',
      ],
    ]);

    $this->assertSame('on-hold', $report->getModerationStatus());
  }

  /**
   * Attachment-only reports can stay published.
   */
  public function testAttachmentOnlyReportStaysPublished(): void {
    $report = $this->createReport([
      'moderation_status' => 'draft',
      'body' => NULL,
    ]);
    $this->attachDummyFile($report);
    $report->setModerationStatus('published');
    $report->save();

    $this->assertSame('published', $report->getModerationStatus());
  }

  /**
   * Demotion is skipped when prevention is disabled.
   */
  public function testPreventionDisabledAllowsEmptyPublication(): void {
    $this->setEmptyContentConfig(FALSE, 'on-hold');

    $report = $this->createReport([
      'moderation_status' => 'published',
      'body' => NULL,
    ]);

    $this->assertSame('published', $report->getModerationStatus());
  }

  /**
   * Fallback status from config is respected.
   */
  public function testConfigurableFallbackStatus(): void {
    $this->setEmptyContentConfig(TRUE, 'pending');

    $report = $this->createReport([
      'moderation_status' => 'published',
      'body' => NULL,
    ]);

    $this->assertSame('pending', $report->getModerationStatus());
  }

  /**
   * Create a report node for empty-content tests.
   *
   * @param array $values
   *   Node values.
   *
   * @return \Drupal\reliefweb_entities\Entity\Report
   *   The saved report.
   */
  protected function createReport(array $values): Report {
    $node = $this->createNode($values + [
      'type' => 'report',
      'title' => 'Empty content test report ' . $this->randomMachineName(),
    ]);
    $this->assertInstanceOf(Report::class, $node);
    return $node;
  }

  /**
   * Attach a minimal ReliefWeb file to a report.
   *
   * @param \Drupal\reliefweb_entities\Entity\Report $report
   *   Report entity.
   */
  protected function attachDummyFile(Report $report): void {
    $file = File::create([
      'uri' => 'public://empty-content-test-' . $this->randomMachineName() . '.pdf',
      'filename' => 'empty-content-test.pdf',
      'filemime' => 'application/pdf',
      'status' => 1,
    ]);
    $file->save();
    $this->markEntityForCleanup($file);

    $item = ReliefWebFile::createInstance($report->get('field_file')->getItemDefinition());
    $item->setValue([
      'uuid' => \Drupal::service('uuid')->generate(),
      'revision_id' => 0,
      'file_uuid' => $file->uuid(),
      'file_name' => $file->getFilename(),
      'file_mime' => $file->getMimeType(),
      'file_size' => 4,
      'page_count' => 1,
    ]);
    $report->set('field_file', [$item->getValue()]);
  }

  /**
   * Set empty_content settings.
   *
   * @param bool $prevent_publication
   *   Whether prevention is enabled.
   * @param string $fallback_status
   *   Fallback moderation status.
   */
  protected function setEmptyContentConfig(bool $prevent_publication, string $fallback_status): void {
    \Drupal::configFactory()
      ->getEditable('reliefweb_entities.settings')
      ->set('empty_content', [
        'prevent_publication' => $prevent_publication,
        'fallback_status' => $fallback_status,
      ])
      ->save();
  }

}
