<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Module settings form for ReliefWeb import.
 */
class ReliefWebImportSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reliefweb_import.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_import_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reliefweb_import.settings');

    $form['user_agents'] = [
      '#type' => 'textarea',
      '#title' => $this->t('User agents for remote file downloads'),
      '#description' => $this->t('One User-Agent string per line, tried in order until a file download returns HTTP 200. Leave empty if not configured (downloads will fail until set).'),
      '#default_value' => $config->get('user_agents') ?? '',
      '#rows' => 8,
    ];

    $form['throttling'] = [
      '#type' => 'details',
      '#title' => $this->t('Remote file download throttling'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['throttling']['download_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Download delay (seconds)'),
      '#description' => $this->t('Minimum seconds between remote file download starts.'),
      '#default_value' => $config->get('download_delay') ?? 8,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['throttling']['download_delay_jitter'] = [
      '#type' => 'number',
      '#title' => $this->t('Download delay jitter (seconds)'),
      '#description' => $this->t('Extra random seconds (0 to this value) added to the download delay.'),
      '#default_value' => $config->get('download_delay_jitter') ?? 4,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['throttling']['user_agent_retry_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('User-agent retry delay (seconds)'),
      '#description' => $this->t('Seconds to wait before trying the next user agent after HTTP 403.'),
      '#default_value' => $config->get('user_agent_retry_delay') ?? 15,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['throttling']['stream_fallback_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Stream fallback delay (seconds)'),
      '#description' => $this->t('Seconds to wait before retrying without streaming after a transfer failure.'),
      '#default_value' => $config->get('stream_fallback_delay') ?? 2,
      '#min' => 0,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $throttling = $form_state->getValue('throttling') ?? [];
    $this->config('reliefweb_import.settings')
      ->set('user_agents', (string) $form_state->getValue('user_agents'))
      ->set('download_delay', (int) ($throttling['download_delay'] ?? 8))
      ->set('download_delay_jitter', (int) ($throttling['download_delay_jitter'] ?? 4))
      ->set('user_agent_retry_delay', (int) ($throttling['user_agent_retry_delay'] ?? 15))
      ->set('stream_fallback_delay', (int) ($throttling['stream_fallback_delay'] ?? 2))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
