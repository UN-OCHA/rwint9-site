<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\Field\FieldFormatter;

use Drupal\reliefweb_post_api\Entity\Provider;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the 'reliefweb_post_api_key' field formatter.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Plugin\Field\FieldFormatter\ApiKeyFormatter
 *
 * @group reliefweb_post_api
 */
class ApiKeyFormatterTest extends ExistingSiteBase {

  /**
   * @covers ::viewElements
   */
  public function testViewElements(): void {
    $entity = Provider::create(['id' => 123]);

    /** @var Drupal\reliefweb_post_api\Plugin\Field\FieldFormatter\ApiKeyWidget $formatter */
    $formatter = \Drupal::service('plugin.manager.field.formatter')->getInstance([
      'field_definition' => $entity->key->getFieldDefinition(),
      'view_mode' => 'full',
      'configuration' => [],
    ]);

    // No value.
    $result = $formatter->viewElements($entity->key, 'en');
    $this->assertSame([], $result);

    // One empty value.
    $entity->key->value = '';
    $result = $formatter->viewElements($entity->key, 'en');
    $this->assertSame('API key missing', (string) $result[0]['#context']['value']);

    // One value.
    $entity->key->value = 'test';
    $result = $formatter->viewElements($entity->key, 'en');
    $this->assertSame('API key exists', (string) $result[0]['#context']['value']);
  }

}
