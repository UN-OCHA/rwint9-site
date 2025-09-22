<?php

namespace Drupal\reliefweb_files\Plugin\Field\FieldType;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Utility\Random;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\reliefweb_utility\Helpers\FileHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;

/**
 * Plugin implementation of the 'reliefweb_file' field type.
 *
 * @FieldType(
 *   id = "reliefweb_file",
 *   label = @Translation("ReliefWeb File"),
 *   description = @Translation("File field with OCHA docstore backend"),
 *   category = "reliefweb",
 *   default_widget = "reliefweb_file",
 *   default_formatter = "reliefweb_file",
 *   list_class = "\Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFileList",
 *   cardinality = -1,
 * )
 */
class ReliefWebFile extends FieldItemBase {

  use EntityDatabaseInfoTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The docstore client service.
   *
   * @var \Drupal\reliefweb_files\Services\DocstoreClient
   */
  protected $docstoreClient;

  /**
   * The docstore config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $docstoreConfig;

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
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'file_extensions' => '',
      'max_filesize' => NULL,
      'preview_max_filesize' => NULL,
      'preview_min_dimensions' => '',
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);
    $settings = $this->getSettings();

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
        '%limit' => ByteSizeMarkup::create(Environment::getUploadMaxSize()),
      ]),
      '#size' => 10,
      '#element_validate' => [[static::class, 'validateMaxFilesize']],
    ];

    $element['preview_max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preview maximum upload size'),
      '#default_value' => $settings['preview_max_filesize'],
      '#description' => $this->t('Enter a value like "512" (bytes), "80 KB" (kilobytes) or "50 MB" (megabytes) in order to restrict the allowed file size. If left empty the file sizes will be limited only by PHP\'s maximum post and file upload sizes (current limit <strong>%limit</strong>).', [
        '%limit' => ByteSizeMarkup::create(Environment::getUploadMaxSize()),
      ]),
      '#size' => 10,
      '#element_validate' => [[static::class, 'validatePreviewMaxFilesize']],
    ];

    $element['preview_min_dimensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preview minimum dimensions'),
      '#default_value' => $settings['preview_min_dimensions'],
      '#description' => $this->t('Enter a value in the form WIDHTxHEIGHT (pixels), ex: 700x100.'),
      '#size' => 10,
      '#element_validate' => [[static::class, 'validatePreviewMinDimensions']],
    ];

    return $element;
  }

  /**
   * Form API callback.
   *
   * This doubles as a convenience clean-up function and a validation routine.
   * Commas are allowed for the end-user, but ultimately the value will be
   * stored as a space-separated list for compatibility with
   * the FileExtensions upload validator.
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
      $form_state->setError($element, t('The "@name" option must contain a valid value. You may either leave the text field empty or enter a string like "512" (bytes), "80 KB" (kilobytes) or "50 MB" (megabytes).', [
        '@name' => $element['#title'],
      ]));
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
  public static function validatePreviewMaxFilesize(array $element, FormStateInterface $form_state) {
    $element['#value'] = trim($element['#value']);
    $form_state->setValue(['settings', 'preview_max_filesize'], $element['#value']);
    if (!empty($element['#value']) && !Bytes::validate($element['#value'])) {
      $form_state->setError($element, t('The "@name" option must contain a valid value. You may either leave the text field empty or enter a string like "512" (bytes), "80 KB" (kilobytes) or "50 MB" (megabytes).', [
        '@name' => $element['#title'],
      ]));
    }
  }

  /**
   * Form API callback.
   *
   * Validate the format of the preview minimum dimensions (WIDTHxHEIGHT).
   *
   * This function is assigned as an #element_validate callback in
   * fieldSettingsForm().
   *
   * @param array $element
   *   Form element to validate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function validatePreviewMinDimensions(array $element, FormStateInterface $form_state) {
    $element['#value'] = trim($element['#value']);
    $form_state->setValue(['settings', 'preview_min_dimensions'], $element['#value']);
    if (isset($element['#value']) && preg_match('/^(\d+x\d*)?$/', $element['#value']) !== 1) {
      $form_state->setError($element, t('The "@name" option must contain a valid value. You may either leave the text field empty or enter a value in the form WIDHTxHEIGHT (ex: 700x100).', [
        '@name' => $element['#title'],
      ]));
    }
  }

  /**
   * Retrieves the upload validators for a file field.
   *
   * @param ?\Drupal\Core\Entity\EntityInterface $entity
   *   Entity the field item may be added to.
   * @param bool $in_form
   *   Flag to indicate whether we are validating inside a form or not.
   *
   * @return array
   *   An array suitable for passing to file_save_upload() or the file field
   *   element's '#upload_validators' property.
   */
  public function getUploadValidators(?EntityInterface $entity, bool $in_form = TRUE) {
    $validators = [];

    // Validate the extension, limiting to the current one if defined, to
    // ensure a file can be replaced only by a file of the same type.
    $extensions = $this->getAllowedFileExtensions();
    if (!empty($extensions)) {
      $validators['FileExtension'] = ['extensions' => implode(' ', $extensions)];
    }

    // Validate the file mime as well if defined, to  ensure a file can be
    // replaced only by a file of the same type.
    $file_mime = $this->getFileMime();
    if (!empty($file_mime)) {
      $validators['ReliefWebFileMimeType'] = ['mimetype' => $file_mime];
    }

    // There is always a file size limit due to the PHP server limit.
    $validators['FileSizeLimit'] = ['fileLimit' => $this->getMaxFileSize()];

    // Validate the filename length.
    $validators['FileNameLength'] = [];

    // Validate the filename.
    $validators['ReliefWebFileName'] = [];

    // Validate the real mime type.
    $validators['ReliefWebFileRealMimeType'] = [];

    // Validate the hash (duplication).
    $validators['ReliefWebFileHash'] = [
      'fieldItem' => $this,
      'entity' => $entity,
      'inForm' => $in_form,
    ];

    return $validators;
  }

