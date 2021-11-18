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
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
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
   * The file system service.
   *
   * @var Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

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
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    RendererInterface $renderer,
    RequestStack $request_stack
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('reliefweb_file_widget');
    $this->renderer = $renderer;
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
      $container->get('file_system'),
      $container->get('logger.factory'),
      $container->get('renderer'),
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
    $parents = $form['#parents'];
    $field_name = $this->fieldDefinition->getName();
    $field_parents = array_merge($parents, [$field_name]);
    $required = $this->fieldDefinition->isRequired();

    // Load the items for form rebuilds from the field state as they might not
    // be in $form_state->getValues() because of validation limitations. Also,
    // they are only passed in as $items when editing existing entities.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    if (isset($field_state['items'])) {
      $items->setValue(array_values($field_state['items']));
    }
    // Otherwise if $items is not empty, then store the original values so we
    // can provide restore functionalities.
    elseif ($items->count() > 0) {
      foreach ($items as $item) {
        if (!$item->isEmpty()) {
          $field_state['original_values'][$item->get('uuid')->getValue()] = $item->getValue();
        }
      }
      static::setWidgetState($parents, $field_name, $form_state, $field_state);
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
      // Dummy item to get the default upload description and allowed
      // file extensions.
      $dummy_item = $this->createFieldItem();

      // Wrapper to add more files.
      $elements['add_more'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Add file(s)'),
        // @todo review if that's the correct place to add the required.
        '#required' => $required && $delta == 0,
      ];

      // File upload widget.
      $elements['add_more']['files'] = [
        '#type' => 'file',
        '#name' => implode('-', array_merge($field_parents, ['files'])),
        '#multiple' => TRUE,
        '#description' => $dummy_item->getUploadDescription(),
      ];

      // Limit the type of files that can be uplaoded.
      $extensions = $dummy_item->getAllowedFileExtensions();
      if (!empty($extensions)) {
        $element['add_more']['files']['#attributes']['accept'] = '.' . implode(',.', $extensions);
      }

      // Upload button.
      $elements['add_more']['upload'] = [
        '#type' => 'submit',
        '#value' => $this->t('Upload file(s)'),
        '#name' => implode('-', array_merge($field_parents, ['upload'])),
        '#submit' => [[static::class, 'submit']],
        // Limit the validation to the uploaded files.
        '#limit_validation_errors' => [
          array_merge($field_parents, ['add_more', 'files']),
        ],
        '#ajax' => $this->getAjaxSettings($this->t('Uploading file(s)...'), $field_parents),
        // Store information to identify the button in ::extractFormValues().
        '#field_parents' => $field_parents,
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
   * @param string $message
   *   The message to display while the request is being performed.
   * @param array $field_parents
   *   The parents of the field.
   *
   * @return array
   *   Array with the ajax settings.
   */
  protected function getAjaxSettings($message, array $field_parents) {
    return [
      'callback' => [static::class, 'rebuildWidgetForm'],
      'options' => [
        'query' => [
          // This will be used in the ::rebuildWidgetForm() callback to
          // retrieve the widget.
          'field_parents' => implode('/', $field_parents),
        ],
      ],
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
    $field_parents = array_merge($form['#parents'], [$field_name]);
    $element_parents = array_merge($field_parents, [$delta]);

    $item = $items[$delta];

    $element['#type'] = 'form_element';
    $element['#theme'] = 'reliefweb_file_widget_item';

    // Non editable defaults.
    $defaults = [
      'uuid',
      'revision_id',
      'private',
      'file_uuid',
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
    $file_url = $item->getFileUrl(TRUE);
    if (!empty($file_url)) {
      $element['link'] = [
        '#type' => 'link',
        '#title' => $file_label,
        // We add a timestamp to prevent caching by the browser so that
        // it can display replaced files.
        '#url' => $file_url->setOption('query', ['time' => microtime(TRUE)]),
      ];
    }
    else {
      $element['link'] = [
        '#markup' => $file_label,
      ];
    }

    // Information fields.
    $element['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#maxlength' => 255,
      '#default_value' => $item->get('description')->getValue() ?? '',
    ];
    $element['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language version'),
      '#options' => reliefweb_docstore_get_languages(),
      '#default_value' => $item->get('language')->getValue() ?? NULL,
    ];

    // Display the preview and the page and rotation selection.
    if ($item->canHavePreview()) {
      $preview_page = $item->get('preview_page')->getValue() ?? 1;
      $preview_rotation = $item->get('preview_rotation')->getValue() ?? 0;

      $original_preview_page = $item->_original_preview_page ?: $preview_page;
      $original_preview_rotation = $item->_original_preview_rotation ?: $preview_rotation;

      if (!empty($preview_page)) {
        // Only regenerated the preview if the page or rotation changed.
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

      // Preview page and rotation information.
      $element['preview_page'] = [
        '#type' => 'select',
        '#title' => $this->t('Preview page'),
        '#options' => array_merge(
          [0 => $this->t('No preview')],
          range(1, $item->getPageCount())
        ),
        '#default_value' => $preview_page,
        '#ajax' => $this->getAjaxSettings($this->t('Regenerating preview...'), $field_parents),
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
        '#ajax' => $this->getAjaxSettings($this->t('Regenerating preview...'), $field_parents),
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

    // Wrapper for the delete and replace operations on the file.
    // We "hide" them inside an initially closed <details> to limit wrong
    // interactions.
    $element['operations'] = [
      '#type' => 'details',
      '#title' => $this->t('Edit'),
    ];

    // Add a button to delete the file.
    $element['operations']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete'),
      '#name' => implode('-', array_merge($element_parents, ['delete'])),
      '#submit' => [[static::class, 'submit']],
      // Limit the validation to the button to prevent trying to validate
      // the element being removed.
      '#limit_validation_errors' => [
        array_merge($element_parents, ['operations', 'delete']),
      ],
      '#ajax' => $this->getAjaxSettings($this->t('Removing file...'), $field_parents),
      // Store information to identify the button in ::extractFormValues().
      '#delta' => $delta,
      '#field_parents' => $field_parents,
    ];

    // Add a file widget to upload a replacement.
    $element['operations']['file'] = [
      '#type' => 'file',
      '#name' => implode('-', array_merge($element_parents, ['file'])),
      '#multiple' => FALSE,
    ];

    // Add a button to replace the file.
    $element['operations']['replace'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload replacement'),
      '#name' => implode('-', array_merge($element_parents, ['replace'])),
      '#submit' => [[static::class, 'submit']],
      // Limit the validation to the element.
      '#limit_validation_errors' => [
        $element_parents,
      ],
      '#ajax' => $this->getAjaxSettings($this->t('Uploading file...'), $field_parents),
      // Store information to identify the button in ::extractFormValues().
      '#delta' => $delta,
      '#field_parents' => $field_parents,
      '#description' => $item->getUploadDescription(),
    ];

    // Limit the type of files that can be uplaoded.
    $extensions = $item->getAllowedFileExtensions();
    if (!empty($extensions)) {
      $element['operations']['replace']['#attributes']['accept'] = '.' . implode(',.', $extensions);
    }

    // Add the input field for the delta (drag-n-drop reordering).
    $element['_weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight for row @number', [
        '@number' => $delta + 1,
      ]),
      '#title_display' => 'invisible',
      // Note: this 'delta' is the FAPI #type 'weight' element's property not
      // the delta of the current element.
      '#delta' => $items->count(),
      '#default_value' => $item->_weight ?: $delta,
      '#weight' => 100,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      unset($value['preview']);
      unset($value['operations']);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    $parents = $form['#parents'];
    $field_name = $this->fieldDefinition->getName();
    $field_parents = array_merge($parents, [$field_name]);
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    $widget = NestedArray::getValue($form, $field_state['array_parents']);

    // Retrieve any action perfom on the widget (ex: upload files).
    $action = '';
    $button = $form_state->getTriggeringElement();
    if (isset($button['#field_parents']) && $button['#field_parents'] === $field_parents) {
      $action = end($button['#array_parents']);
    }

    // Extract the values from $form_state->getValues().
    $key_exists = FALSE;
    $values = NestedArray::getValue($form_state->getValues(), $field_parents, $key_exists);
    if ($key_exists) {
      // Extract the existing items and store their original delta to be
      // able to flag errors to the correct form elements before proceeding
      // with any reordering.
      $files = [];
      foreach ($values as $delta => $value) {
        if (is_numeric($delta) && !empty($value)) {
          $element = NestedArray::getValue($widget, [$delta]);

          // Delete the value.
          if ($action === 'delete' && $button['#delta'] === $delta) {
            $value = $this->deleteFieldItem($element, $form_state, $value);
          }
          elseif ($action === 'replace' && $button['#delta'] === $delta) {
            $value = $this->replaceFieldItem($element, $form_state, $value);
          }
          if (!empty($value)) {
            $value['_original_delta'] = $delta;
            $value['_weight'] = isset($value['_weight']) ? $value['_weight'] : $delta;
            $files[] = $value;
          }
        }
      }

      // Apply any re-ordering to the existing items.
      usort($files, function ($a, $b) {
        return SortArray::sortByKeyInt($a, $b, '_weight');
      });

      // Add the newly uploaded items if any.
      if ($action === 'upload') {
        $element = NestedArray::getValue($widget, ['add_more', 'files']);
        $files = array_merge($files, $this->uploadFiles($element, $form_state));
      }

      // Let the widget massage the submitted values.
      //
      // Note: this updates the form state values as they are returned by
      // reference by NestedArray::getValue() so we don't need any additional
      // cleaning.
      $values = $this->massageFormValues($files, $form, $form_state);

      // Assign the values and remove the empty ones.
      $items->setValue($values);
      $items->filterEmptyItems();

      // Put delta mapping in $form_state, so that flagErrors() can use it.
      foreach ($items as $delta => $item) {
        $field_state['original_deltas'][$delta] = $item->_original_delta ?: $delta;

        // We'll let the widget repopulate the weight with the new deltas.
        unset($item->_original_delta, $item->_weight);
      }
    }

    // Store the original items so we can process deleted and replaced files
    // when saving the field's data.
    $items->_original_values = $field_state['original_values'] ?? [];

    // Update the field state items with the new data.
    $field_state['items'] = $items->getValue();
    static::setWidgetState($parents, $field_name, $form_state, $field_state);
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
    return $this->processUploadedFiles($element, $form_state, $element['#name']);
  }

  /**
   * Create a ReliefWebFile field item.
   *
   * @param array|null $values
   *   Optional values to initialize the field item with.
   *
   * @return \Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile
   *   Field item.
   */
  protected function createFieldItem(?array $values = NULL) {
    $item = ReliefWebFileType::createInstance($this->fieldDefinition->getItemDefinition());
    if (!is_null($values)) {
      $item->setValue($values);
    }
    return $item;
  }

  /**
   * Delete a field item.
   *
   * @param array $element
   *   Form element to which attach the errors if any.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to add the errors.
   * @param array $values
   *   The original field item values.
   *
   * @return null
   *   Empty field item values to ensure the item is deleted.
   */
  protected function deleteFieldItem(array $element, FormStateInterface $form_state, array $values) {
    try {
      // If the file was new or replaced, remove its associated managed files.
      if (empty($values['revision_id'])) {
        ReliefWebFileType::deleteFileFromUuid($values['file_uuid']);
        ReliefWebFileType::deleteFileFromUuid($values['preview_uuid'], TRUE);
      }
    }
    catch (\Exception $exception) {
      $form_state->setError($element, $exception->getMessage());
    }
    return NULL;
  }

  /**
   * Create field items from upload files.
   *
   * @param array $element
   *   Form element to which attach the errors if any.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to add the errors.
   * @param array $values
   *   The original field item values.
   *
   * @return array
   *   The new field item values.
   */
  protected function replaceFieldItem(array $element, FormStateInterface $form_state, array $values) {
    // Retrieve the upload validators for the original item. This will
    // ensure the replacement is of the same type.
    $validators = $this->createFieldItem($values)->getUploadValidators();

    // Create a new field item with associated managed files and replace the
    // original values with its values.
    $name = $element['operations']['file']['#name'];
    $items = $this->processUploadedFiles($element, $form_state, $name, $validators);
    if (!empty($items)) {
      $item = reset($items);
      // Copy some properties from the original file.
      $item['uuid'] = $values['uuid'];
      $item['description'] = $values['description'];
      $item['language'] = $values['language'];
      return $item;
    }
    // If we couldn't replace the file, keep the original values.
    return $values;
  }

  /**
   * Create field items from upload files.
   *
   * @param array $element
   *   Form element to which attach the errors if any.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to add the errors.
   * @param string $name
   *   Name of the request property that contain the upload files.
   * @param array $validators
   *   Upload validators as expected by file_validate().
   *
   * @return array
   *   List of field item data.
   */
  protected function processUploadedFiles(array $element, FormStateInterface $form_state, $name, array $validators = []) {
    /** @var \Symfony\Component\HttpFoundation\FileBag $file_bag */
    $file_bag = $this->requestStack->getCurrentRequest()->files;

    // For fields that accept multiple uploads the parameter name ends with
    // `[]` and we need to strip it to retrieve the files.
    if (substr($name, -2) === '[]') {
      $name = substr($name, 0, -2);
      $files = $file_bag->get($name, []);
    }
    else {
      $files = [$file_bag->get($name, NULL)];
    }

    $items = [];
    $errors = [];
    foreach ($files as $file) {
      if (!empty($file)) {
        try {
          $items[] = $this->processUploadedFile($file, $validators)->getValue();
        }
        catch (\Exception $exception) {
          $errors[] = $exception->getMessage();

          // Try to delete the uploaded file.
          $this->deleteUploadedFile($file->getRealPath());
        }
      }
    }

    if (!empty($errors)) {
      $form_state->setError($element, $this->generateErrorList($errors));
    }

    // Remove the files so that they are not processed again.
    $file_bag->remove($name);
    return $items;
  }

  /**
   * Process an uploaded file and create a field item from it.
   *
   * @param \SplFileInfo $file_info
   *   The uploaded file info.
   * @param array $validators
   *   Upload validators as expected by file_validate().
   *
   * @return \Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile
   *   New field item created with the file information.
   *
   * @throws \Exception
   *   Throw an exception if the managed file or the field item couldn't be
   *   created.
   *
   * @todo upload to the docstore directly if/once we allow temporary
   * files to be uploaded. For now we cannot create a docstore file here
   * because if, for whatever reason, the form submission doesn't fully
   * finish, then we'll end up with a unused file in the docstore. So instead
   * we create a temporary managed file that can be garbage collected if the
   * the submission doesn't finish.
   */
  protected function processUploadedFile(\SplFileInfo $file_info, array $validators = []) {
    $file_name = $file_info->getClientOriginalName();

    // @todo throw a more relevant information.
    if (!$this->validateUploadedFile($file_info)) {
      $this->throwError($this->t('Invalid file %name.', [
        '%name' => $file_name,
      ]));
    }

    // Create a field item that we can validate.
    $item = $this->createFieldItem();

    // Get the real path of the uploaded file.
    $path = $file_info->getRealPath();

    // We generate a UUID before creating the file entity so that we can use
    // it for the file uri.
    $file_uuid = ReliefWebFileType::generateUuid();

    // Generate the file uri.
    $extension = ReliefWebFileType::getFileExtension($file_name);
    $uri = ReliefWebFileType::getFileUriFromUuid($file_uuid, $extension, TRUE);

    // Try to guess the real mime type of the uploaded file.
    $file_mime = ReliefWebFileType::getFileMimeType($uri);

    // Create a temporary managed file associated with the uploaded file.
    $file = ReliefWebFileType::createFileFromUuid($file_uuid, $uri, $file_name, $file_mime);

    // Set the file size.
    $file->setSize(@filesize($path) ?? 0);

    // Validate the file.
    $errors = file_validate($file, $validators + $item->getUploadValidators());

    // Bail out if the uploaded file is invalid.
    if (!empty($errors)) {
      $this->throwError($this->t('Unable to upload the file %name. @errors', [
        '%name' => $file_name,
        '@errors' => $this->generateErrorList($errors),
      ]));
    }

    // Create the private temp directory to store the file.
    if (!ReliefWebFileType::prepareDirectory($uri)) {
      $this->throwError($this->t('Unable to create the destination directory for the uploaded file %name.', [
        '%name' => $file_name,
      ]));
    }

    // Move the uploaded file.
    if (!$this->fileSystem->moveUploadedFile($path, $uri)) {
      $this->throwError($this->t('Unable to copy the uploaded file %name.', [
        '%name' => $file_name,
      ]));
    }

    // Update the file.
    $file->setMimeType(ReliefWebFileType::getFileMimeType($uri));
    $file->setSize(@filesize($uri) ?? 0);

    // Populate and return the field item.
    return $this->populateFieldItemFromFile($item, $file);
  }

  /**
   * Populate a field item with the data from a file entity.
   *
   * @param \Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile $item
   *   Field item.
   * @param \Drupal\file\Entity\File $file
   *   File entity.
   *
   * @return \Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile
   *   The updated field item.
   *
   * @throws \Exception
   *   Throws an exception if the field item data is invalid.
   */
  protected function populateFieldItemFromFile(ReliefWebFileType $item, File $file) {
    $item->setValue([
      'uuid' => ReliefWebFileType::generateUuid(),
      // A revision of 0 is an easy way to determine new files.
      // This will be populated after a successful upload for remote files or
      // when saving the local file as permanent.
      'revision_id' => 0,
      // We mark the item as private initially. It may be changed to public
      // depending on the status of the entity the field is attached to etc.
      'private' => TRUE,
      'file_uuid' => $file->uuid(),
      'file_name' => $file->getFilename(),
      'file_mime' => $file->getMimeType(),
      'file_size' => $file->getSize(),
      'page_count' => ReliefWebFileType::getFilePageCount($file),
    ]);

    // Validate the field item.
    $violations = $item->validate();
    if ($violations->count() > 0) {
      $file_name = $file->getFilename();

      foreach ($violations as $violation) {
        $this->logger->error('Field item violation at %property_path for file %name : @message', [
          '%property_path' => $violation->getPropertyPath(),
          '%name' => $file_name,
          '@message' => $violation->getMessage(),
        ]);
      }

      $this->deleteUploadedFile($file->getFileUri());

      $this->throwError($this->t('Invalid field item data for the uploaded file %name.', [
        '%name' => $file_name,
      ]));
    }

    // Save the file.
    $file->setTemporary();
    $file->save();

    return $item;
  }

  /**
   * Throw an error message to display as a form error.
   *
   * @param \Drupal\Component\Render\MarkupInterface|string $message
   *   The error message.
   *
   * @throws \Exception
   *   The error message wrapped in an exception.
   */
  protected function throwError($message) {
    throw new \Exception($message);
  }

  /**
   * Group several errors to disply by FormStateInterface::setErrorByName().
   *
   * The Form API doesn't allow to set several errors on an element so we need
   * to group and pre-render them.
   *
   * @param array $errors
   *   List of error messages.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The grouped errors.
   */
  protected function generateErrorList(array $errors) {
    if (empty($errors)) {
      return '';
    }
    foreach ($errors as &$error) {
      $error = Markup::create($error);
    }
    if (count($errors) > 1) {
      $message = [
        '#theme' => 'item_list',
        '#items' => $errors,
      ];
      return $this->renderer->renderRoot($message);
    }
    return reset($errors);
  }

  /**
   * Delete an uploaded file.
   *
   * @param string $uri
   *   File URI.
   *
   * @return bool
   *   TRUE if the file was deleted.
   */
  protected function deleteUploadedFile($uri) {
    return $this->fileSystem->unlink($uri);
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
  protected function validateUploadedFile(\SplFileInfo $file_info) {
    return $file_info->isValid() &&
           $file_info->getRealPath() !== FALSE &&
           // Max allowed length of a managed file name.
           // @see file_validate_name_length()
           mb_strlen($file_info->getClientOriginalName()) <= 240;
  }

  /**
   * Submit callback for the different operations in the widget.
   *
   * This simply instructs to rebuild the form.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function submit(array &$form, FormStateInterface &$form_state) {
    $form_state->setRebuild();
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
    // Retrieve the updated widget.
    $parents = explode('/', $request->query->get('field_parents'));
    $field_name = array_pop($parents);
    $field_state = static::getWidgetState($parents, $field_name, $form_state);

    // The array parents are populaed in the WidgetBase::afterBuild().
    $widget = NestedArray::getValue($form, $field_state['array_parents']);

    // Create the response and ensure the widget attachments will be loaded.
    $response = new AjaxResponse();
    $response->setAttachments($widget['#attached'] ?? []);

    // This will replace the widget with the new one in the form.
    return $response->addCommand(new ReplaceCommand(NULL, $widget));
  }

}
