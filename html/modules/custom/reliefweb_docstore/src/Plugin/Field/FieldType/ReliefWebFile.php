<?php

namespace Drupal\reliefweb_docstore\Plugin\Field\FieldType;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Utility\Random;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

/**
 * Plugin implementation of the 'reliefweb_file' field type.
 *
 * @FieldType(
 *   id = "reliefweb_file",
 *   label = @Translation("ReliefWeb File"),
 *   description = @Translation("File field with OCHA docstore backend"),
 *   category = @Translation("ReliefWeb"),
 *   default_widget = "reliefweb_file",
 *   default_formatter = "reliefweb_file",
 *   list_class = "\Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFileList",
 *   cardinality = -1,
 * )
 */
class ReliefWebFile extends FieldItemBase {

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The docstore client service.
   *
   * @var \Drupal\reliefweb_docstore\Services\DocstoreClient
   */
  protected $docstoreClient;

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'local' => TRUE,
      'file_extensions' => '',
      'max_filesize' => NULL,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);
    $settings = $this->getSettings();

    $element['local'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Store files locally'),
      '#description' => $this->t('Check if the files should be stored locally instead of remotely.'),
      '#default_value' => $this->getSetting('local'),
    ];

    // Make the extension list a little more human-friendly by comma-separation.
    $extensions = str_replace(' ', ', ', $settings['file_extensions']);
    $element['file_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed file extensions'),
      '#default_value' => $extensions,
      '#description' => $this->t("Separate extensions with a comma or space. Each extension can contain alphanumeric characters, '.', and '_', and should start and end with an alphanumeric character."),
      '#element_validate' => [[static::class, 'validateExtensions']],
      '#maxlength' => 255,
      // By making this field required, we prevent a potential security issue
      // that would allow files of any type to be uploaded.
      '#required' => TRUE,
    ];

    $element['max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum upload size'),
      '#default_value' => $settings['max_filesize'],
      '#description' => $this->t('Enter a value like "512" (bytes), "80 KB" (kilobytes) or "50 MB" (megabytes) in order to restrict the allowed file size. If left empty the file sizes will be limited only by PHP\'s maximum post and file upload sizes (current limit <strong>%limit</strong>).', [
        '%limit' => format_size(Environment::getUploadMaxSize()),
      ]),
      '#size' => 10,
      '#element_validate' => [[static::class, 'validateMaxFilesize']],
    ];

    return $element;
  }

  /**
   * Form API callback.
   *
   * This doubles as a convenience clean-up function and a validation routine.
   * Commas are allowed for the end-user, but ultimately the value will be
   * stored as a space-separated list for compatibility with
   * file_validate_extensions().
   *
   * This function is assigned as an #element_validate callback in
   * fieldSettingsForm().
   *
   * @param array $element
   *   Form element to validate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function validateExtensions(array $element, FormStateInterface $form_state) {
    if (!empty($element['#value'])) {
      $extensions = preg_replace('/([, ]+\.?)/', ' ', trim(strtolower($element['#value'])));
      $extension_array = array_unique(array_filter(explode(' ', $extensions)));
      $extensions = implode(' ', $extension_array);
      if (!preg_match('/^([a-z0-9]+([._][a-z0-9])* ?)+$/', $extensions)) {
        $form_state->setError($element, t("The list of allowed extensions is not valid. Allowed characters are a-z, 0-9, '.', and '_'. The first and last characters cannot be '.' or '_', and these two characters cannot appear next to each other. Separate extensions with a comma or space."));
      }
      else {
        $form_state->setValueForElement($element, $extensions);
      }

      // If insecure uploads are not allowed and txt is not in the list of
      // allowed extensions, ensure that no insecure extensions are allowed.
      if (!in_array('txt', $extension_array, TRUE) && !\Drupal::config('system.file')->get('allow_insecure_uploads')) {
        foreach ($extension_array as $extension) {
          if (preg_match(FileSystemInterface::INSECURE_EXTENSION_REGEX, 'test.' . $extension)) {
            $form_state->setError($element, t('Add %txt_extension to the list of allowed extensions to securely upload files with a %extension extension. The %txt_extension extension will then be added automatically.', [
              '%extension' => $extension,
              '%txt_extension' => 'txt',
            ]));
            break;
          }
        }
      }
    }
  }

  /**
   * Form API callback.
   *
   * Ensures that a size has been entered and that it can be parsed by
   * \Drupal\Component\Utility\Bytes::toNumber().
   *
   * This function is assigned as an #element_validate callback in
   * fieldSettingsForm().
   *
   * @param array $element
   *   Form element to validate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function validateMaxFilesize(array $element, FormStateInterface $form_state) {
    $element['#value'] = trim($element['#value']);
    $form_state->setValue(['settings', 'max_filesize'], $element['#value']);
    if (!empty($element['#value']) && !Bytes::validate($element['#value'])) {
      $form_state->setError($element, $this->t('The "@name" option must contain a valid value. You may either leave the text field empty or enter a string like "512" (bytes), "80 KB" (kilobytes) or "50 MB" (megabytes).', [
        '@name' => $element['#title'],
      ]));
    }
  }

  /**
   * Retrieves the upload validators for a file field.
   *
   * @return array
   *   An array suitable for passing to file_save_upload() or the file field
   *   element's '#upload_validators' property.
   */
  public function getUploadValidators() {
    $validators = [];

    // Validate the extension, limiting to the current one if defined, to
    // ensure a file can be replaced only by a file of the same type.
    $extensions = $this->getAllowedFileExtensions();
    if (!empty($extensions)) {
      $validators['file_validate_extensions'] = [implode(' ', $extensions)];
    }

    // Validate the file mime as well if defined, to  ensure a file can be
    // replaced only by a file of the same type.
    $file_mime = $this->get('file_mime')->getValue();
    if (!empty($file_mime)) {
      $validators['reliefweb_docostore_file_validate_mime_type'] = [$file_mime];
    }

    // There is always a file size limit due to the PHP server limit.
    $validators['file_validate_size'] = [$this->getMaxFileSize()];

    // Validate the filename length.
    $validators['file_validate_name_length'] = [];

    return $validators;
  }

  /**
   * Get the upload max file size.
   *
   * @return int
   *   The max file size in bytes.
   */
  public function getMaxFileSize() {
    $settings = $this->getSettings();

    $max_filesize = Bytes::toNumber(Environment::getUploadMaxSize());
    if (!empty($settings['max_filesize'])) {
      $max_filesize = min($max_filesize, Bytes::toNumber($settings['max_filesize']));
    }

    return $max_filesize;
  }

  /**
   * Get the allowed file extensions for validation and description.
   *
   * @return array
   *   Allowed file extensions.
   */
  public function getAllowedFileExtensions() {
    $settings = $this->getSettings();

    $file_name = $this->get('file_name')->getValue();
    if (!empty($file_name)) {
      return [static::getFileExtension($file_name)];
    }
    elseif (!empty($settings['file_extensions'])) {
      return explode(' ', $settings['file_extensions']);
    }
    return [];
  }

  /**
   * Get the description with the allowed extensions and file size.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The upload description.
   */
  public function getUploadDescription() {
    $extensions = $this->getAllowedFileExtensions();
    return $this->t('Allowed extensions: %extensions. Max file size: %max_filesize.', [
      '%extensions' => !empty($extensions) ? implode(', ', $extensions) : $this->t('any'),
      '%max_filesize' => format_size($this->getMaxFileSize()),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'uuid';
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // The UUID of the file resource used notably to generate the permanent URL.
    $properties['uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('File UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the file resource.'))
      ->setRequired(TRUE);

    // The ID of the file revision.
    // When "remote" is selected in the settngs, this is ID of the resource
    // revision.
    // When "local" is selected in the settings, this is ID of the permanent
    // local file.
    // It's set to 0 for content being created.
    $properties['revision_id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('File revision ID'))
      ->setDescription(new TranslatableMarkup('The ID of the file revision.'))
      ->setRequired(TRUE);

    // The UUID of the local file associated with the field item.
    // When "remote" is selected in the settngs, this is the UUID of the
    // temporary file used to store the file content to generate the preview.
    // When "local" is selected in the settings, this is the UUID of the
    // permanent file associated with the field item.
    $properties['file_uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Preview UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the preview file.'))
      ->setRequired(TRUE);

    // The name of the file as uploaded.
    $properties['file_name'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('File Name'))
      ->setDescription(new TranslatableMarkup('The file name as uploaded.'))
      ->setRequired(TRUE);

    // The mime type of the field.
    $properties['file_mime'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('File Mime'))
      ->setDescription(new TranslatableMarkup('The file mime type.'))
      ->setRequired(TRUE);

    // The size (in bytes) of the field.
    $properties['file_size'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('File Size'))
      ->setDescription(new TranslatableMarkup('The file size in bytes.'))
      ->setRequired(TRUE);

    // The ISO 639-1 code of the main language of the file content.
    $properties['language'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Language'))
      ->setDescription(new TranslatableMarkup('The ISO 639-1 code of the main language of the file content.'))
      ->setRequired(FALSE);

    // Brief description of the file (ex: label).
    $properties['description'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('A brief description of the file.'))
      ->setRequired(FALSE);

    // Number of pages in the file.
    $properties['page_count'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Page count'))
      ->setDescription(new TranslatableMarkup('Number of pages in the file.'))
      ->setRequired(FALSE);

    // The UUID of the preview file.
    // When it exists, this is the UUID of the permanent local preview file.
    $properties['preview_uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Preview UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the preview file.'))
      ->setRequired(FALSE);

    // The page used for the file preview.
    $properties['preview_page'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Preview page'))
      ->setDescription(new TranslatableMarkup('The page used for the file preview.'))
      ->setRequired(FALSE);

    // The rotation of the page used for the preview.
    $properties['preview_rotation'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Preview page rotation'))
      ->setDescription(new TranslatableMarkup('The rotation of the page used for the preview.'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'uuid' => [
          'type' => 'varchar_ascii',
          'length' => 36,
          'not null' => TRUE,
        ],
        'revision_id' => [
          'type' => 'int',
          'size' => 'medium',
          'not null' => TRUE,
        ],
        'private' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
        ],
        'file_name' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'file_mime' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'file_size' => [
          'type' => 'int',
          'size' => 'big',
          'not null' => TRUE,
        ],
        'language' => [
          'type' => 'varchar_ascii',
          'length' => 12,
          'not null' => FALSE,
        ],
        'description' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'page_count' => [
          'type' => 'int',
          'size' => 'medium',
          'not null' => FALSE,
        ],
        'preview_uuid' => [
          'type' => 'varchar_ascii',
          'length' => 36,
          'not null' => FALSE,
        ],
        'preview_page' => [
          'type' => 'int',
          'size' => 'medium',
          'not null' => FALSE,
        ],
        'preview_rotation' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => FALSE,
        ],
      ],
      'indexes' => [
        'uuid' => ['uuid'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();

    $constraint_manager = \Drupal::typedDataManager()
      ->getValidationConstraintManager();

    // @todo add a constraint for the preview uuid, page and rotation.
    $constraints[] = $constraint_manager->create('ComplexData', [
      'uuid' => [
        'Uuid' => [],
      ],
    ]);

    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();

    $values['uuid'] = static::generateUuid();
    $values['revision_id'] = mt_rand(1111, 9999);
    $values['file_uuid'] = static::generateUuid();
    $valyes['file_name'] = $random->string(mt_rand(1, 250)) . '.' . $random->string(mt_rand(1, 4));
    $valyes['file_mime'] = $random->string(mt_rand(32, 128));
    $valyes['file_size'] = mt_rand(1111, 99999);
    $values['language'] = ['en', 'fr', 'sp'][mt_rand(0, 2)];
    $values['description'] = $random->sentences(mt_rand(1, 5));
    $valyes['page_count'] = mt_rand(1, 100);
    $values['preview_uuid'] = static::generateUuid();
    $values['preview_page'] = mt_rand(1, 100);
    $values['preview_rotation'] = mt_rand(0, 2);

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $uuid = $this->get('uuid')->getValue();
    return empty($uuid);
  }

  /**
   * Get data to render the file preview.
   *
   * @param string $style
   *   The image style to use for the preview.
   * @param bool $generate
   *   Whether to generate the preview if doesn't exist or not.
   *
   * @return array
   *   Render array.
   */
  public function renderPreview($style = 'small', $generate = FALSE) {
    $preview = $this->getPreview($generate);
    if (empty($preview)) {
      return [];
    }

    $uri = $preview->getFileUri();

    // @todo store the dimensions or use something faster to get
    // the width and height?
    $info = getimagesize($uri);
    if ($info === FALSE) {
      return [];
    }

    return [
      '#theme' => 'image_style',
      '#style_name' => $style,
      '#uri' => $uri,
      '#alt' => $this->t('Preview of @file_name', [
        '@file_name' => $this->get('file_name')->getValue(),
      ]),
      '#attributes' => [
        'class' => ['rw-file-preview'],
      ],
      '#width' => $info[0],
      '#height' => $info[1],
    ];
  }

  /**
   * Get the file entity of the preview.
   *
   * @param bool $generate
   *   Whether to generate the preview if doesn't exist or not.
   *
   * @return \Drupal\file\Entity\File|null
   *   Managed file or NULL if the item doesn't have a preview or it couldn't
   *   be generated.
   */
  public function getPreview($generate = FALSE) {
    $file = $this->loadPreviewFile();
    if (empty($file) && $generate && $this->canHavePreview()) {
      $page = $this->get('preview_page')->getValue();
      $rotation = $this->get('preview_rotation')->getValue();
      return $this->generatePreview($page, $rotation);
    }
    return NULL;
  }

  /**
   * Generate the file preview if it's a supported format.
   *
   * @param int $page
   *   The page to use for the preview.
   * @param int $rotation
   *   The rotation of the preview page (0, 90 or -90).
   * @param bool $regenerate
   *   TRUE to force the regeneration of the preview.
   *
   * @return \Drupal\file\Entity\File|null
   *   The preview file entity or NULL if the preview was not generated.
   */
  public function generatePreview($page = 0, $rotation = 0, $regenerate = FALSE) {
    if ($page < 1 || $this->isEmpty() || !$this->canHavePreview() || $this->getPageCount() === 0) {
      return NULL;
    }

    // Ensure the page is a valid value.
    $page = $page > $this->getPageCount() ? 1 : $page;

    // Ensure the rotation is a valid value.
    $rotation = in_array($rotation, [0, 90, -90]) ? $rotation : 0;

    $preview_uuid = $this->get('preview_uuid')->getValue();
    $current_page = $this->get('preview_page')->getValue();
    $current_rotation = $this->get('preview_rotation')->getValue();

    // Skip if the preview already exists for the given page and rotation.
    if (!$regenerate && !empty($preview_uuid) && $page == $current_page && $rotation == $current_rotation) {
      return $this->loadFileByUuid($preview_uuid);
    }

    // Load or create a file associated with the field item.
    $file = $this->loadFile();
    if (empty($file) || !file_exists($file->getFileUri())) {
      // For local files, we cannot generate the preview if there is no file
      // associated with the field item.
      if ($this->getSetting('local') === TRUE) {
        return NULL;
      }
      // For remote files, we try to retrieve the file content and create a
      // temporary local file.
      else {
        $file = $file ?? $this->createFile();
        if (!$this->updateFileFromRemote($file)) {
          return NULL;
        }
      }
    }

    // Retrieve or create the preview file.
    $preview_file = $this->loadPreviewFile();
    if (empty($preview_file)) {
      $preview_file = $this->createPreviewFile();
    }

    // Create the preview.
    if (!$this->extractPreview($file->getFileUri(), $preview_file->getFileUri(), $page, $rotation)) {
      return NULL;
    }

    // Save the preview. We don't mark it yet as permanent so that it can be
    // garbage collected if the form, for example, is not submitted.
    $preview_file->save();

    // Update the field item preview uuid as it may be a new file.
    $this->get('preview_uuid')->setValue($preview_file->uuid());
    $this->get('preview_page')->setValue($page);
    $this->get('preview_rotation')->setValue($rotation);

    // Delete the preview derivatives to ensure they correspond to the updated
    // preview image.
    image_path_flush($preview_file->getFileUri());

    return $preview_file;
  }

  /**
   * Extract the preview from the given file source.
   *
   * @param string $source_uri
   *   URI of the file from which to extract the preview.
   * @param string $destination_uri
   *   URI of the file preview file.
   * @param int $page
   *   Page to use for the preview.
   * @param int $rotation
   *   Rotation of the preview page.
   *
   * @return bool
   *   TRUE if the extraction was successful.
   */
  protected function extractPreview($source_uri, $destination_uri, $page, $rotation) {
    $file_system = $this->getFileSystem();

    // Create the previews directory to store the file.
    if (!static::prepareDirectory($destination_uri)) {
      // @todo log the error.
      return FALSE;
    }

    // Mutool needs the full real paths of the files. The destination one can
    // only be retrieved after creating its directory path.
    $source_path = $file_system->realpath($source_uri);
    $destination_path = $file_system->realpath($destination_uri);
    if (empty($source_path) || empty($destination_path)) {
      // @todo log the information?
      return FALSE;
    }

    $source = escapeshellarg($source_path);
    $destination = escapeshellarg($destination_path);
    $page = escapeshellarg($page);
    $rotation = escapeshellarg($rotation);

    $mutool = \Drupal::state()->get('mutool', '/usr/bin/mutool');
    if (is_executable($mutool)) {
      // @todo add max dimensions.
      $command = "{$mutool} draw -R {$rotation} -o {$destination} {$source} {$page}";
      exec($command, $output, $return_val);
      // @todo log error?
      return empty($return_val) && @file_exists($destination_uri);
    }
    return FALSE;
  }

  /**
   * Regenerate the preview.
   *
   * @return bool
   *   TRUE if the preview generation was successful.
   */
  public function regeneratePrevew() {
    $page = $this->get('preview_page')->getValue();
    $rotation = $this->get('preview_rotation')->getValue();
    return $this->generatePreview($page, $rotation, TRUE);
  }

  /**
   * Check if a preview can be generated for this field item.
   *
   * @return bool
   *   TRUE if a preview can be generated.
   */
  public function canHavePreview() {
    return $this->get('file_mime')->getValue() === 'application/pdf';
  }

  /**
   * Delete the preview file and its derivative images.
   */
  protected function deletePreview() {
    $file = $this->loadPreviewFile();
    if (!empty($file)) {
      $uri = $file->getFileUri();
      $file->delete();
      image_path_flush($uri);
    }
  }

  /**
   * Get the number of pages for this field item file.
   *
   * @return int
   *   Number of pages. Returns 0 if the number of pages is irrelevant.
   */
  public function getPageCount() {
    if ($this->canHavePreview()) {
      return $this->get('page_count')->getValue() ?? 1;
    }
    return 0;
  }

  /**
   * Load the file associated with the field item.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity or NULL if it couldn't be loaded.
   */
  public function loadFile() {
    return $this->loadFileByUuid($this->get('file_uuid')->getValue());
  }

  /**
   * Load the preview file associated with the field item.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity or NULL if it couldn't be loaded.
   */
  public function loadPreviewFile() {
    return $this->loadFileByUuid($this->get('preview_uuid')->getValue());
  }

  /**
   * Get the file URL.
   *
   * @return \Drupal\Core\Url
   *   URL object.
   */
  public function getFileUrl($private = FALSE) {
    $uuid = $this->get('uuid')->getValue();
    $file_name = $this->get('file_name')->getValue();
    $extension = static::getFileExtension($file_name);
    $revision_id = $this->get('revision_id')->getValue();

    // If the revision ID is not set, then it's a new file and there should be
    // an associated managed file and we return its URL.
    if (empty($revision_id)) {
      $url = file_create_url(static::getFileUriFromUuid($file_uuid, $extension));
    }
    // Otherwise use a "direct" link to the file.
    elseif ($private) {
      $url = 'internal:/private/attachments/' . $uuid . '/' . $file_name;
    }
    else {
      $url = 'internal:/attachments/' . $uuid . '/' . $file_name;
    }
    return !empty($url) ? Url::fromUri($url) : '';
  }

  /**
   * Create a managed file for the preview.
   *
   * @return \Drupal\file\Entity\File
   *   Managed file.
   */
  protected function createPreviewFile() {
    if ($this->isEmpty()) {
      return NULL;
    }

    $uuid = $this->get('uuid')->getValue();

    // The preview is PNG image.
    $extension = 'png';
    $file_name = $uuid . '.' . $extension;
    $file_mime = 'image/' . $extension;

    // Generate a UUID for the preview. We'll store it in this field item only
    // if the preview generation worked.
    $preview_uuid = $this->generateUuid();

    // Generate the preview URI as private initially. We'll move it to public
    // if the docstore file is made public.
    $uri = static::getFileUriFromUuid($preview_uuid, $extension, TRUE, TRUE);

    // Create a temporary managed file with the System user.
    return static::createFileFromUuid($preview_uuid, $uri, $file_name, $file_mime);
  }

  /**
   * Create a managed file for this field item.
   *
   * @return \Drupal\file\Entity\File
   *   Managed file.
   */
  protected function createFile() {
    if ($this->isEmpty()) {
      return NULL;
    }

    $file_uuid = $this->get('file_uuid')->getValue();
    $file_name = $this->get('file_name')->getValue();
    $file_mime = $this->get('file_mime')->getValue();

    if (empty($file_uuid)) {
      $file_uuid = static::generateUuid();
      $this->setValue('file_uuid', $file_uuid);
    }

    // Generate the file uri.
    $extension = static::getFileExtension($file_name);
    $uri = static::getFileUriFromUuid($file_uuid, $extension, TRUE);

    // Create a temporary managed file with the System user.
    return static::createFileFromUuid($file_uuid, $uri, $file_name, $file_mime);
  }

  /**
   * Update the file with the content of the docstore file resource.
   *
   * @param \Drupal\file\Entity\File $file
   *   The managed file entity.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function updateFileFromRemote(File $file) {
    $uuid = $this->get('uuid')->getValue();
    $revision_id = $this->get('revision_id')->getValue();
    $uri = $file->getFileUri();

    // Create the private temp directory to store the file.
    if (!static::prepareDirectory($uri)) {
      // @todo log error?
      return FALSE;
    }

    // Save the docstore file content.
    $success = $this->getDocstoreClient()
      ->downloadFileContentToFilePath($uuid, $uri, $revision_id);

    // Update the file entity.
    if ($success) {
      // Update the file entity and save it so that we don't have to
      // re-download the file to generate the preview. Its status being
      // temporary it will be garbage collected as some point by the system.
      $file->setMimeType($this->getFileMimeType($uri));
      $file->setTemporary();
      $file->save();
    }
    return $success;
  }

  /**
   * Load a file entity by its UUID.
   *
   * @param string $uuid
   *   File entity UUID.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity or NULL if it couldn't be loaded.
   */
  protected function loadFileByUuid($uuid) {
    if (empty($uuid)) {
      return NULL;
    }
    return $this->getEntityRepository()->loadEntityByUuid('file', $uuid);
  }

  /**
   * Get the permanent file URI for the field item.
   *
   * @param bool $preview
   *   Whether the URI is for the preview file or the local file.
   *
   * @return string
   *   URI.
   */
  public function getPermanentUri($preview = TRUE) {
    $uuid = $this->get('uuid')->getValue();
    $private = $this->get('private')->getValue();
    $extension = self::getFileExtension($file->getFileName());
    return static::getFileUriFromUuid($uuid, $extension, !empty($private), $preview);
  }

  /**
   * Get the entity repository service.
   *
   * @return \Drupal\Core\Entity\EntityRepositoryInterface
   *   The entity repository service.
   */
  protected function getEntityRepository() {
    if (!isset($this->entityRepository)) {
      $this->entityRepository = \Drupal::service('entity.repository');
    }
    return $this->entityRepository;
  }

  /**
   * Get the entity type manager service.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager service.
   */
  protected function getEntityTypeManager() {
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }
    return $this->entityTypeManager;
  }

  /**
   * Get the file system serice.
   *
   * @return \Drupal\Core\File\FileSystemInterface
   *   The file system service.
   */
  protected function getFileSystem() {
    if (!isset($this->fileSystem)) {
      $this->fileSystem = \Drupal::service('file_system');
    }
    return $this->fileSystem;
  }

  /**
   * Get the docstore client service.
   *
   * @return \Drupal\reliefweb_docstore\Services\DocstoreClient
   *   The docstore client service.
   */
  protected function getDocstoreClient() {
    if (!isset($this->docstoreClient)) {
      $this->docstoreClient = \Drupal::service('reliefweb_docstore.client');
    }
    return $this->docstoreClient;
  }

  /**
   * Get the file directory URI from a UUID.
   *
   * @param string $uuid
   *   File UUID.
   * @param bool $private
   *   Whether the file is private or not.
   * @param bool $preview
   *   Whether the file is a preview or an attachment.
   *
   * @return string
   *   The directory URI.
   */
  public static function getFileDirectoryUriFromUuid($uuid, $private = TRUE, $preview = FALSE) {
    $settings = \Drupal::service('config.factory')->get('reliefweb_docstore.settings');

    $directory = $private ? 'private://' : 'public://';
    if ($preview) {
      $directory .= $settings->get('preview_directory') ?? 'previews';
    }
    else {
      $directory .= $settings->get('file_directory') ?? 'attachments';
    }
    $directory .= '/' . substr($uuid, 0, 2);
    $directory .= '/' . substr($uuid, 2, 2);
    return $directory;
  }

  /**
   * Get the file URI from a UUID.
   *
   * @param string $uuid
   *   File UUID.
   * @param string $extension
   *   File extension.
   * @param bool $private
   *   Whether the file is private or not.
   * @param bool $preview
   *   Whether the file is a preview or an attachment.
   *
   * @return string
   *   The file URI.
   */
  public static function getFileUriFromUuid($uuid, $extension, $private = TRUE, $preview = FALSE) {
    $directory = static::getFileDirectoryUriFromUuid($uuid, $private, $preview);
    return $directory . '/' . $uuid . '.' . $extension;
  }

  /**
   * Create a file entity with the given UUID.
   *
   * @param string $uuid
   *   File UUID.
   * @param string $uri
   *   File URI.
   * @param string $file_name
   *   File name.
   * @param string $file_mime
   *   File mime type.
   *
   * @return \Drupal\file\Entity\File
   *   File entity.
   */
  public static function createFileFromUuid($uuid, $uri, $file_name, $file_mime) {
    return File::create([
      'uuid' => $uuid,
      // We use the System user as owner of the file as those are used for
      // global files that have nothing to do with the current user.
      'uid' => 2,
      'uri' => $uri,
      // Temporary file that can be garbage collected if not set permanent.
      'status' => 0,
      'filename' => $file_name,
      'filemime' => $file_mime,
    ]);
  }

  /**
   * Prepare a directory, creating unexisting paths.
   *
   * @param string $uri
   *   File or directory URI.
   *
   * @return bool
   *   TRUE if the preparation succeeded.
   */
  public static function prepareDirectory($uri) {
    $file_system = \Drupal::service('file_system');
    $directory = $file_system->dirname($uri);
    return $file_system->prepareDirectory($directory, $file_system::CREATE_DIRECTORY);
  }

  /**
   * Move a file.
   *
   * @param string $source
   *   Source URI.
   * @param string $destination
   *   Destination URI.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   Exception if the file couldn't be moved.
   */
  public static function moveFile($source, $destination) {
    if (!static::prepareDirectory($destination)) {
      throw new FileException(strtr('Unable to create destination directory for @destination', [
        '@destination' => $destination,
      ]));
    }

    $file_system = \Drupal::service('file_system');
    $file_system->move($source, $destination, $file_system::EXISTS_REPLACE);
  }

  /**
   * Delete a managed file with the given UUID.
   *
   * @param string $uuid
   *   File UUID.
   * @param bool $preview
   *   Whether the file is a preview or not.
   */
  public static function deleteFileFromUuid($uuid, $preview = FALSE) {
    if (!empty($uuid)) {
      $file = \Drupal::service('entity.repository')
        ->loadEntityByUuid('file', $uuid);

      if (!empty($file)) {
        // Remove the derivative images.
        if ($preview) {
          image_path_flush($file->getFileUri());
        }

        $file->delete();
      }
    }
  }

  /**
   * Get the extension of the file.
   *
   * @param string $file_name
   *   File name.
   *
   * @return string
   *   File extension in lower case.
   */
  public static function getFileExtension($file_name) {
    return mb_strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
  }

  /**
   * Get the mime of the file.
   *
   * @param string $uri
   *   File uri.
   *
   * @return string
   *   File mime type.
   */
  public static function getFileMimeType($uri) {
    return \Drupal::service('file.mime_type.guesser')->guessMimeType($uri);
  }

  /**
   * Check if a file URI is private.
   *
   * @param string $uri
   *   File uri.
   *
   * @return bool
   *   TRUE if the file uri is private.
   */
  public static function isFilePrivate($uri) {
    return StreamWrapperManager::getScheme($uri) === 'private';
  }

  /**
   * Generate a UUID.
   *
   * @return string
   *   UUID.
   */
  public static function generateUuid() {
    return \Drupal::service('uuid')->generate();
  }

  /**
   * Check if the file can have a preview.
   *
   * @param \Drupal\file\Entity\File $file
   *   Managed File.
   *
   * @return bool
   *   TRUE if the file preview can be generated.
   */
  protected static function fileCanHavePreview(File $file) {
    return $file->getMimeType() === 'application/pdf';
  }

  /**
   * Get the number of pages of a file.
   *
   * @param \Drupal\file\Entity\File $file
   *   File entity.
   *
   * @return int
   *   Number of pages.
   */
  public static function getFilePageCount(File $file) {
    if (!static::fileCanHavePreview($file)) {
      return 1;
    }

    $uri = $file->getFileUri();
    if (empty($uri)) {
      return 1;
    }

    $path = \Drupal::service('file_system')->realpath($uri);
    if (empty($path)) {
      return 1;
    }

    $mutool = \Drupal::state()->get('mutool', '/usr/bin/mutool');
    if (is_executable($mutool)) {
      $source = escapeshellarg($path);
      $command = "{$mutool} info -M {$source}";
      exec($command, $output, $return_val);
      if (empty($return_val) && preg_match('/Pages: (?<count>\d+)/', implode("\n", $output), $matches) === 1) {
        return intval($matches['count']);
      }
    }
    return 1;
  }

  /**
   * Get a file URI based on its UUID.
   *
   * @param \Drupal\file\Entity\File $file
   *   File.
   * @param bool $private
   *   Whether to return a private URI or a public one.
   *
   * @return string
   *   URI.
   */
  public static function getFileUuidUri(File $file, $private = TRUE) {
    $extension = self::getFileExtension($file->getFileName());
    return static::getFileUriFromUuid($file->uuid(), $extension, $private);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    $original_item = $this->_original_item ?? NULL;

    try {
      if ($this->getSetting('local') === TRUE) {
        if (isset($original_item)) {
          $this->replaceLocalFile($this->_original_item);
        }
        else {
          $this->createLocalFile();
        }
      }
      else {
        $this->updateRemoteFile();
      }

      // Update the preview if any.
      $this->updatePreviewFile($original_item);
    }
    // @todo log the error and see what to tell the user.
    catch (\Exception $exception) {
      throw $exception;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave($updated) {
    $entity = $this->getEntity();
    if ($entity instanceof EntityPublishedInterface) {
      // Mark the file as public if the entity the file belongs to is published
      // make it private otherwise.
      $item->updateFileStatus($entity->isPublished());
    }
    return parent::postSave($updated);
  }

  /**
   * Create a local file.
   *
   * @throws \Exception
   *   Exception if the file couldn't be created.
   */
  protected function createLocalFile() {
    $file = $this->loadFile();

    // @todo better error message.
    if (empty($file)) {
      throw new \Exception('Unable to load the local file');
    }

    // Move the file to its the permanent URI (private initially).
    $uri = $this->getPermanentUri();
    static::moveFile($file->getFileUri(), $uri);

    // Save the file as permanent.
    $new_file->setFileUri($uri);
    $new_file->setPermanent();
    $new_file->save();

    // Update the revision id with the file ID to distinguish from new items.
    $this->setValue('revision_id', $file->id());
  }

  /**
   * Replace a local file.
   *
   * @param \Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile $old_item
   *   The old field item.
   *
   * @throws \Exception
   *   Exception if the file couldn't be replaced.
   */
  protected function replaceLocalFile(ReliefWebFile $old_item) {
    $new_file = $this->loadFile();
    $old_file = $old_item->loadFile();

    // @todo better error messages.
    if (empty($new_file)) {
      throw new \Exception('Unable to load the new file');
    }
    if (empty($old_file)) {
      throw new \Exception('Unable to load the old file');
    }

    $new_uri = $old_file->getFileUri();
    $old_uri = $old_item->getFileUuidUri($old_file);

    // Move the old file to its new location based on its UUID.
    static::moveFile($old_file->getFileUri(), $old_uri);
    $old_file->setFileUri($old_uri);
    $old_file->setPermanent();
    $old_file->save();

    // Move the new file to the permanent URI (old URI).
    static::moveFile($new_file->getFileUri(), $new_uri);
    $new_file->setFileUri($new_uri);
    $new_file->setPermanent();
    $new_file->save();
  }

  /**
   * Update or create a remote file.
   *
   * @throws \Exception
   *   Exception if the file's content couldn't be updated.
   */
  protected function updateRemoteFile() {
    $file = $this->loadFile();

    // @todo better error message.
    if (empty($file)) {
      throw \Exception('Unable to load the local file.');
    }

    $client = $this->getDocstoreClient();
    $uuid = $this->get('uuid')->getValue();

    // Check if the remote file exists.
    $result = $client->getFile($uuid);
    if (empty($result)) {
      // Create the file resource in the docstore.
      $result = $client->createFile([
        'uuid' => $uuid,
        'filename' => $this->get('file_name')->getValue(),
        'mimetype' => $this->get('file_mime')->getValue(),
        // Mark the new file as private initially. We'll change it that after
        // the entity the field is attached to is properly saved.
        'private' => TRUE,
      ]);

      if (empty($result)) {
        throw \Exception('Unable to create remote file resource');
      }
    }

    // Upload the file content.
    $result = $client->updateFileContentFromFilePath($uuid, $file->getFileUri());

    // @todo better error message.
    if (!isset($result['revision_id'])) {
      throw new \Exception('Unable to update remote file content');
    }

    // Store the new revision returned by the docstore.
    $this->setValue('revision_id', $result['revision_id']);

    // Delete the local file.
    $file->delete();
  }

  /**
   * Update the preview file.
   *
   * @param \Drupal\reliefweb_docstore\Plugin\Field\FieldType\ReliefWebFile|null $old_item
   *   The old field item if any.
   *
   * @throws \Exception
   *   Exception if the file's content couldn't be updated.
   */
  protected function updatePreviewFile(?ReliefWebFile $old_item) {
    // Delete the old preview if any.
    if (isset($old_item)) {
      $old_item->deletePreview();
    }

    // If "no preview" was selected, delete the preview.
    $preview_page = $this->get('preview_page')->getValue();
    if (empty($preview_page) || !$this->canHavePreview()) {
      $this->deletePreview();
    }

    // Get the preview (generate it if necessary).
    $file = $this->getPreview(TRUE);
    if (empty($file)) {
      return;
    }

    // Move the preview to its permanent location.
    $uri = $this->getPermanentUri(TRUE);
    $this->moveFile($file->getFileUri(), $uri);

    // Mark the filed as permanent.
    $file->setFileUri($uri);
    $file->setPermanent();
    $file->save();
  }

  /**
   * Update the status of the file associated with this field item.
   *
   * @param bool $published
   *   TRUE if the file should be made public.
   */
  protected function updateFileStatus($published) {
    // For local files, move to the private or public location if the status
    // is different.
    if ($this->getSetting('local') === TRUE) {
      $file = $this->loadFile();
      if (!empty($file)) {
        $private = $this->isFilePrivate($file->getFileUri());
        if ($private === $published) {
          $this->swapFileLocation($file);
        }
      }
    }
    // For remote files, mark the file resource as private or public.
    else {
      $uuid = $this->get('uuid')->getValue();
      $this->getDocstoreClient()->updateFileStatus($uuid, !$published);
    }

    // Update the preview file as well.
    $preview_file = $this->loadPreviewFile();
    if (!empty($file)) {
      $private = $this->isFilePrivate($preview_file->getFileUri());
      if ($private === $published) {
        $this->swapFileLocation($preview_file);
      }
    }
  }

  /**
   * Swap the location of a local file.
   *
   * @param \Drupal\file\Entity\File $file
   *   File entity.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   Exception if the file couldn't be moved.
   *
   * @todo do something about the exception.
   */
  protected function swapFileLocation(File $file) {
    $uri = $file->getFileUri();
    $scheme = StreamWrapperManager::getScheme($uri);
    $target = StreamWrapperManager::getTarget($uri);
    if ($scheme !== FALSE && $target !== FALSE) {
      $scheme = $scheme === 'private' ? 'public' : 'private';
      $this->moveFile($uri, $scheme . '://' . $target);
    }
  }

}
