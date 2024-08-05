<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the form for a ReliefWeb POST API provider entity.
 */
class GenerateApiKeyForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_post_api_generate_api_key';
  }

  public function __construct(EntityTypeManagerInterface $entityTypeManager, UuidInterface $uuid_service) {
    $this->entityTypeManager = $entityTypeManager;
    $this->uuidService = $uuid_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('uuid'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user = NULL) {
    $form['uid'] = [
      '#type' => 'value',
      '#value' => $user,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate a (new) API key.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $account = $this->entityTypeManager->getStorage('user')->load($form_state->getValue('uid'));
    $api_key = $this->uuidService->generate();

    $account->set('field_api_key', $api_key);
    $account->save();

    $this->messenger()->addMessage($this->t('Your API key has been generated and saved, it will be only displayed here: @apikey', [
      '@apikey' => $api_key,
    ]));
  }

}
