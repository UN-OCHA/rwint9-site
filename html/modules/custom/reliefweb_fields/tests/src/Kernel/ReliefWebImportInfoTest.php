<?php

namespace Drupal\Tests\reliefweb_fields\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\field\Kernel\FieldKernelTestBase;

/**
 * Test reliefweb import info field.
 *
 * @covers \Drupal\reliefweb_fields\Plugin\Field\FieldType\ReliefWebImportInfo
 */
class ReliefWebImportInfoTest extends FieldKernelTestBase {

  /**
   * A field storage to use in this test class.
   *
   * @var \Drupal\field\Entity\FieldStorageConfig
   */
  protected $fieldStorage;

  /**
   * The field used in this test class.
   *
   * @var \Drupal\field\Entity\FieldConfig
   */
  protected $field;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'reliefweb_fields',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Add a field.
    $this->fieldStorage = FieldStorageConfig::create([
      'field_name' => mb_strtolower($this->randomMachineName()),
      'entity_type' => 'entity_test',
      'type' => 'reliefweb_import_info',
    ]);
    $this->fieldStorage->save();

    $this->field = FieldConfig::create([
      'field_storage' => $this->fieldStorage,
      'bundle' => 'entity_test',
      'required' => TRUE,
    ]);
    $this->field->save();

    $display_options = [
      'type' => 'reliefweb_import_info',
      'label' => 'hidden',
    ];
    EntityViewDisplay::create([
      'targetEntityType' => $this->field->getTargetEntityTypeId(),
      'bundle' => $this->field->getTargetBundle(),
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent($this->fieldStorage->getName(), $display_options)
      ->save();
  }

  /**
   * Tests the field.
   */
  public function testField() {
    $field_name = $this->fieldStorage->getName();

    // Create an entity.
    $entity = EntityTest::create([
      'name' => $this->randomString(),
      $field_name => [
        'feed_url' => 'https://www.example.com/jobs/feed.xml',
        'base_url' => 'https://www.example.com/',
        'uid' => 0,
      ],
    ]);

    $this->assertEquals($entity->{$field_name}->feed_url, 'https://www.example.com/jobs/feed.xml');
    $this->assertEquals($entity->{$field_name}->base_url, 'https://www.example.com/');
    $this->assertEquals($entity->{$field_name}->uid, 0);
  }

  /**
   * Tests the field emptiness.
   */
  public function testFieldEmpty() {
    $field_name = $this->fieldStorage->getName();

    // Create an entity.
    $entity = EntityTest::create([
      'name' => $this->randomString(),
      $field_name => [
        'feed_url' => '',
        'base_url' => 'https://www.example.com/',
        'uid' => 0,
      ],
    ]);

    $this->assertEquals($entity->{$field_name}->isEmpty(), TRUE);
  }

}
