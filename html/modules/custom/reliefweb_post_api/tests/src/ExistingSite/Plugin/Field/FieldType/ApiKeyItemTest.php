<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\Field\FieldType;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Password\PasswordInterface;
use Drupal\reliefweb_post_api\Entity\Provider;
use Drupal\reliefweb_post_api\Plugin\Field\FieldType\ApiKeyItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the 'reliefweb_post_api_key' field item.
 */
#[CoversClass(ApiKeyItem::class)]
#[Group('reliefweb_post_api')]
class ApiKeyItemTest extends ExistingSiteBase {

  /**
   * Test property definitions.
   */
  public function testPropertyDefinitions(): void {
    $entity = Provider::create(['id' => 123]);

    $field_storage_definition = $entity->key->getFieldDefinition()->getFieldStorageDefinition();

    $definitions = ApiKeyItem::propertyDefinitions($field_storage_definition);

    $this->assertSame(['value', 'existing', 'pre_hashed'], array_keys($definitions));
  }

  /**
   * Test pre save.
   */
  public function testPreSave(): void {
    $manager = \Drupal::service('plugin.manager.field.field_type');
    $password = \Drupal::service('password');

    $value = 'test';
    $hashed_value = $password->hash(trim($value));

    $entity = Provider::create(['id' => 123]);
    $entity->setOriginal(Provider::create(['id' => 123, 'key' => 'other']));

    $item = $manager->createFieldItem($entity->key, 0, NULL);
    $item->setValue(['value' => $value]);
    $this->assertSame($value, $item->value);

    $item->preSave();
    $this->assertNotSame($value, $item->value);
    $this->assertTrue($password->check($value, $item->value));

    $item->setValue([
      'pre_hashed' => TRUE,
      'value' => $hashed_value,
    ]);
    $item->preSave();
    $this->assertSame($hashed_value, $item->value);
    $this->assertFalse($item->pre_hashed);

    $item->setValue([]);
    $item->preSave();
    $this->assertSame('other', $item->value);

    $this->expectException(EntityMalformedException::class);
    $item->setValue(str_pad('', PasswordInterface::PASSWORD_MAX_LENGTH + 1, '-'));
    $item->preSave();
  }

  /**
   * Test is empty.
   */
  public function testIsEmpty(): void {
    $entity = Provider::create(['id' => 123]);
    $manager = \Drupal::service('plugin.manager.field.field_type');

    $item = $manager->createFieldItem($entity->key, 0, NULL);
    $this->assertTrue($item->isEmpty());

    $item->setValue('test');
    $this->assertFalse($item->isEmpty());
    $this->assertSame('test', $item->value);
  }

}
