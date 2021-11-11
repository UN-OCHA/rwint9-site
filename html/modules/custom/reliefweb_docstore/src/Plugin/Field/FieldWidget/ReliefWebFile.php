<?php

namespace Drupal\reliefweb_docstore\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile as ReliefWebFileType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Plugin implementation of the 'reliefweb_file' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_file",
 *   label = @Translation("ReliefWeb File"),
 *   field_types = {
 *     "reliefweb_file"
 *   }
 * )
 */
class ReliefWebFile extends WidgetBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Ajax wrapper ID.
   *
   * @var string
   */
  protected $ajaxWrapperId;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    RequestStack $request_stack
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function handlesMultipleValues() {
    return FALSE;
  }

  /**
   * Overrides \Drupal\Core\Field\WidgetBase::formMultipleElements().
   *
   * Special handling for draggable multiple widgets and 'add more' button.
   *
   * @todo No changes, can be inherited from FileWidget?
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $required = $this->fieldDefinition->isRequired();
    $parents = $form['#parents'];

    // Load the items for form rebuilds from the field state as they might not
    // be in $form_state->getValues() because of validation limitations. Also,
    // they are only passed in as $items when editing existing entities.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    if (isset($field_state['items'])) {
      $items->setValue(array_values($field_state['items']));
    }

    // Container.
    $elements = [
      '#theme' => 'reliefweb_file_widget',
      '#title' => $this->fieldDefinition->getLabel(),
      '#description' => $this->getFilteredDescription(),
      '#tree' => TRUE,
    ];

    // Add an element for every existing item.
    $delta = 0;
    foreach ($items as $item) {
      if (!$item->isEmpty()) {
        $element = $this->formSingleElement($items, $delta, [], $form, $form_state);
        if (!empty($element)) {
          $elements[$delta++] = $element;
        }
      }
    }

    // Add one more empty row for new uploads except when this is a programmed
    // multiple form as it is not necessary.
    if (!$form_state->isProgrammed()) {
      $name = implode('_', array_merge($parents, [$field_name, 'files']));
      $elements['add_more'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Add file(s)'),
        'files' => [
          '#type' => 'file',
          '#name' => $name,
          '#multiple' => TRUE,
        ],
        'upload' => [
          '#type' => 'submit',
          '#value' => $this->t('Upload file(s)'),
          '#submit' => [[static::class, 'uploadFiles']],
          // Disable validation of the button.
          '#limit_validation_errors' => [
            array_merge($parents, ['add_more']),
          ],
          '#ajax' => $this->getAjaxSettings($this->t('Uploading file(s)...')),
        ],
        '#required' => $required && $delta == 0,
      ];
    }

    // Add the ajax wrapper.
    $elements['#prefix'] = '<div id="' . $this->getAjaxWrapperId() . '">';
    $elements['#suffix'] = '</div>';

    // Populate the 'array_parents' information in $form_state->get('field')
    // after the form is built, so that we catch changes in the form structure
    // performed in alter() hooks.
    $elements['#after_build'][] = [static::class, 'afterBuild'];
    $elements['#field_name'] = $field_name;
    $elements['#field_parents'] = $parents;

    return $elements;
  }

  /**
   * Get the ajax wrapper id for the field.
   *
   * @return string
   *   Wrapper ID.
   */
  protected function getAjaxWrapperId() {
    if (!isset($this->ajaxWrapperId)) {
      $this->ajaxWrapperId = Html::getUniqueId($this->fieldDefinition->getName() . '-ajax-wrapper');
    }
    return $this->ajaxWrapperId;
  }

  /**
   * Get the base ajax settings for the operation in the widget.
   *
   * @return array
   *   Array with the ajax settings.
   */
  protected function getAjaxSettings($message = '') {
    return [
      'callback' => [static::class, 'rebuildWidgetForm'],
      'wrapper' => $this->getAjaxWrapperId(),
      'effect' => 'fade',
      'progress' => [
        'type' => 'throbber',
        'message' => $message,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $form['#parents'];
    $item = $items[$delta];

    $element['#theme'] = 'reliefweb_file_widget_item';

    // Non editable defaults.
    $defaults = [
      'uuid',
      'revision_id',
      'status',
      'file_name',
      'file_mime',
      'file_size',
      'page_count',
      'preview_uuid',
    ];
    foreach ($defaults as $default) {
      $element[$default] = [
        '#type' => 'hidden',
        '#value' => $item->get($default)->getValue(),
      ];
    }

    // Link to the file.
    $file_name = $item->get('file_name')->getValue();
    $file_size = $item->get('file_size')->getValue();
    $file_extension = ReliefWebFileType::getFileExtension($file_name);
    $file_label = $file_name . ' (' . mb_strtoupper($file_extension) . ' | ' . format_size($file_size) . ')';
    $file_url = $item->getFileUrl();

    if (!empty($file_url)) {
      $element['link'] = [
        '#type' => 'link',
        '#title' => $file_label,
        '#url' => $file_url,
      ];
    }
    else {
      $element['link'] = [
        '#markup' => $file_label,
      ];
    }

    $element['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#maxlength' => 255,
      '#default_value' => $item->get('description')->getValue() ?? '',
    ];

    // @todo move that to a settings on the field.
    $languages = [
      'en' => $this->t('English version'),
      'fr' => $this->t('French version'),
    ];

    $element['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language version'),
      '#options' => $languages,
      '#default_value' => $item->get('language')->getValue() ?? NULL,
    ];

    // Display the preview and the page and rotation selection.
    if ($item->canHavePreview()) {
      $preview_page = $item->get('preview_page')->getValue() ?? 1;
      $preview_rotation = $item->get('preview_rotation')->getValue() ?? 0;

      $original_preview_page = $item->_original_preview_page ?: $preview_page;
      $original_preview_rotation = $item->_original_preview_rotation ?: $preview_rotation;

      if (!empty($preview_page)) {
        $regenerate = $original_preview_page != $preview_page || $original_preview_rotation != $preview_rotation;

        // Ensure the preview is generated.
        if ($item->generatePreview($preview_page, $preview_rotation, $regenerate) !== NULL) {
          $element['preview'] = $item->renderPreview('thumbnail');

          // Add state to hide the preview if "no preview" is selected.
          if (!empty($element['preview'])) {
            $element['preview']['#type'] = 'item';
            // Prevent the caching of the derivative image.
            // @see reliefweb_docstore_preprocess_image_style()
            $element['preview']['#attributes']['data-no-cache'] = TRUE;
            $element['preview']['#states']['invisible'] = [
              ':input[name="' . $field_name . '[' . $delta . '][preview_page]"]' => ['value' => 0],
            ];
          }
        }
      }

      $pages = range(1, $item->getPageCount());
      $element['preview_page'] = [
        '#type' => 'select',
        '#title' => $this->t('Preview page'),
        '#options' => array_merge([0 => $this->t('No preview')], $pages),
        '#default_value' => $preview_page,
        '#ajax' => $this->getAjaxSettings($this->t('Regenerating preview...')),
      ];

      $element['preview_rotation'] = [
        '#type' => 'select',
        '#title' => $this->t('Preview page rotation'),
        '#options' => [
          0 => $this->t('none'),
          90 => $this->t('clockwise'),
          -90 => $this->t('counterclockwise'),
        ],
        '#default_value' => $preview_rotation,
        '#ajax' => $this->getAjaxSettings($this->t('Regenerating preview...')),
      ];

      // Store the original preview page and rotation so that we can compare
      // with the new values and determine if the preview should be regenerated.
      $element['_original_preview_page'] = [
        '#type' => 'hidden',
        '#value' => $preview_page,
      ];
      $element['_original_preview_rotation'] = [
        '#type' => 'hidden',
        '#value' => $preview_rotation,
      ];
    }

    // Add a button to delete the file.
    $element['_delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete'),
      '#name' => implode('-', array_merge($parents, [$delta, 'delete'])),
      '#submit' => [[static::class, 'deleteFile']],
      // Store the delta so we know which file to mark as deleted.
      '#file_delta' => $delta,
      // Disable validation of the button.
      '#limit_validation_errors' => [
        array_merge($parents, [$delta]),
      ],
      '#ajax' => $this->getAjaxSettings($this->t('Removing file...')),
    ];

    // Add the input field for the delta (drag-n-drop reordering).
    $element['_weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight for row @number', [
        '@number' => $delta + 1,
      ]),
      '#title_display' => 'invisible',
      // Note: this 'delta' is the FAPI #type 'weight' element's property.
      '#delta' => count($items),
      '#default_value' => $item->_weight ?: $delta,
      '#weight' => 100,
    ];

    // Add properties needed by the value() method.
    $element['#field_name'] = $this->fieldDefinition->getName();
    $element['#entity_type'] = $items->getEntity()->getEntityTypeId();

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      unset($value['_delete']);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $form['#parents'];
    $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);

    // Extract the values from $form_state->getValues().
    $path = array_merge($parents, [$field_name]);
    $key_exists = FALSE;
    $values = NestedArray::getValue($form_state->getValues(), $path, $key_exists);
    if ($key_exists) {
      // Remove the 'value' of the 'add more' button.
      unset($values['add_more']);

      // The original delta, before drag-and-drop reordering, is needed to
      // route errors to the correct form element.
      foreach ($values as $delta => &$value) {
        $value['_original_delta'] = $delta;
      }
      usort($values, function ($a, $b) {
        return SortArray::sortByKeyInt($a, $b, '_weight');
      });

      // Let the widget massage the submitted values.
      $values = $this->massageFormValues($values, $form, $form_state);

      // Assign the values and remove the empty ones.
      $items->setValue($values);
      $items->filterEmptyItems();

      // Put delta mapping in $form_state, so that flagErrors() can use it.
      // Also, store the original preview page and rotation of each item so we
      // can determine if their preview needs to be regenerated when the form
      // is rebuilt.
      foreach ($items as $delta => $item) {
        $field_state['original_deltas'][$delta] = $item->_original_delta ?: $delta;

        // We'll let the widget repopulate the weight with the new deltas.
        unset($item->_original_delta, $item->_weight);
      }
    }

    // Update the field state items with the new data.
    $field_state['items'] = $items->getValue();
    static::setWidgetState($form['#parents'], $field_name, $form_state, $field_state);
  }

  /**
   * Upload new files.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function uploadFiles(array &$form, FormStateInterface &$form_state) {
    $button = $form_state->getTriggeringElement();
    $widget = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
    $parents = $form['#parents'];

    // Retrieve the current field state with the list of existing, queued and
    // deleted files.
    $field_name = $widget['#field_name'];
    $field_parents = $widget['#field_parents'];
    $field_state = static::getWidgetState($field_parents, $field_name, $form_state);

    // Get uploaded files.
    $name = implode('_', array_merge($parents, [$field_name, 'files']));
    $files = \Drupal::request()->files->get($name, []);

    if (!empty($files)) {
      foreach ($files as $file) {
        try {
          $field_state['items'][] = static::createNewFileFieldItem($file);
        }
        catch (\Exception $exception) {
          $form_state->setError($widget, $exception->getMessage());
        }
      }
    }

    // Update the widget state with the existing and new files.
    static::setWidgetState($field_parents, $field_name, $form_state, $field_state);
    $form_state->setRebuild();
  }

  /**
   * Delete a file.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function deleteFile(array &$form, FormStateInterface &$form_state) {
    $button = $form_state->getTriggeringElement();
    $widget = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
    $delta = $button['#file_delta'];

    // Retrieve the current field state with the list of existing, queued and
    // deleted files.
    $field_name = $widget['#field_name'];
    $field_parents = $widget['#field_parents'];
    $field_state = static::getWidgetState($field_parents, $field_name, $form_state);

    if (isset($field_state['items'][$delta]['uuid'])) {
      $item = $field_state['items'][$delta];

      // If the file is new, we delete it completely.
      if (empty($item['revision_id'])) {
        // Load the associated managed file.
        $file = \Drupal::service('entity.repository')
          ->loadEntityByUuid('file', $item['uuid']);

        // Remove the managed file.
        if (!empty($file)) {
          $file->delete();
        }
      }

      // Remove the field item.
      unset($field_state['items'][$delta]);
    }

    // Re-index the items.
    $field_state['items'] = array_values($field_state['items']);

    // Update the widget state with the existing and new files.
    static::setWidgetState($field_parents, $field_name, $form_state, $field_state);
    $form_state->setRebuild();
  }

  /**
   * Create a new file field item.
   *
   * @param \SplFileInfo $file_info
   *   The uploaded file info.
   *
   * @return array
   *   The new field entry with its uuid, revision_id, file name and status.
   *
   * @throws \Exception
   *   Throw an exception if the temporary file couldn't be created.
   *
   * @todo upload to the docstore directly if/once we allow temporary
   * files to be uploaded. For now we cannot create a docstore file here
   * because if, for whatever reason, the form submission doesn't fully
   * finish, then we'll end up with a unused file in the docstore. So instead
   * we create a temporary managed file that can be garbage collected if the
   * the submission doesn't finish.
   */
  protected static function createNewFileFieldItem(\SplFileInfo $file_info) {
    $file_name = $file_info->getClientOriginalName();

    // @todo throw a more relevant information.
    if (!static::validateFile($file_info)) {
      throw new \Exception(strtr('Invalid file @name.', [
        '@name' => $file_name,
      ]));
    }

    // We generate a UUID before creating the file entity so that we can use
    // it for the file uri.
    $uuid = ReliefWebFileType::generateUuid();

    // Generate the file uri.
    $extension = ReliefWebFileType::getFileExtension($file_name);
    $uri = ReliefWebFileType::getFileUriFromUuid($uuid, $extension, TRUE, FALSE);
    $path = $file_info->getRealPath();

    // Create the private temp directory to store the file.
    if (!ReliefWebFileType::prepareDirectory($uri)) {
      throw new \Exception(strtr('Unable to create the destination directory for the uploaded file @name.', [
        '@name' => $file_name,
      ]));
    }

    // Move the uploaded file.
    if (!\Drupal::service('file_system')->moveUploadedFile($path, $uri)) {
      throw new \Exception(strtr('Unable to copy the uploadedf ile @name.', [
        '@name' => $file_name,
      ]));
    }

    // Try to guess the real mime type of the uploaded file.
    $file_mime = ReliefWebFileType::getFileMimeType($uri);

    // Create a temporary managed file associated with the uploaded file.
    $file = ReliefWebFileType::createFileFromUuid($uuid, $uri, $file_name, $file_mime);

    // Save the file. This will notably populate its file size.
    $file->save();

    // Create a new ReliefWeb item.
    return [
      'uuid' => $uuid,
      // A revision of 0 is an easy way to determine new files.
      // This will be populated after a successful upload to the
      // docstore.
      'revision_id' => 0,
      'status' => 0,
      'file_name' => $file_name,
      'file_mime' => $file_mime,
      'file_size' => $file->getSize(),
      'page_count' => ReliefWebFileType::getFilePageCount($file),
    ];
  }

  /**
   * Validate an uploaded file.
   *
   * @param \SplFileInfo $file_info
   *   Uploaded file.
   *
   * @return bool
   *   TRUE if the file is valid.
   *
   * @todo check extension and size and $file->getError().
   *
   * @see _file_save_upload_single()
   */
  protected static function validateFile(\SplFileInfo $file_info) {
    return $file_info->isValid() &&
           $file_info->getRealPath() !== FALSE &&
           // Max allowed length of a managed file name.
           // @todo check if there is a constant we can use here.
           mb_strlen($file_info->getClientOriginalName()) <= 255;
  }

  /**
   * Rebuild form.
   *
   * @param array $form
   *   The build form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response of the ajax upload.
   */
  public static function rebuildWidgetForm(array &$form, FormStateInterface &$form_state, Request $request) {
    $button = $form_state->getTriggeringElement();
    $widget = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));

    $response = new AjaxResponse();
    $response->setAttachments($form['#attached']);

    return $response->addCommand(new ReplaceCommand(NULL, $widget));
  }

}
