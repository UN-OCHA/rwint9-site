<?php

namespace Drupal\guidelines\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure example settings for this site.
 */
class GuidelineGlobalSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'guidelines.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'guidelines_global_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    $form['overwrite_description'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Overwrite description'),
      '#default_value' => $config->get('overwrite_description'),
    ];

    $form['use_json_loading'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Json to load guidelines'),
      '#default_value' => $config->get('use_json_loading'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('overwrite_description', $form_state->getValue('overwrite_description'))
      ->set('use_json_loading', $form_state->getValue('use_json_loading'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
