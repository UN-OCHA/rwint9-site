<?php

namespace Drupal\Tests\reliefweb_fields\Kernel;

use Drupal\Tests\field\Kernel\FieldKernelTestBase;

/**
 * Test reliefweb links field.
 *
 * @covers \Drupal\reliefweb_fields\Plugin\Field\FieldType\ReliefWebUserPostingRights
 */
class ReliefWebUserPostingRightsValidationTest extends FieldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['reliefweb_fields'];

  /**
   * Tests link validation.
   *
   * @dataProvider providerTestData
   */
  public function testExternalLinkValidation($value, $expected_violations) {
    $definition = \Drupal::typedDataManager()
      ->createDataDefinition('field_item:reliefweb_user_posting_rights');
    $link_item = \Drupal::typedDataManager()->create($definition);

    $link_item->setValue($value);
    $violations = $link_item->validate();

    $expected_count = count($expected_violations);
    $this->assertCount($expected_count, $violations);
    if ($expected_count) {
      $i = 0;
      foreach ($expected_violations as $error_msg) {
        if (!empty($error_msg)) {
          $this->assertEquals($error_msg, $violations[$i]->getMessage());
        }
        $i++;
      }
    }
  }

  /**
   * Builds an array of links to test.
   *
   * @return array
   *   The first element of the array is the link value to test. The second
   *   value is an array of expected violation messages.
   */
  public function providerTestData() {
    $violation_0 = '<em class="placeholder"></em>: the User Id must be a number superior or equal to 3.';
    $violation_1 = '<em class="placeholder"></em>: the Job rights must be one of 0, 1, 2 or 3.';
    $violation_2 = '<em class="placeholder"></em>: the Training rights must be one of 0, 1, 2 or 3.';

    return [
      [
        [],
        [],
      ],
      [
        [
          'id' => 0,
          'job' => 0,
          'training' => 0,
          'notes' => '',
        ], [
          $violation_0,
        ],
      ],
      [
        [
          'id' => 666,
          'job' => 0,
          'training' => 0,
          'notes' => '',
        ], [],
      ],
      [
        [
          'id' => 666,
          'job' => 4,
          'training' => 0,
          'notes' => '',
        ], [
          $violation_1,
        ],
      ],
      [
        [
          'id' => 666,
          'job' => 2,
          'training' => 6,
          'notes' => '',
        ], [
          $violation_2,
        ],
      ],
    ];
  }

}
