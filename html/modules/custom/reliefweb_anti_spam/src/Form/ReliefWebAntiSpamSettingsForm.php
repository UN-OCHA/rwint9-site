<?php

declare(strict_types=1);

namespace Drupal\reliefweb_anti_spam\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for ReliefWeb Anti-Spam settings.
 */
class ReliefWebAntiSpamSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reliefweb_anti_spam.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_anti_spam_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reliefweb_anti_spam.settings');

    // Unverified User Post Limit.
    $form['post_limit'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Unverified User Post Limit'),
      '#tree' => TRUE,
    ];

    $form['post_limit']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable post limit for unverified users'),
      '#default_value' => $config->get('post_limit.enabled') ?? FALSE,
    ];

    $form['post_limit']['number'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of posts'),
      '#min' => 1,
      '#default_value' => $config->get('post_limit.number') ?? 1,
      '#states' => [
        'visible' => [
          ':input[name="post_limit[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['post_limit']['frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Frequency'),
      '#options' => [
        'ever' => $this->t('Ever (until verified)'),
        'hour' => $this->t('Per hour'),
        'day' => $this->t('Per day'),
        'week' => $this->t('Per week'),
        'month' => $this->t('Per month'),
        'year' => $this->t('Per year'),
      ],
      '#default_value' => $config->get('post_limit.frequency') ?? 'day',
      '#states' => [
        'visible' => [
          ':input[name="post_limit[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Validation Settings.
    $form['validation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Validation Settings'),
      '#tree' => TRUE,
    ];

    $form['validation']['blacklisted_domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blacklisted domains'),
      '#description' => $this->t('Enter one domain per line. Posts containing these domains in title, body, or "How to Apply" field will be rejected.'),
      '#default_value' => $config->get('validation.blacklisted_domains') ?? '',
    ];

    $form['validation']['title_min_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum number of words in title'),
      '#min' => 1,
      '#default_value' => $config->get('validation.title_min_words') ?? 3,
    ];

    $form['validation']['title_min_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum length of title'),
      '#min' => 1,
      '#default_value' => $config->get('validation.title_min_length') ?? 20,
    ];

    $form['validation']['body_min_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum number of words in body'),
      '#min' => 1,
      '#default_value' => $config->get('validation.body_min_words') ?? 50,
    ];

    $form['validation']['body_min_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum length of body'),
      '#min' => 1,
      '#default_value' => $config->get('validation.body_min_length') ?? 200,
    ];

    // Tiered Error Messages.
    $form['error_messages'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Error Messages'),
      '#tree' => TRUE,
    ];

    $form['error_messages']['content_quality'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Content Quality Error'),
      '#default_value' => $config->get('error_messages.content_quality') ?? 'The content of your submission does not meet our quality standards. Please review and revise.',
      '#description' => $this->t('This message will be shown for issues related to content quality (e.g., minimum length, word count, blacklisted domains).'),
    ];

    $form['error_messages']['submission_frequency'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Submission Frequency Error'),
      '#default_value' => $config->get('error_messages.submission_frequency') ?? 'You have reached the maximum number of submissions allowed at this time. Please try again later.',
      '#description' => $this->t('This message will be shown when a user exceeds the allowed submission frequency.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reliefweb_anti_spam.settings');

    // Save post limit settings.
    $config->set('post_limit.enabled', $form_state->getValue(['post_limit', 'enabled']));
    $config->set('post_limit.number', $form_state->getValue(['post_limit', 'number']));
    $config->set('post_limit.frequency', $form_state->getValue(['post_limit', 'frequency']));

    // Save validation settings.
    $config->set('validation.blacklisted_domains', $form_state->getValue(['validation', 'blacklisted_domains']));
    $config->set('validation.title_min_words', $form_state->getValue(['validation', 'title_min_words']));
    $config->set('validation.title_min_length', $form_state->getValue(['validation', 'title_min_length']));
    $config->set('validation.body_min_words', $form_state->getValue(['validation', 'body_min_words']));
    $config->set('validation.body_min_length', $form_state->getValue(['validation', 'body_min_length']));

    // Save tiered error messages.
    $config->set('error_messages.content_quality', $form_state->getValue(['error_messages', 'content_quality']));
    $config->set('error_messages.submission_frequency', $form_state->getValue(['error_messages', 'submission_frequency']));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
