<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\reliefweb_entities\Entity\Job;
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
    $job = $this->createJobStubWithImportErrors([
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
   * Create a job stub with `_import_errors` and labeled fields.
   *
   * @param array $fields
   *   Map of field name to label and error message.
   *
   * @return \Drupal\reliefweb_entities\Entity\Job
   *   Job stub.
   */
  protected function createJobStubWithImportErrors(array $fields): Job {
    $field_lists = [];
    foreach ($fields as $field_name => $info) {
      $definition = $this->prophesize(FieldDefinitionInterface::class);
      $definition->getLabel()->willReturn($info['label']);

      $item_list = $this->prophesize(FieldItemListInterface::class);
      $item_list->getFieldDefinition()->willReturn($definition->reveal());
      $field_lists[$field_name] = $item_list->reveal();
    }

    $errors = array_map(static fn(array $info): string => $info['error'], $fields);

    return new class($field_lists, $errors) extends Job {

      /**
       * Import errors keyed by field name.
       *
       * Exposed as `_import_errors` via __get()/__isset() to match production.
       *
       * @var array<string, string>
       */
      private array $importErrors = [];

      /**
       * Field item lists keyed by field name.
       *
       * @var array<string, \Drupal\Core\Field\FieldItemListInterface>
       */
      private array $fieldLists;

      /**
       * {@inheritdoc}
       */
      public function __construct(array $field_lists, array $errors) {
        // Avoid ContentEntityBase construction; this stub only supports the
        // property access used by formatImportErrorsForRevisionLog().
        $this->fieldLists = $field_lists;
        $this->importErrors = $errors;
      }

      /**
       * {@inheritdoc}
       */
      public function &__get($name) {
        if ($name === '_import_errors') {
          $value = $this->importErrors;
          return $value;
        }
        $value = $this->fieldLists[$name] ?? NULL;
        return $value;
      }

      /**
       * {@inheritdoc}
       */
      public function __isset($name) {
        if ($name === '_import_errors') {
          return TRUE;
        }
        return isset($this->fieldLists[$name]);
      }

    };
  }

}
