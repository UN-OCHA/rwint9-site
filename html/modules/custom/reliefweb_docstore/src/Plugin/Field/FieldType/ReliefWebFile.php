<?php

namespace Drupal\reliefweb_docstore\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
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
  public static function mainPropertyName() {
    return 'uuid';
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // The UUID of the docstore resource.
    $properties['uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('File UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the docstore resource.'))
      ->setRequired(TRUE);

    // The ID of the file revision.
    $properties['revision_id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('File revision ID'))
      ->setDescription(new TranslatableMarkup('The ID of the file revision.'))
      ->setRequired(TRUE);

    // The status of the file: private or public.
    $properties['status'] = DataDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Whether the file is private or public.'))
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
    $properties['preview_uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Preview UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the preview file.'))
      ->setRequired(TRUE);

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
        'status' => [
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
          'not null' => TRUE,
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
    $valyes['status'] = (bool) mt_rand(0, 1);
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
    $preview_uuid = $this->get('preview_uuid')->getValue();
    if (!empty($preview_uuid)) {
      return $this->loadFileByUuid($preview_uuid);
    }
    elseif ($generate && $this->canHavePreview()) {
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

    $uuid = $this->get('uuid')->getValue();

    // Check if there is temporary file for the file item.
    $file = $this->loadFileByUuid($uuid);
    // Create the temporary file if doesn't exist.
    if (empty($file)) {
      $file = $this->createFile();
    }

    // Retrieve the file from the docstore.
    if (!@file_exists($file->getFileUri()) && !$this->updateFile($file)) {
      return NULL;
    }

    // Retrieve the preview file.
    $preview_file = NULL;
    if (!empty($preview_uuid)) {
      $preview_file = $this->loadFileByUuid($preview_uuid);
    }
    // Create the preview file if doesn't exist.
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
   * Get the file URL.
   *
   * @return \Drupal\Core\Url
   *   URL object.
   */
  public function getFileUrl() {
    $uuid = $this->get('uuid')->getValue();
    $file_name = $this->get('file_name')->getValue();
    $extension = static::getFileExtension($file_name);
    $revision_id = $this->get('revision_id')->getValue();

    // If the revision ID is not set, then it's a new file and there should be
    // an associated managed file and we return its URL.
    if (empty($revision_id)) {
      $url = file_create_url(static::getFileUriFromUuid($uuid, $extension, TRUE, FALSE));
    }
    // Otherwise use a "direct" link to the file.
    // @todo this doesn't handle the case were the file is private in the
    // docstore. We need a different route for that.
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

    // Generate the preview URI as private initially. We'll move it to public
    // if the docstore file is made public.
    $uri = static::getFileUriFromUuid($uuid, $extension, TRUE, TRUE);

    // Generate a UUID for the preview. We'll store it in this field item only
    // if the preview generation worked.
    $preview_uuid = $this->get('preview_uuid')->getValue();
    if (empty($preview_uuid)) {
      $preview_uuid = $this->generateUuid();
    }

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

    $uuid = $this->get('uuid')->getValue();
    $file_name = $this->get('file_name')->getValue();
    $file_mime = $this->get('file_mime')->getValue();

    // Generate the file uri.
    $extension = static::getFileExtension($file_name);
    $uri = static::getFileUriFromUuid($uuid, $extension, TRUE, FALSE);

    // Create a temporary managed file with the System user.
    return static::createFileFromUuid($uuid, $uri, $file_name, $file_mime);
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
  protected function updateFile(File $file) {
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
      // temporary it will garbage collected as some point by the system.
      $file->setMimeType($this->getFileMimeType($uri));
      $file->save();
    }
    return $success;
  }

  /**
   * Load a file entity by its uuid.
   *
   * @param string $uuid
   *   File entity UUID.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity or NULL if it couldn't be loaded.
   */
  protected function loadFileByUuid($uuid) {
    return $this->getEntityRepository()->loadEntityByUuid('file', $uuid);
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
    // @todo use a config setting for the attachments/previews path.
    $directory = $private ? 'private://' : 'public://';
    $directory .= $preview ? 'previews/' : 'attachments/';
    $directory .= substr($uuid, 0, 2);
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
   * Get the extension of the file.
   *
   * @param string $file_name
   *   File name.
   *
   * @return string
   *   File extension.
   */
  public static function getFileExtension($file_name) {
    return pathinfo($file_name, PATHINFO_EXTENSION);
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

}
