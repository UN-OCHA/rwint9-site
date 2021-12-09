<?php

namespace Drupal\Tests\reliefweb_fields\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\field\Kernel\FieldKernelTestBase;

/**
 * Test reliefweb links field.
 *
 * @covers \Drupal\reliefweb_fields\Plugin\Field\FieldType\ReliefWebUserPostingRights
 */
class ReliefWebUserPostingRightsTest extends FieldKernelTestBase {

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
      'type' => 'reliefweb_user_posting_rights',
      'settings' => [
        'internal' => 0,
        'use_cover' => 0,
        'keep_archives' => 0,
      ],
    ]);
    $this->fieldStorage->save();

    $this->field = FieldConfig::create([
      'field_storage' => $this->fieldStorage,
      'bundle' => 'entity_test',
      'required' => TRUE,
    ]);
    $this->field->save();

    $display_options = [
      'type' => 'reliefweb_user_posting_rights',
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
        'id' => 666,
        'job' => 0,
        'training' => 0,
        'notes' => 0,
      ],
    ]);

    $this->assertEquals($entity->{$field_name}->id, 666);
    $this->assertEquals($entity->{$field_name}->job, 0);
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
        'id' => '',
      ],
    ]);

    $this->assertEquals($entity->{$field_name}->isEmpty(), TRUE);
  }

}
