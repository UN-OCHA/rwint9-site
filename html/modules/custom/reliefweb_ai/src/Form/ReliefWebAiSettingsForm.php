<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for ReliefWeb AI settings.
 */
class ReliefWebAiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'reliefweb_ai.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_ai_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reliefweb_ai.settings');

    // OCHA AI Chat settings section.
    $form['ocha_ai_chat'] = [
      '#type' => 'details',
      '#title' => $this->t('OCHA AI Chat Settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['ocha_ai_chat']['allow_for_anonymous'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow anonymous user to access the chat'),
      '#description' => $this->t('Check this box to allow anonymous users to access the AI chat functionality.'),
      '#default_value' => $config->get('ocha_ai_chat.allow_for_anonymous') ?? FALSE,
    ];

    $form['ocha_ai_chat']['instructions_replace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace chat instructions'),
      '#description' => $this->t('If checked, replace the chat instructions when the chat is disabled (error, anonymous access etc.), otherwise append the extra instructions.'),
      '#default_value' => $config->get('ocha_ai_chat.instructions_replace') ?? FALSE,
    ];

    $form['ocha_ai_chat']['login_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Login or register instructions'),
      '#description' => $this->t('Instructions displayed to anonymous users for login or registration.'),
      '#default_value' => $config->get('ocha_ai_chat.login_instructions') ?? '',
      '#rows' => 4,
    ];

    // Language Detection settings section.
    $form['language_detection'] = [
      '#type' => 'details',
      '#title' => $this->t('Language Detection Settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['language_detection']['tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XML tag for text extracts'),
      '#description' => $this->t('XML tag in the LLM response from which to retrieve the text extracts.'),
      '#default_value' => $config->get('language_detection.tag') ?? '',
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['language_detection']['use_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use title for language detection'),
      '#description' => $this->t('Whether to also use the title for the language detection.'),
      '#default_value' => $config->get('language_detection.use_title') ?? FALSE,
    ];

    // Text Extract Fix settings section.
    $form['text_extract_fix'] = [
      '#type' => 'details',
      '#title' => $this->t('Text Extraction Fix Settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['text_extract_fix']['tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XML tag for extracted text'),
      '#description' => $this->t('XML tag in the LLM response from which to retrieve the extracted text.'),
      '#default_value' => $config->get('text_extract_fix.tag') ?? '',
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    // Line Matching subsection.
    $form['text_extract_fix']['line_matching'] = [
      '#type' => 'details',
      '#title' => $this->t('Line Matching Settings'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];

    $form['text_extract_fix']['line_matching']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('OCHA AI helper endpoint'),
      '#description' => $this->t('OCHA AI helper endpoint to match lines.'),
      '#default_value' => $config->get('text_extract_fix.line_matching.endpoint') ?? '',
      '#maxlength' => 2048,
      '#required' => TRUE,
    ];

    $form['text_extract_fix']['line_matching']['threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity threshold'),
      '#description' => $this->t('Similarity threshold for line matching.'),
      '#default_value' => $config->get('text_extract_fix.line_matching.threshold') ?? 0,
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
      '#required' => TRUE,
    ];

    // Inference subsection.
    $form['text_extract_fix']['inference'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Inference Settings'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];

    $form['text_extract_fix']['inference']['plugin_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OCHA AI completion plugin ID'),
      '#description' => $this->t('The plugin ID for the OCHA AI completion service.'),
      '#default_value' => $config->get('text_extract_fix.inference.plugin_id') ?? '',
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['text_extract_fix']['inference']['temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#description' => $this->t('Controls randomness in the AI response. Lower values make output more focused and deterministic.'),
      '#default_value' => $config->get('text_extract_fix.inference.temperature') ?? 0.7,
      '#min' => 0,
      '#max' => 2,
      '#step' => 0.1,
      '#required' => TRUE,
    ];

    $form['text_extract_fix']['inference']['top_p'] = [
      '#type' => 'number',
      '#title' => $this->t('Nucleus sampling (top_p)'),
      '#description' => $this->t('Controls diversity via nucleus sampling. Lower values focus on more probable tokens.'),
      '#default_value' => $config->get('text_extract_fix.inference.top_p') ?? 1.0,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['text_extract_fix']['inference']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tokens'),
      '#description' => $this->t('Maximum number of tokens to generate in the response.'),
      '#default_value' => $config->get('text_extract_fix.inference.max_tokens') ?? 1000,
      '#min' => 1,
      '#max' => 4096,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['text_extract_fix']['inference']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#description' => $this->t('The system prompt that defines the AI behavior and context.'),
      '#default_value' => $config->get('text_extract_fix.inference.system_prompt') ?? '',
      '#rows' => 6,
      '#required' => TRUE,
    ];

    $form['text_extract_fix']['inference']['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('User prompt template'),
      '#description' => $this->t('The prompt template used for text extraction tasks.'),
      '#default_value' => $config->get('text_extract_fix.inference.prompt') ?? '',
      '#rows' => 6,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate temperature range.
    $temperature = $form_state->getValue(['text_extract_fix', 'inference', 'temperature']);
    if ($temperature < 0 || $temperature > 2) {
      $form_state->setErrorByName('text_extract_fix][inference][temperature',
        $this->t('Temperature must be between 0 and 2.'));
    }

    // Validate top_p range.
    $top_p = $form_state->getValue(['text_extract_fix', 'inference', 'top_p']);
    if ($top_p < 0 || $top_p > 1) {
      $form_state->setErrorByName('text_extract_fix][inference][top_p',
        $this->t('Top_p must be between 0 and 1.'));
    }

    // Validate threshold range.
    $threshold = $form_state->getValue(['text_extract_fix', 'line_matching', 'threshold']);
    if ($threshold < 0 || $threshold > 100) {
      $form_state->setErrorByName('text_extract_fix][line_matching][threshold',
        $this->t('Threshold must be between 0 and 100.'));
    }

    // Validate max_tokens range.
    $max_tokens = $form_state->getValue(['text_extract_fix', 'inference', 'max_tokens']);
    if ($max_tokens < 1 || $max_tokens > 4096) {
      $form_state->setErrorByName('text_extract_fix][inference][max_tokens',
        $this->t('Max tokens must be between 1 and 4096.'));
    }

    // Validate XML tags contain only valid characters.
    $language_tag = $form_state->getValue(['language_detection', 'tag']);
    if (!empty($language_tag) && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $language_tag)) {
      $form_state->setErrorByName('language_detection][tag',
        $this->t('XML tag must contain only letters, numbers, underscores, and hyphens.'));
    }

    $extract_tag = $form_state->getValue(['text_extract_fix', 'tag']);
    if (!empty($extract_tag) && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $extract_tag)) {
      $form_state->setErrorByName('text_extract_fix][tag',
        $this->t('XML tag must contain only letters, numbers, underscores, and hyphens.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reliefweb_ai.settings');

    // Save OCHA AI Chat settings.
    $config->set('ocha_ai_chat.allow_for_anonymous',
      $form_state->getValue(['ocha_ai_chat', 'allow_for_anonymous']));
    $config->set('ocha_ai_chat.instructions_replace',
      $form_state->getValue(['ocha_ai_chat', 'instructions_replace']));
    $config->set('ocha_ai_chat.login_instructions',
      $form_state->getValue(['ocha_ai_chat', 'login_instructions']));

    // Save Language Detection settings.
    $config->set('language_detection.tag',
      $form_state->getValue(['language_detection', 'tag']));
    $config->set('language_detection.use_title',
      $form_state->getValue(['language_detection', 'use_title']));

    // Save Text Extract Fix settings.
    $config->set('text_extract_fix.tag',
      $form_state->getValue(['text_extract_fix', 'tag']));
    $config->set('text_extract_fix.line_matching.endpoint',
      $form_state->getValue(['text_extract_fix', 'line_matching', 'endpoint']));
    $config->set('text_extract_fix.line_matching.threshold',
      $form_state->getValue(['text_extract_fix', 'line_matching', 'threshold']));
    $config->set('text_extract_fix.inference.plugin_id',
      $form_state->getValue(['text_extract_fix', 'inference', 'plugin_id']));
    $config->set('text_extract_fix.inference.temperature',
      $form_state->getValue(['text_extract_fix', 'inference', 'temperature']));
    $config->set('text_extract_fix.inference.top_p',
      $form_state->getValue(['text_extract_fix', 'inference', 'top_p']));
    $config->set('text_extract_fix.inference.max_tokens',
      $form_state->getValue(['text_extract_fix', 'inference', 'max_tokens']));
    $config->set('text_extract_fix.inference.system_prompt',
      $form_state->getValue(['text_extract_fix', 'inference', 'system_prompt']));
    $config->set('text_extract_fix.inference.prompt',
      $form_state->getValue(['text_extract_fix', 'inference', 'prompt']));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