  /**
   * Retrieves the upload validators for a preview file.
   *
   * @return array
   *   An array suitable for passing to file_save_upload() or the file field
   *   element's '#upload_validators' property.
   */
  public function getPreviewUploadValidators() {
    // We only allow PNG images to be consistent with the automatically
    // generated previews.
    $validators = [
      'FileExtension' => ['extensions' => 'png'],
      'ReliefWebFileMimeType' => ['mimetype' => 'image/png'],
      'FileNameLength' => [],
      'FileIsImage' => [],
      'FileSizeLimit' => ['fileLimit' => $this->getPreviewMaxFileSize()],
      'FileImageDimensions' => [
        'maxDimensions' => '',
        'minDimensions' => $this->getPreviewMinDimensions(),
      ],
    ];

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
   * Get the preview upload max file size.
   *
   * @return int
   *   The max file size in bytes.
   */
  public function getPreviewMaxFileSize() {
    $settings = $this->getSettings();

    $max_filesize = Bytes::toNumber(Environment::getUploadMaxSize());
    if (!empty($settings['preview_max_filesize'])) {
      $max_filesize = min($max_filesize, Bytes::toNumber($settings['preview_max_filesize']));
    }

    return $max_filesize;
  }

  /**
   * Get the preview minimum dimensions.
   *
   * @return string
   *   Minimum dimensions in the form WIDTHxHEIGHT.
   */
  public function getPreviewMinDimensions() {
    $settings = $this->getSettings();
    return $settings['preview_min_dimensions'] ?? '';
  }

  /**
   * Get the allowed file extensions for validation and description.
   *
   * @return array
   *   Allowed file extensions.
   */
  public function getAllowedFileExtensions() {
    $settings = $this->getSettings();

    $extension = $this->getFileExtension();
    if (!empty($extension)) {
      return [$extension];
    }
    elseif (!empty($settings['file_extensions'])) {
      return explode(' ', $settings['file_extensions']);
    }
    return [];
  }

  /**
   * Get the description with the allowed extensions and file size.
   *
   * @param ?array $extensions
   *   Extension list override. Only the extensions present in the default
   *   allowed extension list will be used.
   * @param ?int $max_file_size
   *   Max file size override. The minimum between the override and the default
   *   max file size will be used.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The upload description.
   */
  public function getUploadDescription(?array $extensions = NULL, ?int $max_file_size = NULL) {
    $default_extensions = $this->getAllowedFileExtensions();
    if (!empty($extensions) && !empty($default_extensions)) {
      $extensions = array_intersect($default_extensions, $extensions);
    }
    else {
      $extensions = $default_extensions;
    }

    $default_max_file_size = $this->getMaxFileSize();
    if (!empty($max_file_size) && $max_file_size > 0) {
      $max_file_size = min($default_max_file_size, $max_file_size);
    }
    else {
      $max_file_size = $default_max_file_size;
    }

    return $this->t('Allowed extensions: %extensions. Max file size: %max_filesize.', [
      '%extensions' => !empty($extensions) ? implode(', ', $extensions) : $this->t('any'),
      '%max_filesize' => ByteSizeMarkup::create($max_file_size),
    ]);
  }

  /**
   * Get the description with the allowed extensions and file size.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The upload description.
   */
  public function getPreviewUploadDescription() {
    return $this->t('PNG only. Max file size: %max_filesize. Minimum dimensions: %dimensions', [
      '%extension' => 'png',
      '%max_filesize' => ByteSizeMarkup::create($this->getPreviewMaxFileSize()),
      '%dimensions' => $this->getPreviewMinDimensions() ?: $this->t('none'),
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
      ->setLabel(new TranslatableMarkup('UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the file resource.'))
      ->setRequired(TRUE);

    // The ID of the file revision.
    // When "remote" is selected in the settngs, this is ID of the resource
    // revision.
    // When "local" is selected in the settings, this is ID of the permanent
    // local file.
    // It's set to 0 for content being created.
    $properties['revision_id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Revision ID'))
      ->setDescription(new TranslatableMarkup('The ID of the file revision.'))
      ->setRequired(TRUE);

    // The UUID of the local file associated with the field item.
    // When "remote" is selected in the settngs, this is the UUID of the
    // temporary file used to store the file content to generate the preview.
    // When "local" is selected in the settings, this is the UUID of the
    // permanent file associated with the field item.
    $properties['file_uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('File UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the attachment file.'))
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

    // The SHA-256 hash of the file.
    $properties['file_hash'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('File Hash'))
      ->setDescription(new TranslatableMarkup('The file hash.'))
      ->setRequired(FALSE);

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
          'size' => 'normal',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'file_uuid' => [
          'type' => 'varchar_ascii',
          'length' => 36,
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
        'file_hash' => [
          'type' => 'varchar_ascii',
          'length' => 64,
          'not null' => FALSE,
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
        'file_uuid' => ['file_uuid'],
        'file_hash' => ['file_hash'],
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
    $values['file_name'] = $random->string(mt_rand(1, 250)) . '.' . $random->string(mt_rand(1, 4));
    $values['file_mime'] = $random->string(mt_rand(32, 128));
    $values['file_size'] = mt_rand(1111, 99999);
    $values['file_hash'] = $random->string(64);
    $values['language'] = ['en', 'fr', 'sp'][mt_rand(0, 2)];
    $values['description'] = $random->sentences(mt_rand(1, 5));
    $values['page_count'] = mt_rand(1, 100);
    $values['preview_uuid'] = static::generateUuid();
    $values['preview_page'] = mt_rand(1, 100);
    $values['preview_rotation'] = mt_rand(0, 2);

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $uuid = $this->getUuid();
    return empty($uuid);
  }

  /**
   * Check if we are storing the files locally or remotely.
   *
   * @return bool
   *   TRUE if the files are stored locally.
   */
  public function storeLocally() {
    return $this->getDocstoreConfig()->get('local') === TRUE;
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
    $info = @getimagesize($uri);
    if ($info === FALSE) {
      return [];
    }

    return [
      '#theme' => 'responsive_image__preview',
      '#responsive_image_style_id' => $style,
      '#uri' => $uri,
      '#attributes' => [
        'alt' => $this->t('Preview of @file_name', [
          '@file_name' => $this->getFileName(),
        ]),
        'class' => ['rw-file-preview'],
        'data-version' => implode('-', [
          // Once the file is saved it will have a revision ID, until then to
          // ensure the preview image is not cached, we use its file ID.
          $this->getRevisionId() ?: $preview->id(),
          $this->getPreviewPage(),
          $this->getPreviewRotation(),
        ]),
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
    // For file that cannot have a preview automatically generated, try
    // to return the attached preview if any.
    if (!$this->canHaveGeneratedPreview()) {
      return $this->loadPreviewFile();
    }

    $page = $this->getPreviewPage();
    $rotation = $this->getPreviewRotation();

    // Skip if the file is not supposed to have a preview.
    if ($page < 1) {
      return NULL;
    }

    // Migrated content don't have a page count initially. We can use that to
    // detect if the preview should be regenerated.
    if (!$this->hasPageCount()) {
      return $this->generatePreview($page, $rotation, TRUE);
    }

    // Load the existing preview file or generate it if instructed so.
    $file = $this->loadPreviewFile();
    if (empty($file) && $generate) {
      return $this->generatePreview($page, $rotation);
    }
    return $file;
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
   * @param bool $new
   *   TRUE to force the creation of a new preview file.
   *
   * @return \Drupal\file\Entity\File|null
   *   The preview file entity or NULL if the preview was not generated.
   */
  public function generatePreview($page = 0, $rotation = 0, $regenerate = FALSE, $new = FALSE) {
    if ($page < 1 || $this->isEmpty() || !$this->canHaveGeneratedPreview() || $this->getPageCount() === 0) {
      return NULL;
    }

    $preview_uuid = $this->getPreviewUuid();
    $current_page = $this->getPreviewPage();
    $current_rotation = $this->getPreviewRotation();

    // Migrated content don't have a page count. We can use that to detect if
    // we should regenerate the preview.
    $no_page_count = !$this->hasPageCount();
    $regenerate = $regenerate || $no_page_count;

    // Skip if the preview already exists for the given page and rotation and
    // the preview file exists.
    if (!$regenerate && !empty($preview_uuid) && $page == $current_page && $rotation == $current_rotation) {
      $preview_file = $this->loadPreviewFile();
      if (!empty($preview_file)) {
        return $preview_file;
      }
    }

    // Load or create a file associated with the field item.
    $file = $this->loadFile();
    $downloaded = FALSE;
    if (empty($file) || !file_exists($file->getFileUri())) {
      // For local files, we cannot generate the preview if there is no file
      // associated with the field item.
      if ($this->storeLocally()) {
        return NULL;
      }
      // For remote files, we try to retrieve the file content and create a
      // temporary local file.
      else {
        $file = $file ?? $this->createFile();
        if (!$this->updateFileFromRemote($file)) {
          return NULL;
        }
        $downloaded = TRUE;
      }
    }

    // Update the page count if it's not set (i.e. for migrated content).
    if ($no_page_count) {
      $this->updatePageCount($file);
    }

    // Retrieve or create the preview file.
    $preview_file = $new ? NULL : $this->loadPreviewFile();
    if (empty($preview_file)) {
      $preview_file = $this->createPreviewFile();
    }

    // Ensure the page is a valid value.
    $page = $page > $this->getPageCount() ? 1 : $page;

    // Ensure the rotation is a valid value.
    $rotation = in_array($rotation, [0, 90, -90]) ? $rotation : 0;

    // Create the preview.
    $success = $this->extractPreview($file->getFileUri(), $preview_file->getFileUri(), $page, $rotation);
    if ($success) {
      // Save the preview. We mark it as temporary so that it can be garbage
      // collected if the form, for example, is not submitted. It will be saved
      // as permanent in ::preSave() when the form is submitted.
      if ($preview_file->isNew()) {
        $preview_file->setTemporary();
        $preview_file->save();
      }

      // Update the field item preview uuid as it may be a new file.
      $this->get('preview_uuid')->setValue($preview_file->uuid());
      $this->get('preview_page')->setValue($page);
      $this->get('preview_rotation')->setValue($rotation);

      // Delete the preview derivatives to ensure they correspond to the updated
      // preview image.
      $this->deletePreviewDerivatives($preview_file->getFileUri());
    }

    // Make sure we don't leave a local file if stored remotely.
    if ($downloaded) {
      $this->deleteFileOnDisk($file->getFileUri());
    }

    return $success ? $preview_file : NULL;
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
      $options = \Drupal::state()->get('mutool_options', '');
      $command = "{$mutool} draw {$options} -R {$rotation} -o {$destination} {$source} {$page}";
      exec($command, $output, $return_val);
      // @todo log error?
      return empty($return_val) && @file_exists($destination_uri);
    }
    return FALSE;
  }

  /**
   * Extract text content from the given PDF file.
   *
   * @param ?int $page
   *   Specific page to extract text from (if not provided extracts all pages).
   *
   * @return string
   *   The extracted text content or empty string in case of failure.
   */
  public function extractText(?int $page = NULL): string {
    $file = $this->loadFile();
    return isset($file) ? FileHelper::extractText($file, $page) : '';
  }

  /**
   * Regenerate the preview.
   *
   * @return bool
   *   TRUE if the preview generation was successful.
   */
  public function regeneratePrevew() {
    $page = $this->getPreviewPage();
    $rotation = $this->getPreviewRotation();
    return $this->generatePreview($page, $rotation, TRUE);
  }

  /**
   * Check if a preview can be automatically generated for this field item.
   *
   * @return bool
   *   TRUE if a preview can be generated.
   */
  public function canHaveGeneratedPreview() {
    return $this->getFileMime() === 'application/pdf';
  }

  /**
   * Delete the field item file.
   *
   * Note: this doesn't remove the remote file.
   */
  public function deleteFile() {
    $file = $this->loadFile();
    if (!empty($file)) {
      $uri = $file->getFileUri();
      $this->deleteFileOnDisk($uri);
      $file->delete();
    }
  }

  /**
   * Delete the preview file and its derivative images.
   */
  public function deletePreview() {
    $file = $this->loadPreviewFile();
    if (!empty($file)) {
      $uri = $file->getFileUri();
      $this->deleteFileOnDisk($uri);
      $this->deletePreviewDerivatives($uri);
      $file->delete();
    }
  }

  /**
   * Delete the derivative images that might have been created for the preview.
   *
   * @param string $uri
   *   Preview image URI.
   */
  public function deletePreviewDerivatives($uri) {
    image_path_flush($uri);
  }

  /**
   * Load the file associated with the field item.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity or NULL if it couldn't be loaded.
   */
  public function loadFile() {
    return $this->loadFileByUuid($this->getFileUuid());
  }

  /**
   * Load the preview file associated with the field item.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity or NULL if it couldn't be loaded.
   */
  public function loadPreviewFile() {
    return $this->loadFileByUuid($this->getPreviewUuid());
  }

  /**
   * Get the file URL.
   *
   * @return \Drupal\Core\Url|null
   *   URL object or NULL if we couldn't generate the URL or the current user
   *   doesn't have access to it.
   */
  public function getFileUrl() {
    $file = $this->loadFile();
    if (empty($file)) {
      return NULL;
    }

    $uri = $file->getFileUri();

    // Retrieve the file URI scheme so we can generate the appropriate URL.
    $scheme = StreamWrapperManager::getScheme($uri);
    $private = $scheme === 'private';

    if ($scheme === FALSE) {
      return NULL;
    }
    // Only people with access to the private files can have a link to the file.
    elseif ($private) {
      $can_access_private = FALSE;
      if ($this->getCurrentUser()->hasPermission('access reliefweb private files')) {
        $can_access_private = TRUE;
      }
      elseif ($this->getCurrentUser()->hasPermission('access own reliefweb private files')) {
        $can_access_private = $this->getParent()?->getEntity()?->getOwnerId() == $this->getCurrentUser()->id();
      }

      if ($can_access_private) {
        $url = UrlHelper::getAbsoluteFileUri($uri);
        return empty($url) ? NULL : Url::fromUri($url);
      }
      return NULL;
    }
    // New or replaced files have an empty revision id and there should be a
    // file on disk for them. However we need to check for the page count to
    // distinguish between those and initially migrated content.
    elseif (empty($this->getRevisionId()) && $this->hasPageCount()) {
      $url = UrlHelper::getAbsoluteFileUri($uri);
      return empty($url) ? NULL : Url::fromUri($url);
    }
    // For existing files, they should be accessible via the permanent URL.
    elseif ($uri === $this->getPermanentUri($private)) {
      $url = 'base:/';
      $url .= $private ? 'private/' : '';
      $url .= static::getFileDirectory() . '/';
      $url .= $this->getUuid() . '/' . rawurldecode($this->getFileName());

      return Url::fromUri($url);
    }

    return NULL;
  }

  /**
   * Check if the file is private.
   *
   * @return bool
   *   TRUE if teh file is private.
   */
  public function isPrivate() {
    $file = $this->loadFile();
    if (empty($file)) {
      return TRUE;
    }

    $scheme = StreamWrapperManager::getScheme($file->getFileUri());
    return $scheme === FALSE || $scheme === 'private';
  }

  /**
   * Create a managed file for the preview.
   *
   * @param string $new_uuid
   *   Whether to create a new UUID or re-use the existing one if any.
   *
   * @return \Drupal\file\Entity\File
   *   Managed file.
   */
  public function createPreviewFile($new_uuid = TRUE) {
    if ($this->isEmpty()) {
      return NULL;
    }

    // The preview is PNG image.
    $extension = 'png';
    $file_name = $this->getUuid() . '.' . $extension;
    $file_mime = 'image/' . $extension;

    // Generate a UUID for the preview. We'll store it in this field item only
    // if the preview generation worked.
    $preview_uuid = $this->getPreviewUuid();
    if ($new_uuid || empty($preview_uuid)) {
      $preview_uuid = $this->generateUuid();
    }

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
  public function createFile() {
    if ($this->isEmpty()) {
      return NULL;
    }

    $file_uuid = $this->getFileUuid();
    $file_name = $this->getFileName();
    $file_mime = $this->getFileMime();

    if (empty($file_uuid)) {
      $file_uuid = static::generateUuid();
      $this->get('file_uuid')->setValue($file_uuid);
    }

    // Generate the file uri.
    $extension = static::extractFileExtension($file_name);
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
    $uuid = $this->getUuid();
    $revision_id = $this->getRevisionId();
    $uri = $file->getFileUri();

    // Create the private temp directory to store the file.
    if (!static::prepareDirectory($uri)) {
      // @todo log error?
      return FALSE;
    }

    // Save the docstore file content.
    return $this->getDocstoreClient()
      ->downloadFileContentToFilePath($uuid, $uri, $revision_id);
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
   * @param bool $private
   *   Whether to use the private URI or the public one.
   * @param bool $preview
   *   Whether the URI is for the preview file or the local file.
   *
   * @return string
   *   URI.
   */
  public function getPermanentUri($private = FALSE, $preview = FALSE) {
    $uuid = $this->getUuid();
    $extension = $preview ? 'png' : $this->getFileExtension();
    return static::getFileUriFromUuid($uuid, $extension, $private, $preview);
  }

  /**
   * Get the file directory from the module settings.
   *
   * @param bool $preview
   *   Whether the directory is for a preview or an attachment.
   *
   * @return string
   *   The directory.
   */
  public static function getFileDirectory($preview = FALSE) {
    $settings = \Drupal::service('config.factory')->get('reliefweb_files.settings');
    if ($preview) {
      return $settings->get('preview_directory') ?? 'previews';
    }
    else {
      return $settings->get('file_directory') ?? 'attachments';
    }
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
    $directory = $private ? 'private://' : 'public://';
    $directory .= static::getFileDirectory($preview);
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
   * @return string
   *   The destination URI.
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
    return $file_system->move($source, $destination, $file_system::EXISTS_REPLACE);
  }

  /**
   * Extract the basename of the file.
   *
   * @param string $file_name
   *   File name.
   *
   * @return string
   *   File base name.
   */
  public static function extractFileBasename($file_name) {
    if (empty($file_name)) {
      return '';
    }
    $position = mb_strrpos($file_name, '.');
    return $position > 0 ? mb_substr($file_name, 0, $position) : '';
  }

  /**
   * Extract the extension of the file.
   *
   * @param string $file_name
   *   File name.
   * @param bool $normalize
   *   If TRUE the extension will be returned lower case.
   *
   * @return string
   *   File extension in lower case.
   */
  public static function extractFileExtension($file_name, $normalize = TRUE) {
    if (empty($file_name)) {
      return '';
    }
    $position = mb_strrpos($file_name, '.');
    $extension = $position > 0 ? mb_substr($file_name, $position + 1) : '';
    return $normalize ? mb_strtolower($extension) : $extension;
  }

  /**
   * Check if a file name is valid.
   *
   * @param string $file_name
   *   File name.
   * @param string $expected_extension
   *   The expected extension for the file.
   * @param int|null $maxlength
   *   Max file name length.
   * @param int|null $minlength
   *   Min file name length.
   *
   * @return string
   *   Error message if invalid, empty string otherwise.
   */
  public static function validateFileName($file_name, $expected_extension = '', $maxlength = 255, $minlength = NULL) {
    // No need to continue if the filename is empty.
    if ($file_name === '') {
      return t('Empty file name.');
    }

    // Validate the extension then the file name.
    $extension = static::extractFileExtension($file_name);
    if (empty($extension)) {
      return t('Missing file extension.');
    }
    // Check if the extension matches the expected one.
    elseif (!empty($expected_extension) && $extension !== $expected_extension) {
      return t('Wrong extension, expected: @expected_extension.', [
        '@expected_extension' => $expected_extension,
      ]);
    }

    // Validate the file name length.
    $file_name_length = strlen($file_name);
    $maxlength = $maxlength ?: $file_name_length;
    $minlength = $minlength ?: strlen($extension) + 2;
    if ($file_name_length > $maxlength) {
      return t('File name too long. Maximum: @length characters.', [
        '@length' => $maxlength,
      ]);
    }
    elseif ($file_name_length < $minlength) {
      return t('File name too short. Minimum: @length characters.', [
        '@length' => $minlength,
      ]);
    }

    // Check if the file name contains invalid characters.
    if (preg_match('#^[^' . static::getFileNameInvalidCharacters() . ']+$#u', $file_name) !== 1) {
      return t('Invalid characters in file name.');
    }
    return '';
  }

  /**
   * Get the characters that are invalid in file names.
   *
   * @param bool $visible_only
   *   If TRUE, return only the invalid visible characters.
   *
   * @return string
   *   Invalid characters.
   */
  public static function getFileNameInvalidCharacters($visible_only = FALSE) {
    // Reserved by file system: `<>:"/\|?*`.
    // Control characters: `\x00-\x1F`.
    // Non-printing (del, no-break space, soft hyphen): `\x7F\xA0\xAD`.
    // @see https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
    // @see http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
    static $invalid_characters = [
      'visible' => '<>:"/\\\|?*',
      'control' => '\\x00-\\x1F',
      'non-printing' => '\\x7F\\xA0\\xAD',
    ];
    return $visible_only ? $invalid_characters['visible'] : implode('', $invalid_characters);
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
  public static function guessFileMimeType($uri) {
    if (empty($uri)) {
      return '';
    }
    return \Drupal::service('file.mime_type.guesser')->guessMimeType($uri);
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
   * Check if the file can have an automatically generated preview.
   *
   * @param \Drupal\file\Entity\File|null $file
   *   Managed File.
   *
   * @return bool
   *   TRUE if the file preview can be generated.
   */
  public static function fileCanHaveGeneratedPreview(?File $file) {
    if (empty($file)) {
      return FALSE;
    }
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
    if (!static::fileCanHaveGeneratedPreview($file)) {
      return 0;
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
   * @param bool $preview
   *   Whether to move the field item file or the preview file.
   *
   * @return string
   *   URI.
   */
  public static function getFileUuidUri(File $file, $private = TRUE, $preview = FALSE) {
    $extension = static::extractFileExtension($file->getFileName());
    return static::getFileUriFromUuid($file->uuid(), $extension, $private, $preview);
  }

  /**
   * Get the permanent UUID.
   *
   * @return string
   *   Permanent UUID.
   */
  public function getUuid() {
    return $this->get('uuid')->getValue();
  }

  /**
   * Get the revision ID.
   *
   * @return int
   *   Revision ID.
   */
  public function getRevisionId() {
    return $this->get('revision_id')->getValue();
  }

  /**
   * Get the file UUID.
   *
   * @return string
   *   File UUID.
   */
  public function getFileUuid() {
    return $this->get('file_uuid')->getValue();
  }

  /**
   * Get the file name.
   *
   * @return string
   *   File name.
   */
  public function getFileName() {
    return $this->get('file_name')->getValue();
  }

  /**
   * Get the file name as uploaded.
   *
   * @return string
   *   File name.
   */
  public function getUploadedFileName() {
    $file = $this->loadFile();
    return !empty($file) ? $file->getFileName() : '';
  }

  /**
   * Get the file basename.
   *
   * @return string
   *   File extension.
   */
  public function getFileBasename() {
    return static::extractFileBasename($this->getFileName());
  }

  /**
   * Get the file extension.
   *
   * @return string
   *   File extension.
   */
  public function getFileExtension() {
    return static::extractFileExtension($this->getFileName());
  }

  /**
   * Get the file mime type.
   *
   * @return string
   *   File mime type.
   */
  public function getFileMime() {
    return $this->get('file_mime')->getValue();
  }

  /**
   * Get the file size.
   *
   * @return int
   *   File size in bytes.
   */
  public function getFileSize() {
    return $this->get('file_size')->getValue();
  }

  /**
   * Get the file hash.
   *
   * @return string
   *   File hash.
   */
  public function getFileHash() {
    return $this->get('file_hash')->getValue();
  }

  /**
   * Get the file language.
   *
   * @return string
   *   File language.
   */
  public function getFileLanguage() {
    return $this->get('language')->getValue();
  }

  /**
   * Get the description.
   *
   * @return string
   *   File description.
   */
  public function getFileDescription() {
    return trim($this->get('description')->getValue() ?? '');
  }

  /**
   * Get the preview file UUID.
   *
   * @return string
   *   Preview file UUID.
   */
  public function getPreviewUuid() {
    return $this->get('preview_uuid')->getValue();
  }

  /**
   * Get the preview page.
   *
   * @return int
   *   Preview page.
   */
  public function getPreviewPage() {
    return $this->get('preview_page')->getValue();
  }

  /**
   * Get the preview rotation.
   *
   * @return int
   *   Preview rotation.
   */
  public function getPreviewRotation() {
    return $this->get('preview_rotation')->getValue();
  }

  /**
   * Get the number of pages for this field item file.
   *
   * @return int
   *   Number of pages. Returns 0 if the number of pages is irrelevant.
   */
  public function getPageCount() {
    if ($this->canHaveGeneratedPreview()) {
      return $this->get('page_count')->getValue() ?? 1;
    }
    return 0;
  }

  /**
   * Check if there is a page count for the field item.
   *
   * Note: during the initial migration of the content, the page count is set
   * to NULL. This allow us to distinguish them from items created via the
   * form that should have a page count of 0.
   *
   * @return bool
   *   TRUE if the field item has a page count.
   */
  public function hasPageCount() {
    return !is_null($this->get('page_count')->getValue());
  }

  /**
   * Update the page count for the field item.
   *
   * Note: this is normally only called once to update migrated file records
   * with the page count when the preview (if any) is regenerated.
   *
   * @param \Drupal\file\Entity\File $file
   *   File.
   */
  protected function updatePageCount(File $file) {
    if ($this->hasPageCount()) {
      return;
    }
    $page_count = static::getFilePageCount($file);
    $this->get('page_count')->setValue($page_count);

    $entity = $this->getEntity();
    $entity_id = $entity->id();
    $revision_id = $entity->getRevisionId();
    $entity_type_id = $entity->getEntityTypeId();
    $uuid = $this->getUuid();
    $file_uuid = $this->getFileUuid();

    if (empty($entity_id) || empty($revision_id) || empty($uuid) || empty($file_uuid)) {
      return;
    }

    $field_name = $this->getFieldDefinition()->getName();
    $table = $this->getFieldTableName($entity_type_id, $field_name);
    $revision_table = $this->getFieldRevisionTableName($entity_type_id, $field_name);
    $uuid_field = $this->getFieldColumnName($entity_type_id, $field_name, 'uuid');
    $file_uuid_field = $this->getFieldColumnName($entity_type_id, $field_name, 'file_uuid');
    $page_count_field = $this->getFieldColumnName($entity_type_id, $field_name, 'page_count');

    // Try to update the DB records.
    try {
      $this->getDatabase()
        ->update($table)
        ->fields([$page_count_field => $page_count])
        ->condition('entity_id', $entity_id, '=')
        ->condition('revision_id', $revision_id, '=')
        ->condition($uuid_field, $uuid, '=')
        ->condition($file_uuid_field, $file_uuid, '=')
        ->execute();

      $this->getDatabase()
        ->update($revision_table)
        ->fields([$page_count_field => $page_count])
        ->condition('entity_id', $entity_id, '=')
        ->condition('revision_id', $revision_id, '=')
        ->condition($uuid_field, $uuid, '=')
        ->condition($file_uuid_field, $file_uuid, '=')
        ->execute();

      // We need to clear the cache so that the page count change is reflected.
      Cache::invalidateTags($entity->getCacheTagsToInvalidate());
      $this->getEntityTypeStorage($entity_type_id)->resetCache([$entity_id]);
    }
    catch (\Exception $exception) {
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $uuid = $this->getUuid();

    // Move the files to their private location so that they are not accessible
    // anymore.
    $this->updateFileStatus(TRUE);

    // Try to delete the remote file. If it's used by another provider, then
    // the request will fail but that's the expected behavior.
    if (!$this->storeLocally() && !empty($uuid)) {
      $this->getDocstoreClient()->deleteFile($uuid);
    }

    // Delete the file and preview.
    $this->deleteFile();
    $this->deletePreview();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRevision() {
    $uuid = $this->getUuid();
    $revision_id = $this->getRevisionId();

    // Try to delete the remote revision. If it's used by another provider, then
    // the request will fail but that's the expected behavior.
    if (!$this->storeLocally() && !empty($uuid) && !empty($revision_id)) {
      $this->getDocstoreClient()->deleteFileRevision($uuid, $revision_id);
    }

    // Delete the file and preview.
    $this->deleteFile();
    $this->deletePreview();
  }

  /**
   * Update the file and preview of the item that is going to be removed.
   */
  public function updateRemovedItem() {
    try {
      // Move the field item file to its UUID URI so that it's not possible to
      // directly access the file anymore with the permanent URI.
      $this->moveFileToUuidUri();
      // Delete the preview and its derivatives as they should not be accessible
      // anymore either.
      $this->deletePreview();
      // Update the file status to private to restrict further access to the
      // file.
      $this->updateFileStatus(TRUE);
    }
    // @todo log the error and see what to tell the user.
    catch (\Exception $exception) {
      throw $exception;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(?ReliefWebFile $original_item = NULL) {
    // Try to update the file if it's new, has been replaced (different file
    // UUID or its revision is empty and we are in remote mode. The latter could
    // occur if creating the remote file didn't work or when switching from
    // local to remote mode after resetting the revision IDs.
    $update_file = empty($original_item) ||
      $original_item->getFileUuid() !== $this->getFileUuid() ||
      (empty($this->getRevisionId()) && !$this->storeLocally());

    $update_preview = empty($original_item) ||
      $original_item->getPreviewUuid() !== $this->getPreviewUuid() ||
      $original_item->getRevisionId() !== $this->getRevisionId();

    try {
      if ($update_file) {
        // Archive the old file.
        if (!empty($original_item)) {
          $original_item->archiveFile();
        }

        // Update the file hash with the one from the new file.
        $this->updateFileHash();

        // Update the field item file.
        if ($this->storeLocally()) {
          $this->updateLocalFile();
        }
        else {
          $this->updateRemoteFile();
        }
      }

      if ($update_preview) {
        // Archive the old preview file.
        if (!empty($original_item)) {
          $original_item->archivePreviewFile();
        }

        // Update the preview if any.
        $this->updatePreviewFile();
      }

      // Ensure we have a file hash.
      $hash = $this->getFileHash();
      if (empty($hash)) {
        $this->updateFileHash();
      }

      // Ensure that the file is saved as permanent.
      $this->ensureFilesArePermanent();
    }
    // @todo log the error and see what to tell the user.
    catch (\Exception $exception) {
      // @todo inject the messenger service to display the message.
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
      $this->updateFileStatus(!$entity->isPublished());
    }

    // Mark the file revision as active for us.
    // @todo do something if that fails?
    if (!$this->storeLocally()) {
      $this->getDocstoreClient()->selectFileRevision($this->getUuid(), $this->getRevisionId());
    }

    return parent::postSave($updated);
  }

  /**
   * Update the file item's file hash.
   *
   * @return ?string
   *   The new file hash.
   */
  public function updateFileHash(): ?string {
    $file = $this->loadFile();
    if (empty($file)) {
      throw new \Exception('Unable to load the new local file to update the hash.');
    }

    $hash = $this->calculateFileHash($file);
    $this->setFileHash($hash);
    return $hash;
  }

  /**
   * Set the file hash.
   *
   * @param ?string $hash
   *   The file hash.
   *
   * @return $this
   *   This file item.
   */
  public function setFileHash(?string $hash): static {
    $this->get('file_hash')->setValue($hash);
    return $this;
  }

  /**
   * Calculate the hash of a file.
   *
   * @param \Drupal\file\Entity\File $file
   *   File.
   *
   * @return ?string
   *   File's content hash.
   */
  public function calculateFileHash(File $file): ?string {
    return FileHelper::generateFileHash($file, file_system: $this->getFileSystem());
  }

  /**
   * Replace a local file.
   *
   * @throws \Exception
   *   Exception if the file couldn't be replaced.
   */
  protected function updateLocalFile() {
    // Move the new file to the permanent URI.
    $file = $this->loadFile();
    // @todo better error message.
    if (empty($file)) {
      throw new \Exception('Unable to load the new local file');
    }
    $this->changeFileLocation($file, $this->getPermanentUri(TRUE, FALSE));

    // Update the revision id with the file ID to distinguish from new items.
    $this->get('revision_id')->setValue($file->id());
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
      throw new \Exception('Unable to load the local file.');
    }

    $client = $this->getDocstoreClient();
    $uuid = $this->getUuid();

    // Check if there is already a file with the revision ID in the docstore.
    // If that's the case, then we don't have anything special to do.
    $revision_id = $this->getRevisionId();
    if (!empty($revision_id)) {
      $response = $client->getFileRevision($uuid, $revision_id);

      // We cannot revert if the file doesn't exist in the docstore anymore or
      // something went wrong like denied access.
      if (!$response->isSuccessful()) {
        throw new \Exception('The remote file revision doesn\'t exist anymore.');
      }
    }
    // Check if the remote file exists and create one if it doesn't then
    // update its content.
    else {
      // Check if the remote file exists.
      $response = $client->getFile($uuid);

      // Create it if necessary.
      if ($response->isNotFound()) {
        // Create the file resource in the docstore.
        $response = $client->createFile([
          'uuid' => $uuid,
          'filename' => $this->getFileName(),
          'mimetype' => $this->getFileMime(),
          // Mark the new file as private initially. We'll change it that after
          // the entity the field is attached to is properly saved.
          'private' => TRUE,
        ]);

        if (!$response->isSuccessful()) {
          throw new \Exception('Unable to create remote file resource');
        }
      }
      // Abort if the file couldn't be retrieved as we cannot update it.
      elseif (!$response->isSuccessful()) {
        throw new \Exception('Unable to retrieve remote file resource');
      }

      // Upload the file content.
      $response = $client->updateFileContentFromFilePath($uuid, $file->getFileUri());
      if (!$response->isSuccessful()) {
        throw new \Exception('Unable to update remote file content');
      }

      // Get the new file revision id.
      $content = $response->getContent();
      if (empty($content) || !isset($content['revision_id'])) {
        throw new \Exception('Invalid revison id after updating file content');
      }

      // Store the new revision returned by the docstore.
      $this->get('revision_id')->setValue($content['revision_id']);
    }

    // Delete the local file if any.
    $this->deleteFileOnDisk($file->getFileUri());

    // Change the URI of the field item file to its permanent URI.
    $this->changeFileLocation($file, $this->getPermanentUri(TRUE, FALSE));
  }

  /**
   * Update the preview file.
   *
   * @throws \Exception
   *   Exception if the preview file couldn't be updated.
   */
  protected function updatePreviewFile() {
    // If "no preview" was selected, delete the preview.
    $preview_page = $this->getPreviewPage();
    if (empty($preview_page)) {
      $this->deletePreview();
    }

    // Get the preview (generate it if necessary).
    $file = $this->getPreview(TRUE);
    if (empty($file)) {
      return;
    }

    // Move the preview to its permanent location.
    $this->changeFileLocation($file, $this->getPermanentUri(TRUE, TRUE));
  }

  /**
   * Make sure the files are permanent.
   */
  protected function ensureFilesArePermanent() {
    $file = $this->loadFile();
    if (!empty($file)) {
      if ($file->isTemporary()) {
        $file->setPermanent();
        $file->save();
      }

      // Ensure there is no local file left if we are storing remotely unless
      // the revision ID is empty which would indicate something went wrong
      // when trying to create or update the remote file.
      if (!$this->storeLocally() && !empty($this->getRevisionId())) {
        $this->deleteFileOnDisk($file->getFileUri());
      }
    }

    $preview_file = $this->loadPreviewFile();
    if (!empty($preview_file)) {
      if ($preview_file->isTemporary()) {
        $preview_file->setPermanent();
        $preview_file->save();
      }
    }
  }

  /**
   * Update the status of the file associated with this field item.
   *
   * @param bool $private
   *   TRUE if the file should be made private.
   */
  public function updateFileStatus($private) {
    // Swap the file location from private to public or vice versa.
    $this->swapFileLocation($this->loadFile(), $private);

    // For remote files, mark the file resource as private or public.
    if (!$this->storeLocally()) {
      // @todo log any error.
      $this->getDocstoreClient()->updateFileStatus($this->getUuid(), $private);
    }

    // Update the preview file as well.
    $preview_file = $this->loadPreviewFile();
    if (!empty($preview_file)) {
      $this->deletePreviewDerivatives($preview_file->getFileUri());
      $this->swapFileLocation($preview_file, $private);
    }
  }

  /**
   * Move the field item or preview file to its private file UUID URI.
   *
   * @param bool $preview
   *   Whether to move the field item file or the preview file.
   *
   * @throws \Exception
   *   Exception if the file couldn't me moved.
   */
  protected function moveFileToUuidUri($preview = FALSE) {
    $file = $preview ? $this->loadPreviewFile() : $this->loadFile();
    // @todo better error message.
    if (empty($file)) {
      throw new \Exception('Unable to load file to move to UUID URI');
    }
    $this->changeFileLocation($file, static::getFileUuidUri($file, TRUE, $preview));
  }

  /**
   * Move the file to its file UUID URI.
   *
   * @throws \Exception
   *   Exception if the file couldn't me moved.
   */
  protected function archiveFile() {
    $file = $this->loadFile();
    if (!empty($file)) {
      $this->moveFileToUuidUri();
      // Make the file private as well.
      $this->swapFileLocation($file, TRUE);
    }
  }

  /**
   * Move the preview file to its file UUID URI and remove derivatives.
   *
   * @throws \Exception
   *   Exception if the file couldn't be moved.
   */
  protected function archivePreviewFile() {
    // Delete the old preview if it's generated. We don't need to keep it.
    // It will be recreated when reverting if that ever happens.
    if ($this->canHaveGeneratedPreview()) {
      $this->deletePreview();
    }
    // Otherwise attempt to move the preview file to its UUID URI.
    else {
      $file = $this->loadPreviewFile();
      if (!empty($file)) {
        $this->deletePreviewDerivatives($file->getFileUri());
        $this->moveFileToUuidUri(TRUE);
        // Make the file private as well.
        $this->swapFileLocation($file, TRUE);
      }
    }
  }

  /**
   * Swap the location of a local file.
   *
   * @param \Drupal\file\Entity\File|null $file
   *   File entity.
   * @param bool $private
   *   Wether to make the file private or not.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   Exception if the file couldn't be moved.
   *
   * @todo do something about the exception.
   */
  protected function swapFileLocation(?File $file, $private) {
    if (empty($file)) {
      return;
    }

    $source = $file->getFileUri();
    $target = StreamWrapperManager::getTarget($source);
    if ($target === FALSE) {
      return;
    }

    // Move the file if the URI has changed.
    $destination = ($private ? 'private' : 'public') . '://' . $target;
    if ($source !== $destination) {
      $this->changeFileLocation($file, $destination);
    }
  }

  /**
   * Change the location of a file.
   *
   * Note: the file doesn't need to exists on disk.
   *
   * @param \Drupal\file\Entity\File|null $file
   *   File entity.
   * @param string $destination
   *   The destination URI.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   Exception if the file couldn't be moved.
   *
   * @todo do something about the exception.
   */
  protected function changeFileLocation(?File $file, $destination) {
    if (empty($file)) {
      return;
    }

    $source = $file->getFileUri();
    if ($source !== $destination && file_exists($source)) {
      static::moveFile($source, $destination);
    }

    $file->setFileUri($destination);
    $file->setPermanent();
    $file->save();
  }

  /**
   * Delete a file on disk.
   *
   * @param string $uri
   *   URI of the file on disk.
   *
   * @return bool
   *   TRUE if the file could be deleted.
   */
  protected function deleteFileOnDisk($uri) {
    if (!empty($uri) && file_exists($uri)) {
      return $this->getFileSystem()->unlink($uri);
    }
    return FALSE;
  }

  /**
   * Get the current user.
   *
   * @return \Drupal\Core\Session\AccountProxyInterface
   *   Current user.
   */
  protected function getCurrentUser() {
    if (!isset($this->currentUser)) {
      $this->currentUser = \Drupal::currentUser();
    }
    return $this->currentUser;
  }

  /**
   * Get the docstore client service.
   *
   * @return \Drupal\reliefweb_files\Services\DocstoreClient
   *   The docstore client service.
   */
  protected function getDocstoreClient() {
    if (!isset($this->docstoreClient)) {
      $this->docstoreClient = \Drupal::service('reliefweb_files.client');
    }
    return $this->docstoreClient;
  }

  /**
   * Get the reliefweb docstore config.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Docstore config.
   */
  protected function getDocstoreConfig() {
    if (!isset($this->docstoreConfig)) {
      $this->docstoreConfig = \Drupal::config('reliefweb_files.settings');
    }
    return $this->docstoreConfig;
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

}
