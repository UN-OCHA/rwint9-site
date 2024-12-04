<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Entity\Provider;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the 'reliefweb_post_api_key' field widget.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Plugin\Field\FieldWidget\ApiKeyWidget
 *
 * @group reliefweb_post_api
 */
class ApiKeyWidgetTest extends ExistingSiteBase {

  /**
   * @covers ::formElement
   */
  public function testFormElement(): void {
    $form = [];
    $element = [
      '#title' => 'test',
      '#description' => new TranslatableMarkup('test description'),
    ];
    $entity = Provider::create(['id' => 123]);

    $form_object = $this->createConfiguredMock(EntityFormInterface::class, [
      'getEntity' => $entity,
    ]);
    $form_state = $this->createConfiguredMock(FormStateInterface::class, [
      'getFormObject' => $form_object,
    ]);

    /** @var Drupal\reliefweb_post_api\Plugin\Field\FieldWidget\ApiKeyWidget $widget */
    $widget = \Drupal::service('plugin.manager.field.widget')->getInstance([
      'field_definition' => $entity->key->getFieldDefinition(),
    ]);

    // New entity.
    $entity->enforceIsNew(TRUE);
    $result = $widget->formElement($entity->key, 0, $element, $form, $form_state);
    $this->assertSame([
      '#title' => 'test',
      '#description' => $element['#description'],
      '#type' => 'textfield',
      '#default_value' => NULL,
      '#size' => $widget->getSetting('size'),
      '#placeholder' => $widget->getSetting('placeholder'),
      '#maxlength' => $entity->key->getFieldDefinition()->getSetting('max_length'),
    ], $result['value']);

    // Existing entity.
    $entity->enforceIsNew(FALSE);
    $result = $widget->formElement($entity->key, 0, $element, $form, $form_state);
    $this->assertSame('test description. <strong>Leave blank to keep the existing API key</strong>.', (string) $result['value']['#description'] ?? '');
  }

}
