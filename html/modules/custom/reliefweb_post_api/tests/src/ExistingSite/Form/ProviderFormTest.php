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
