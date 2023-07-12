<?php

namespace Drupal\reliefweb_openai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure OpenAI client settings for this site.
 */
class ApiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_openai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['reliefweb_openai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['token'] = [
      '#required' => TRUE,
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->config('reliefweb_openai.settings')->get('token'),
      '#description' => $this->t('The API key is required to interface with OpenAI services. Get your API key by signing up on the <a href="@link" target="_blank">OpenAI website</a>.', ['@link' => 'https://openai.com/api']),
    ];

    $form['api_org'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization name/ID'),
      '#default_value' => $this->config('reliefweb_openai.settings')->get('api_org'),
      '#description' => $this->t('The organization name or ID on your OpenAI account. This is required for some OpenAI services to work correctly.'),
    ];

    $form['aws_access_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Access key'),
      '#default_value' => $this->config('reliefweb_openai.settings')->get('aws_access_key'),
      '#description' => $this->t('AWS Access key'),
    ];

    $form['aws_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Secret key'),
      '#default_value' => $this->config('reliefweb_openai.settings')->get('aws_secret_key'),
      '#description' => $this->t('AWS Secret key'),
    ];

    $form['aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Region'),
      '#default_value' => $this->config('reliefweb_openai.settings')->get('aws_region'),
      '#description' => $this->t('AWS Region'),
    ];

    $form['aws_endpoint_theme_classifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Theme classifier endpoint'),
      '#default_value' => $this->config('reliefweb_openai.settings')->get('aws_endpoint_theme_classifier'),
      '#description' => $this->t('AWS Theme classifier endpoint'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('reliefweb_openai.settings')
      ->set('token', $form_state->getValue('token'))
      ->set('api_org', $form_state->getValue('api_org'))
      ->set('aws_access_key', $form_state->getValue('aws_access_key'))
      ->set('aws_secret_key', $form_state->getValue('aws_secret_key'))
      ->set('aws_region', $form_state->getValue('aws_region'))
      ->set('aws_endpoint_theme_classifier', $form_state->getValue('aws_endpoint_theme_classifier'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
