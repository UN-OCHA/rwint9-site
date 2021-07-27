<?php

namespace Drupal\reliefweb_bookmarks\Form;

/**
 * @file
 * Contains Drupal\reliefweb_bookmarks\Form\BookmarksConfigurationForm.
 */


use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class SettingsForm.
 */
class BookmarksConfigurationForm extends ConfigFormBase {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'reliefweb_bookmarks.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_bookmarks_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->get('reliefweb_bookmarks.settings');

    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $content_types_list = [];
    foreach ($content_types as $content_type) {
      $content_types_list[$content_type->id()] = $content_type->label();
    }

    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    $vocabularies_list = [];
    foreach ($vocabularies as $vocabulary) {
      $vocabularies_list[$vocabulary->id()] = $vocabulary->label();
    }

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#options' => $content_types_list,
      '#default_value' => $config->get('content_types') ? $config->get('content_types') : [],
      '#title' => $this->t('Please choose any content type which you want for bookmarks.'),
    ];

    $form['vocabularies'] = [
      '#type' => 'checkboxes',
      '#options' => $vocabularies_list,
      '#default_value' => $config->get('vocabularies') ? $config->get('vocabularies') : [],
      '#title' => $this->t('Please choose any vocabulary which you want for bookmarks.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('reliefweb_bookmarks.settings')
      ->set('content_types', $form_state->getValue('content_types'))
      ->set('vocabularies', $form_state->getValue('vocabularies'))
      ->save();
  }

}
