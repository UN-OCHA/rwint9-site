<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Form;

use Drupal\Core\Form\FormState;
use Drupal\reliefweb_post_api\Entity\Provider;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use Drupal\reliefweb_post_api\Form\ProviderForm;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb POST API provider form.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Form\ProviderForm
 *
 * @group reliefweb_post_api
 */
class ProviderFormTest extends ExistingSiteBase {

  /**
   * @covers ::__construct()
   */
  public function testConstructor(): void {
    $container = \Drupal::getContainer();

    $form_object = new ProviderForm(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('password'),
      $container->get('plugin.manager.reliefweb_post_api.content_processor')
    );
    $this->assertInstanceOf(ProviderForm::class, $form_object);
  }

  /**
   * @covers ::create()
   */
  public function testCreate(): void {
    $form_object = ProviderForm::create(\Drupal::getContainer());
    $this->assertInstanceOf(ProviderForm::class, $form_object);
  }

  /**
   * @covers ::form()
   */
  public function testForm(): void {
    $form_object = \Drupal::entityTypeManager()
      ->getFormObject('reliefweb_post_api_provider', 'default')
      ->setEntity($this->createDummyProvider());

    $form_state = new FormState();

    $form = \Drupal::service('form_builder')
      ->buildForm($form_object, $form_state);

    $form = $form_object->form($form, $form_state);
    $this->assertArrayHasKey('data-enhanced', $form['#attributes']);
    $this->assertArrayHasKey('data-with-autocomplete', $form['field_source']['#attributes']);
  }

  /**
   * @covers ::validateForm()
   */
  public function testValidateForm(): void {
    $provider = $this->createDummyProvider();

    $form_object = \Drupal::entityTypeManager()
      ->getFormObject('reliefweb_post_api_provider', 'default')
      ->setEntity($provider);

    $form_state = new FormState();

    $form = \Drupal::service('form_builder')
      ->buildForm($form_object, $form_state);

    // Missing resource.
    $form_state->clearErrors();
    $form_state->unsetValue(['resource', 0, 'value']);
    $form_object->validateForm($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('resource', $errors);
    $this->assertEquals('This value should not be null.', (string) $errors['resource']);

    // Invalid resource.
    $form_state->clearErrors();
    $form_state->setValue(['resource', 0, 'value'], 'test');
    $form_object->validateForm($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('resource', $errors);
    $this->assertEquals('The value you selected is not a valid choice.', (string) $errors['resource']);

    // Missing resource status.
    $form_state->clearErrors();
    $form_state->setValue(['resource', 0, 'value'], 'reports');
    $form_object->validateForm($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('field_resource_status', $errors);
    $this->assertEquals('This value should not be null.', (string) $errors['field_resource_status']);

    // Invalid resource status.
    $form_state->clearErrors();
    $form_state->setValue(['field_resource_status', 0, 'value'], 'test');
    $form_object->validateForm($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('field_resource_status', $errors);
    $this->assertEquals('The value you selected is not a valid choice.', (string) $errors['field_resource_status']);

    // Valid resouce and resource status.
    $form_state->clearErrors();
    $form_state->setValue(['resource', 0, 'value'], 'reports');
    $form_state->setValue(['field_resource_status', 0, 'value'], 'pending');
    $form_object->validateForm($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayNotHasKey('resource', $errors);
    $this->assertArrayNotHasKey('field_resource_status', $errors);
  }

  /**
   * @covers ::validateForm()
   */
  public function testValidateFormUnallowedStatus(): void {
    $provider = $this->createDummyProvider();

    $form_object = \Drupal::entityTypeManager()
      ->getFormObject('reliefweb_post_api_provider', 'default')
      ->setEntity($provider);

    // Skip errors due to unallowed values for the resource and resource status
    // fields so we can test unallowed statuses.
    $form_state = new class() extends FormState {

      /**
       * {@inheritdoc}
       */
      public function getErrors() {
        $errors = $this->errors;
        foreach ($errors as $key => $error) {
          if ($key === 'resource' || $key === 'field_resource_status') {
            if ((string) $error === 'The value you selected is not a valid choice.') {
              unset($errors[$key]);
            }
          }
        }
        return $errors;
      }

    };

    $form = \Drupal::service('form_builder')
      ->buildForm($form_object, $form_state);

    // Test invalid choice of status.
    $form_state->clearErrors();
    $form_state->setValue(['resource', 0, 'value'], 'reports');
    $form_state->setValue(['field_resource_status', 0, 'value'], 'test');
    $form_object->validateForm($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayNotHasKey('resource', $errors);
    $this->assertArrayHasKey('field_resource_status', $errors);
    $this->assertStringContainsString('is not supported for this resource, please select one of', (string) $errors['field_resource_status']);
  }

  /**
   * @covers ::save()
   */
  public function testSave(): void {
    $form_object = \Drupal::entityTypeManager()
      ->getFormObject('reliefweb_post_api_provider', 'default')
      ->setEntity($this->createDummyProvider());

    $form_state = new FormState();

    $form = \Drupal::service('form_builder')
      ->buildForm($form_object, $form_state);

    $form_object->save($form, $form_state);
    $this->assertSame('entity.reliefweb_post_api_provider.collection', $form_state->getRedirect()->getRouteName());
  }

  /**
   * Create a dummy provider.
   *
   * @return \Drupal\reliefweb_post_api\Entity\ProviderInterface
   *   Provider.
   */
  protected function createDummyProvider(): ?ProviderInterface {
    $entity_type = $bundle = 'reliefweb_post_api_provider';

    return new class([], $entity_type, $bundle) extends Provider {

      /**
       * {@inheritdoc}
       */
      public function save() {
        return NULL;
      }

    };
  }

}
