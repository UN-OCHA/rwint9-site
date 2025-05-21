<?php

declare(strict_types=1);

namespace Drupal\reliefweb_users\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for submitter form settings.
 */
class SubmitterFormSettingsForm extends FormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_users_submitter_form_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get the current form settings from state.
    $settings = $this->state->get('reliefweb_users_submitter_form_settings', []);

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure settings for the submitter form.') . '</p>',
    ];

    $default_instructions = $form_state->getValue('instructions', $settings['instructions'] ?? []);
    $form['instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Main instructions'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['instructions']['header'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Header instructions'),
      '#description' => $this->t('Instructions displayed at the top of the form.'),
      '#default_value' => $default_instructions['header']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 5,
    ];

    $form['instructions']['footer'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Footer instructions'),
      '#description' => $this->t('Instructions displayed at the bottom of the form.'),
      '#default_value' => $default_instructions['footer']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 5,
    ];

    $default_fields = $form_state->getValue('fields', $settings['fields'] ?? []);
    $form['fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Field instructions'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['fields']['title'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Title'),
      '#description' => $this->t('Instructions for the title.'),
      '#default_value' => $default_fields['title']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['fields']['field_primary_country'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Primary country'),
      '#description' => $this->t('Instructions for the primary country field.'),
      '#default_value' => $default_fields['field_primary_country']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['fields']['field_source'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Source'),
      '#description' => $this->t('Instructions for the source/organization field.'),
      '#default_value' => $default_fields['field_source']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['fields']['field_language'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Language'),
      '#description' => $this->t('Instructions for the language field.'),
      '#default_value' => $default_fields['field_language']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['fields']['field_original_publication_date'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Original publication date'),
      '#description' => $this->t('Instructions for the original publication date field.'),
      '#default_value' => $default_fields['field_original_publication_date']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['fields']['field_file'] = [
      '#type' => 'text_format',
      '#title' => $this->t('File'),
      '#description' => $this->t('Instructions for the file field.'),
      '#default_value' => $default_fields['field_file']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $default_buttons = $form_state->getValue('buttons', $settings['buttons'] ?? []);
    $form['buttons'] = [
      '#type' => 'details',
      '#title' => $this->t('Save button messages'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['buttons']['create'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Creating a document'),
      '#description' => $this->t('Instructions for the save buttons when creating a new document.'),
      '#default_value' => $default_buttons['create']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['buttons']['update'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Updating a document'),
      '#description' => $this->t('Instructions for the save buttons when updating a document.'),
      '#default_value' => $default_buttons['update']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $default_errors = $form_state->getValue('errors', $settings['errors'] ?? []);
    $form['errors'] = [
      '#type' => 'details',
      '#title' => $this->t('Error messages'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['errors']['file_too_large'] = [
      '#type' => 'text_format',
      '#title' => $this->t('File too large'),
      '#description' => $this->t('Error message when the file is too large. Available placeholders: %filename, %filesize and %maxsize.'),
      '#default_value' => $default_errors['file_too_large']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['errors']['file_duplicate'] = [
      '#type' => 'text_format',
      '#title' => $this->t('File duplicate'),
      '#description' => $this->t('Error message when another document with the same file is found when validating the upload. Available placeholders: @link (link to the existing document with the same file).'),
      '#default_value' => $default_errors['file_duplicate']['value'] ?? '',
      '#format' => 'markdown_editor',
      '#allowed_formats' => ['markdown_editor'],
      '#after_build' => [[$this, 'hideTextFormat']],
      '#rows' => 3,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Hide the text format help since we only allow the rich editor.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element.
   */
  public function hideTextFormat(array $element, FormStateInterface $form_state): array {
    if (isset($element['format'])) {
      unset($element['format']['#theme_wrappers']);
      if (isset($element['format']['help'])) {
        $element['format']['help']['#access'] = FALSE;
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = [
      'instructions' => $form_state->getValue('instructions', []),
      'fields' => $form_state->getValue('fields', []),
      'buttons' => $form_state->getValue('buttons', []),
      'errors' => $form_state->getValue('errors', []),
    ];

    // Save the settings.
    $this->state->set('reliefweb_users_submitter_form_settings', $settings);

    $this->messenger()->addStatus($this->t('The submitter form settings have been saved.'));
  }

}
