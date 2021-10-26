<?php

namespace Drupal\Tests\reliefweb_fields\Kernel;

use Drupal\Tests\field\Kernel\FieldKernelTestBase;

/**
 * Test reliefweb links field.
 *
 * @covers \Drupal\reliefweb_fields\Plugin\Field\FieldType\ReliefWebLinks
 */
class ReliefWebLinksValidationTest extends FieldKernelTestBase {

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
      ->createDataDefinition('field_item:reliefweb_links');
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
    $violation_0 = strtr('The URL may not be longer than 2048 characters.', [
      '@max' => 2048,
    ]);
    $violation_1 = strtr('The title may not be longer than @max characters.', [
      '@max' => 1024,
    ]);
    $violation_2 = strtr('The image may not be longer than @max characters.', [
      '@max' => 2048,
    ]);

    return [
      [
        [],
        [],
      ],
      [
        [
          'url' => 'https://example.com',
        ], [
          'This value should not be null.',
          'This value should not be null.',
        ],
      ],
      [
        [
          'url' => $this->randomMachineName(3000),
          'title' => '',
          'image' => '',
          'active' => 0,
        ], [
          '',
          $violation_0,
        ],
      ],
      [
        [
          'url' => $this->randomMachineName(3000),
          'title' => $this->randomMachineName(3000),
          'image' => '',
          'active' => 0,
        ], [
          '',
          $violation_0,
          $violation_1,
        ],
      ],
      [
        [
          'url' => 'https://example.com',
          'title' => $this->randomMachineName(100),
          'image' => $this->randomMachineName(3000),
          'active' => 0,
        ], [
          $violation_2,
        ],
      ],
      [
        [
          'url' => 'https://example.com',
          'title' => $this->randomMachineName(100),
          'image' => $this->randomMachineName(100),
          'active' => -1,
        ], [
          'This value should be of the correct primitive type.',
        ],
      ],
    ];
  }

}
