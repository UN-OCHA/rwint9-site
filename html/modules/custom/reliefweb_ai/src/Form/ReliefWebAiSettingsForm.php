<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_utility\Helpers\LanguageDetectionHelper;

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

    // Language Detection settings section.
    $form['language_detection'] = [
      '#type' => 'details',
      '#title' => $this->t('Language Detection Settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['language_detection']['min_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum characters'),
      '#description' => $this->t('Minimum characters required for attachment or body samples before ELD runs on that stage.'),
      '#default_value' => $config->get('language_detection.min_chars') ?? 200,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['language_detection']['min_line_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum line characters'),
      '#description' => $this->t('Minimum characters for a PDF or body line to keep when building the detection sample. Short map labels and legends are dropped.'),
      '#default_value' => $config->get('language_detection.min_line_chars') ?? 40,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['language_detection']['max_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum pages per attachment'),
      '#description' => $this->t('Maximum number of pages to extract from each attachment for language detection.'),
      '#default_value' => $config->get('language_detection.max_pages') ?? 3,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['language_detection']['reliable_ratio'] = [
      '#type' => 'number',
      '#title' => $this->t('Strict reliability ratio'),
      '#description' => $this->t('ELD average-score ratio for the first (strict) cascade pass (0–1). Lower is less strict. Default 0.6.'),
      '#default_value' => $config->get('language_detection.reliable_ratio') ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#required' => TRUE,
    ];

    $form['language_detection']['reliable_ratio_soft'] = [
      '#type' => 'number',
      '#title' => $this->t('Soft reliability ratio'),
      '#description' => $this->t('ELD average-score ratio for the second (soft) cascade pass when no stage passes the strict ratio. Default 0.4. If soft also fails, score tie-break and title-lenient passes run before falling back to Other.'),
      '#default_value' => $config->get('language_detection.reliable_ratio_soft') ?? 0.4,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#required' => TRUE,
    ];

    $form['language_detection']['title_min_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Title minimum characters'),
      '#description' => $this->t('Minimum characters for the title stage on strict, soft, and score passes. The final title-lenient fallback ignores this threshold. Default 40.'),
      '#default_value' => $config->get('language_detection.title_min_chars') ?? 40,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['language_detection']['score_margin_min'] = [
      '#type' => 'number',
      '#title' => $this->t('Score margin minimum'),
      '#description' => $this->t('Minimum (top − second) / top ELD score margin for the cross-stage score tie-break (0–1). Default 0.10.'),
      '#default_value' => $config->get('language_detection.score_margin_min') ?? 0.10,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $eld_size_labels = [
      'small' => $this->t('Small'),
      'medium' => $this->t('Medium'),
      'large' => $this->t('Large'),
      'extralarge' => $this->t('Extra large'),
    ];
    $form['language_detection']['eld_database'] = [
      '#type' => 'select',
      '#title' => $this->t('ELD ngram database size'),
      '#description' => $this->t('Larger databases improve accuracy (e.g. place names in titles) but use more memory. Medium is the default. Large and extralarge need substantially more RAM.'),
      '#default_value' => $config->get('language_detection.eld_database') ?? LanguageDetectionHelper::DEFAULT_DATABASE,
      '#options' => array_intersect_key(
        $eld_size_labels,
        array_flip(LanguageDetectionHelper::databaseSizes()),
      ),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $min_chars = $form_state->getValue(['language_detection', 'min_chars']);
    if ($min_chars < 0) {
      $form_state->setErrorByName('language_detection][min_chars',
        $this->t('Minimum characters must be 0 or greater.'));
    }

    $min_line_chars = $form_state->getValue(['language_detection', 'min_line_chars']);
    if ($min_line_chars < 1) {
      $form_state->setErrorByName('language_detection][min_line_chars',
        $this->t('Minimum line characters must be at least 1.'));
    }

    $max_pages = $form_state->getValue(['language_detection', 'max_pages']);
    if ($max_pages < 1) {
      $form_state->setErrorByName('language_detection][max_pages',
        $this->t('Maximum pages must be at least 1.'));
    }

    $reliable_ratio = (float) $form_state->getValue(['language_detection', 'reliable_ratio']);
    if ($reliable_ratio < 0.0 || $reliable_ratio > 1.0) {
      $form_state->setErrorByName('language_detection][reliable_ratio',
        $this->t('Strict reliability ratio must be between 0 and 1.'));
    }

    $reliable_ratio_soft = (float) $form_state->getValue(['language_detection', 'reliable_ratio_soft']);
    if ($reliable_ratio_soft < 0.0 || $reliable_ratio_soft > 1.0) {
      $form_state->setErrorByName('language_detection][reliable_ratio_soft',
        $this->t('Soft reliability ratio must be between 0 and 1.'));
    }
    elseif ($reliable_ratio_soft > $reliable_ratio) {
      $form_state->setErrorByName('language_detection][reliable_ratio_soft',
        $this->t('Soft reliability ratio must be less than or equal to the strict reliability ratio.'));
    }

    $title_min_chars = $form_state->getValue(['language_detection', 'title_min_chars']);
    if ($title_min_chars < 1) {
      $form_state->setErrorByName('language_detection][title_min_chars',
        $this->t('Title minimum characters must be at least 1.'));
    }

    $score_margin_min = (float) $form_state->getValue(['language_detection', 'score_margin_min']);
    if ($score_margin_min < 0.0 || $score_margin_min > 1.0) {
      $form_state->setErrorByName('language_detection][score_margin_min',
        $this->t('Score margin minimum must be between 0 and 1.'));
    }

    $eld_database = (string) $form_state->getValue(['language_detection', 'eld_database']);
    if (!in_array($eld_database, LanguageDetectionHelper::databaseSizes(), TRUE)) {
      $form_state->setErrorByName('language_detection][eld_database',
        $this->t('ELD database size must be small, medium, large, or extralarge.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reliefweb_ai.settings');

    // Replace the whole mapping so removed keys do not linger.
    $config->set('language_detection', [
      'min_chars' => (int) $form_state->getValue(['language_detection', 'min_chars']),
      'min_line_chars' => (int) $form_state->getValue(['language_detection', 'min_line_chars']),
      'max_pages' => (int) $form_state->getValue(['language_detection', 'max_pages']),
      'reliable_ratio' => (float) $form_state->getValue(['language_detection', 'reliable_ratio']),
      'reliable_ratio_soft' => (float) $form_state->getValue(['language_detection', 'reliable_ratio_soft']),
      'title_min_chars' => (int) $form_state->getValue(['language_detection', 'title_min_chars']),
      'score_margin_min' => (float) $form_state->getValue(['language_detection', 'score_margin_min']),
      'eld_database' => (string) $form_state->getValue(['language_detection', 'eld_database']),
    ]);

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
