<?php

namespace Drupal\reliefweb_files\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'reliefweb_file_simplified' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_file_simplified",
 *   label = @Translation("ReliefWeb File Simplified"),
 *   field_types = {
 *     "reliefweb_file"
 *   }
 * )
 */
class ReliefWebFileSimplified extends ReliefWebFile {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    // Add a setting with a list of extra information to retrieve from
    // the fields of the referenced entity bundles.
    return [
      'extensions' => 'pdf',
      'max_file_size' => 25 * 1024 * 1024,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = [];

    $dummy_item = $this->createFieldItem();

    $default_extensions = $dummy_item->getAllowedFileExtensions();
    $extensions = $this->getExtensionsSetting() ?: $default_extensions;
    $element['extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extensions'),
      '#description' => $this->t('Comma separated list of extensions among: @extensions', [
        '@extensions' => implode(', ', $default_extensions ?: [$this->t('any')]),
      ]),
      '#default_value' => $form_state->getValue('extensions', implode(', ', $extensions ?: [])),
      '#element_validate' => [[$this, 'validateExtensionsSetting']],
    ];

    $default_max_files_size = $dummy_item->getMaxFileSize();
    $max_file_size = $this->getMaxFileSizeSetting() ?: $default_max_files_size;
    $element['max_file_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Max file size'),
      '#description' => $this->t('Max file size, up to: @max_file_size', [
        '@max_file_size' => $default_max_files_size,
      ]),
      '#default_value' => $form_state->getValue('max_file_size', $max_file_size),
      '#min' => 1,
      '#max' => $default_max_files_size,
      '#element_validate' => [[$this, 'validateMaxFileSizeSetting']],
    ];

    return $element;
  }

  /**
   * Validate the extensions setting.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateExtensionsSetting(array $element, FormStateInterface $form_state) {
    $dummy_item = $this->createFieldItem();
    $default_extensions = $dummy_item->getAllowedFileExtensions();
    $extensions = preg_split('/[, ]+/', $form_state->getValue($element['#parents'], ''));
    if (!empty($extensions) && !empty($default_extensions) && count(array_diff($extensions, $default_extensions)) > 0) {
      $form_state->setError($element, $this->t('Only the following extensions are allowed: @extensions.', [
        '@extensions' => implode(', ', $default_extensions),
      ]));
    }
  }

  /**
   * Validate the max file size setting.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateMaxFileSizeSetting(array $element, FormStateInterface $form_state) {
    $dummy_item = $this->createFieldItem();
    $default_max_files_size = $dummy_item->getMaxFileSize();
    $max_file_size = $form_state->getValue($element['#parents']);
    if (empty($max_file_size) || $max_file_size < 0 || $max_file_size > $default_max_files_size) {
      $form_state->setError($element, $this->t('The max file size must be between @min and @max.', [
        '@min' => format_size(1),
        '@max' => format_size($default_max_files_size),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $summary[] = $this->t('Extensions: @extensions', [
      '@extensions' => implode(', ', $this->getExtensionsSetting() ?: [$this->t('any')]),
    ]);

    $summary[] = $this->t('Max file size: @max_file_size', [
      '@max_file_size' => format_size($this->getMaxFileSizeSetting()),
    ]);

    return $summary;
  }

  /**
   * Get the allowed extensions setting.
   *
   * @return ?array
   *   List of allowed extensions or NULL.
   */
  protected function getExtensionsSetting(): ?array {
    $extensions = trim($this->getSetting('extensions') ?: '');
    if (empty($extensions)) {
      return NULL;
    }
    return preg_split('/[, ]+/', $extensions);
  }

  /**
   * Get the max file size setting.
   *
   * @return int
   *   Max file size.
   */
  protected function getMaxFileSizeSetting(): int {
    return $this->getSetting('max_file_size');
  }

  /**
   * {@inheritdoc}
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $elements = parent::formMultipleElements($items, $form, $form_state);
    $elements['add_more']['#not_required'] = FALSE;

    $dummy_item = $this->createFieldItem();
    $extensions = $this->getExtensionsSetting();
    $max_file_size = $this->getMaxFileSizeSetting();

    // Update the description of the add more form element.
    if (isset($elements['add_more']['files']['#description'])) {
      unset($elements['add_more']['files']['#description']);
      $elements['add_more']['description'] = [
        '#type' => 'item',
        '#description' => $dummy_item->getUploadDescription(
          extensions: $extensions,
          max_file_size: $max_file_size,
        ),
      ];
    }

    if (!empty($extensions)) {
      $elements['add_more']['files']['#attributes']['accept'] = '.' . implode(',.', $extensions);
    }

    if (isset($elements['add_more']['files']['#upload_validators']['FileSizeLimit']['fileLimit'])) {
      $elements['add_more']['files']['#upload_validators']['FileSizeLimit']['fileLimit'] = $max_file_size;
    }

    $elements['#theme'] = 'reliefweb_file_widget__simplified';
    $elements['#attached']['library'][] = 'reliefweb_files/file.autoupload';
    return $elements;
  }

  /**
   * Handle uploaded files.
   *
   * This moves the uploaded files and create managed files and their
   * associated field items.
   *
   * @param array $element
   *   File upload button.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function uploadFiles(array $element, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $validators = $this->createFieldItem()->getUploadValidators($entity, TRUE);

    if (!empty($element['#upload_validators']['ReliefWebFileHash']['duplicateFileFormError'])) {
      $validators['ReliefWebFileHash']['duplicateFileFormError'] = $element['#upload_validators']['ReliefWebFileHash']['duplicateFileFormError'];
    }

    $extensions = $this->getExtensionsSetting();
    if (!empty($extensions)) {
      $validators['FileExtension'] = ['extensions' => implode(' ', $extensions)];
    }
    $max_file_size = $this->getMaxFileSizeSetting();
    if (!empty($max_file_size)) {
      $validators['FileSizeLimit'] = ['fileLimit' => $max_file_size];
    }

    return $this->processUploadedFiles($element, $form_state, $element['#name'], $validators);
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['_new_file_name']['#access'] = FALSE;
    $element['description']['#access'] = FALSE;
    $element['language']['#access'] = FALSE;
    $element['preview']['#access'] = FALSE;
    $element['preview_page']['#access'] = FALSE;
    $element['preview_rotation']['#access'] = FALSE;
    $element['operations']['#title'] = $this->t('Delete or Replace');
    $element['#theme'] = 'reliefweb_file_widget_item__simplified';
    return $element;
  }

}
