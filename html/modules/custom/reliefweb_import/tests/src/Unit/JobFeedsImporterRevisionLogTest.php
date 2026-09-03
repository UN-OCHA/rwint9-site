<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_import\JobImport\JobImportStateStore;
use Drupal\reliefweb_import\Service\JobFeedsImporter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests editorial revision log formatting for import errors.
 */
#[CoversClass(JobFeedsImporter::class)]
class JobFeedsImporterRevisionLogTest extends JobFeedsImporterTestBase {

  /**
   * Tests detection of the default required/empty validation message.
   */
  public function testIsMissingRequiredValueMessage(): void {
    $this->assertTrue($this->invokeProtectedMethod('isMissingRequiredValueMessage', [
      'This value should not be null.',
    ]));
    $this->assertTrue($this->invokeProtectedMethod('isMissingRequiredValueMessage', [
      'This value should not be null',
    ]));
    $this->assertTrue($this->invokeProtectedMethod('isMissingRequiredValueMessage', [
      'this value should not be null.',
    ]));
    $this->assertFalse($this->invokeProtectedMethod('isMissingRequiredValueMessage', [
      'Invalid field size for field_how_to_apply, 13 characters found, has to be between 100 and 10000.',
    ]));
  }

  /**
   * Tests editorial formatting of import errors for the revision log.
   */
  public function testFormatImportErrorsForRevisionLog(): void {
    $job = $this->createJobStubWithImportState([
      'field_job_type' => [
        'label' => 'Job type',
        'error' => 'This value should not be null.',
      ],
      'field_how_to_apply' => [
        'label' => 'How to apply',
        'error' => 'Invalid field size for field_how_to_apply, 13 characters found, has to be between 100 and 10000.',
      ],
    ]);

    $messages = $this->invokeProtectedMethod('formatImportErrorsForRevisionLog', [$job]);
    $this->assertSame([
      'Job type is missing.',
      'How to apply: Invalid field size for field_how_to_apply, 13 characters found, has to be between 100 and 10000.',
    ], $messages);
  }

  /**
   * Tests revision log formatting includes import notes.
   */
  public function testFormatImportMessagesForRevisionLogIncludesNotes(): void {
    $job = $this->createJobStubWithImportState([], [
      'Experience set to 5-9 years (consultancy default).',
    ]);

    $messages = $this->invokeProtectedMethod('formatImportMessagesForRevisionLog', [$job]);
    $this->assertSame([
      'Experience set to 5-9 years (consultancy default).',
    ], $messages);
  }

  /**
   * Create a job stub with import state in JobImportStateStore.
   *
   * @param array $fields
   *   Map of field name to label and error message.
   * @param string[] $notes
   *   Import notes.
   *
   * @return \Drupal\reliefweb_entities\Entity\Job
   *   Job stub.
   */
  protected function createJobStubWithImportState(array $fields, array $notes = []): Job {
    $field_lists = [];
    foreach ($fields as $field_name => $info) {
      $definition = $this->prophesize(FieldDefinitionInterface::class);
      $definition->getLabel()->willReturn($info['label']);

      $item_list = $this->prophesize(FieldItemListInterface::class);
      $item_list->getFieldDefinition()->willReturn($definition->reveal());
      $field_lists[$field_name] = $item_list->reveal();
    }

    $job = new class($field_lists) extends Job {

      /**
       * Field item lists keyed by field name.
       *
       * @var array<string, \Drupal\Core\Field\FieldItemListInterface>
       */
      private array $fieldLists;

      /**
       * {@inheritdoc}
       */
      public function __construct(array $field_lists) {
        $this->fieldLists = $field_lists;
      }

      /**
       * {@inheritdoc}
       */
      public function &__get($name) {
        $value = $this->fieldLists[$name] ?? NULL;
        return $value;
      }

      /**
       * {@inheritdoc}
       */
      public function __isset($name) {
        return isset($this->fieldLists[$name]);
      }

    };

    JobImportStateStore::markImporting($job);
    foreach ($fields as $field_name => $info) {
      JobImportStateStore::setError($job, $field_name, $info['error']);
    }
    foreach ($notes as $note) {
      JobImportStateStore::addNote($job, $note);
    }

    return $job;
  }

}
