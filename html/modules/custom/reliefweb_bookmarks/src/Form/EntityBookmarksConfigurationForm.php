<?php

namespace Drupal\reliefweb_bookmarks\Form;

/**
 * @file
 * Contains Drupal\reliefweb_bookmarks\Form\EntityBookmarksConfigurationForm.
 */


use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class SettingsForm.
 */
class EntityBookmarksConfigurationForm extends ConfigFormBase {

  /**
   * Entity type manager.
   *
   *
   */
  protected $entity_type_manager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entity_type_manager = $entity_type_manager;
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

    \Drupal::entityTypeManager();
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
    $contentTypes = $this->entity_type_manager->getStorage('node_type')->loadMultiple();

    $contentTypesList = [];
    foreach ($contentTypes as $contentType) {
      $contentTypesList[$contentType->id()] = $contentType->label();
    }
    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#cache' => ['max-age' => 0],
      '#options' => $contentTypesList,
      '#default_value' => $config->get('content_types') ? $config->get('content_types') : [],
      '#title' => $this->t('Please choose any content type which you want for Bookmarks.'),
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
      ->save();
    drupal_flush_all_caches();
  }

}
