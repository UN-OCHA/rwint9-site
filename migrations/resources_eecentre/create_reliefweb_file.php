<?php

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Symfony\Component\Uid\Uuid;

/**
 * Set the value of an entity's ReliefWeb file field.
 *
 * @param \Drupal\Core\Entity\ContentEntityInterface $entity
 *   The entity to which set the field.
 * @param string $field_name
 *   Field name.
 * @param array $files
 *   List of file data with URL and optional description and language.
 */
function set_reliefweb_file_field(ContentEntityInterface $entity, string $field_name, array $files): array {
  static $mimetypes;
  $mapping = [];

  if (!$entity->hasField($field_name)) {
    return $mapping;
  }

  if (empty($files)) {
    return $mapping;
  }

  /** @var \Drupal\Core\Field\FieldItemListInterface $field **/
  $field = $entity->get($field_name);
  $definition = $field->getItemDefinition();

  // This is the list of extensions supported by the report attachment field.
  if (!isset($mimetypes)) {
    $mime_type_guesser = \Drupal::service('file.mime_type.guesser');
    $extensions = explode(' ', 'csv doc docx jpg jpeg odp ods odt pdf png pps ppt pptx svg xls xlsx zip');
    $mimetypes = array_filter(array_map(fn($extension) => $mime_type_guesser->guessMimeType('dummy.' . $extension), $extensions));
  }

  // This is the max size supported for the report attachments.
  $max_size = '40MB';

  // Map the existing attached files from their field item UUID to their
  // file UUID so that we can determine if they need to be updated.
  $existing = [];
  foreach ($field as $item) {
    $existing[$item->getUuid()] = $item;
  }

  // Process the attachments.
  $values = [];
  foreach ($files as $file) {
    if (!isset($file['url'])) {
      continue;
    }

    $url = $file['url'];
    $file_name = $file['filename'];
    $uuid = generate_uuid($url, $entity->uuid());
    $file_uuid = generate_uuid($uuid, $entity->uuid());

    try {
      // Nothing to do if the file didn't change.
      if (isset($existing[$uuid]) && $existing[$uuid]->getFileUuid() === $file_uuid) {
        $item = $existing[$uuid];
      }
      // If the file has changed or didn't exist, then download it.
      else {
        // We use the file name to guess the mimetype not the URL because it
        // may not have an extension.
        $mimetype = guess_file_mime_type($file_name, $mimetypes);
        $item = create_reliefWeb_file_field_item($definition, $file_uuid, $file_name, $url, $mimetype, $max_size);
      }

      // Update the file description and language.
      $item->get('description')->setValue($file['description'] ?? '');
      $item->get('language')->setValue($file['language'] ?? '');

      $mapping[$url] = \Drupal::service('file_url_generator')->generateAbsoluteString($item->getPermanentUri());
      $values[] = $item->getValue();
    }
    catch (\Exception $exception) {
      \Drupal::logger('migration_resources_eecentre')->error($exception->getMessage());
    }
  }

  // Relace the field values.
  $field->setValue($values);

  return $mapping;
}

/**
 * Get the mime of the file.
 *
 * @param string $path
 *   File path or URI.
 * @param array $allowed_mimetypes
 *   List of allowed mimetypes. An empty list means any mime type is accepted.
 *
 * @return string
 *   File mime type.
 *
 * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
 *   Exception if the mimetype could be guessed or is not allowed.
 */
function guess_file_mime_type(string $path, array $allowed_mimetypes = []): string {
  $mimetype = \Drupal::service('file.mime_type.guesser')->guessMimeType($path);
  if (empty($mimetype) || (!empty($allowed_mimetypes) && !in_array($mimetype, $allowed_mimetypes))) {
    throw new \Exception(strtr('Unsupported @mimetype mimetype for @path.', [
      '@mimetype' => $mimetype ?? 'unknown',
      '@path' => $path,
    ]));
  }
  return $mimetype;
}

/**
 * Create a ReliefWeb file field item from a remote file.
 *
 * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
 *   Field item definition.
 * @param string $uuid
 *   File UUID.
 * @param string $file_name
 *   File name.
 * @param string $url
 *   Remote file URL.
 * @param string $mimetype
 *   Accepted mimetype.
 * @param string $max_size
 *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
 *
 * @return \Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile|null
 *   ReliefWeb file field item.
 */
function create_reliefWeb_file_field_item(
  DataDefinitionInterface $definition,
  string $uuid,
  string $file_name,
  string $url,
  string $mimetype,
  string $max_size = '',
): ?ReliefWebFile {
  // Create a new field item.
  $item = ReliefWebFile::createInstance($definition);

  // Generate a private URI for the file. It will be changed to public
  // when the entity the file is attached to is published.
  $extension = ReliefWebFile::extractFileExtension($file_name);
  $file_uri = ReliefWebFile::getFileUriFromUuid($uuid, $extension, TRUE);

  // Retrieve the upload validators to validate the created file as if
  // uploaded via the form.
  $validators = $item->getUploadValidators() ?? [];
  foreach ($validators as $validator => &$info) {
    if ($validator == 'FileSizeLimit') {
      $info = '40000000';
    }
  }

  // Create the file entity with the content.
  $file = create_file($uuid, $file_uri, $file_name, $mimetype, $url, $max_size, $validators);

  // Set the properties of the ReliefWeb file field item so it's fully
  // constructed and can be added to the field item list.
  $item->setValue([
    // Derive the UUID from the remote file URL so we can identify it, for
    // example when receiving an update.
    'uuid' => $uuid,
    // A revision of 0 is an easy way to determine new files.
    // This will be populated after a successful upload for remote files or
    // when saving the local file as permanent.
    'revision_id' => 0,
    'file_uuid' => $file->uuid(),
    'file_name' => $file->getFilename(),
    'file_mime' => $file->getMimeType(),
    'file_size' => $file->getSize(),
    'page_count' => ReliefWebFile::getFilePageCount($file),
  ]);

  // Validate the field item.
  $file_system = \Drupal::service('file_system');
  $violations = $item->validate();
  if ($violations->count() > 0) {
    foreach ($violations as $violation) {
      \Drupal::logger('migration_resources_eecentre')->error('Field item violation at %property_path for file %name : @message', [
        '%property_path' => $violation->getPropertyPath(),
        '%name' => $file->getFilename(),
        '@message' => $violation->getMessage(),
      ]);
    }

    // Remove the uploaded file. There is no need to remove the file entity
    // as it hasn't been saved to the database yet.
    $file_system->unlink($file->getFileUri());

    throw new \Exception(strtr('Invalid field item data for the uploaded file @url.', [
      '@url' => $url,
    ]));
  }

  // Save the file as a temporary file. It will saved as permanent when the
  // entity is saved.
  $file->setTemporary();
  $file->save();

  // Attempt to generate the preview.
  $item->generatePreview(1, 0);

  return $item;
}

/**
 * Create and validate a file.
 *
 * @param string $uuid
 *   The file UUID.
 * @param string $uri
 *   The destination URI where the file will be saved.
 * @param string $name
 *   The file name.
 * @param string $mimetype
 *   The file mimetype.
 * @param string $url
 *   The remote file URL.
 * @param string $max_size
 *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
 * @param array $validators
 *   The file validators.
 *
 * @return \Drupal\file\FileInterface|null
 *   The created file entity or NULL if there was no retrievable content.
 *
 * @throws \Exception
 *   An exception of the file could not be saved.
 */
function create_file(string $uuid, string $uri, string $name, string $mimetype, string $url, string $max_size, array $validators = []): ?FileInterface {

  // Attempt to load the file if already exists.
  $file = \Drupal::service('entity.repository')->loadEntityByUuid('file', $uuid);

  if (empty($file)) {
    // Skip if we cannot retrieve the new image.
    $content = get_remote_file_content($url, $mimetype, $max_size);
    if (empty($content)) {
      return NULL;
    }

    // Create a temporary managed file entity.
    $file = \Drupal::entityTypeManager()->getStorage('file')->create([
      'uuid' => $uuid,
      'langcode' => 'en',
      // We use the System user as owner of the file as those are used for
      // global files that have nothing to do with the current user.
      'uid' => 2,
      'uri' => $uri,
      // Temporary file that can be garbage collected if not set permanent.
      'status' => 0,
      'filename' => $name,
      'filemime' => $mimetype,
    ]);

    // Set the file size.
    $file->setSize(strlen($content) ?? 0);

    // Create the directory to store the file.
    $file_system = \Drupal::service('file_system');
    $directory = $file_system->dirname($uri);
    if (!$file_system->prepareDirectory($directory, $file_system::CREATE_DIRECTORY)) {
      throw new \Exception(strtr('Unable to create the destination directory for the file @name.', [
        '@name' => $name,
      ]));
    }

    // Move the uploaded file.
    if (!$file_system->saveData($content, $uri)) {
      throw new \Exception(strtr('Unable to copy the file @name.', [
        '@name' => $name,
      ]));
    }

    // Validate the file (file name length, file size etc.).
    $errors = validate_file($file, $validators);

    // Bail out if the uploaded file is invalid.
    if (!empty($errors)) {
      $file_system->unlink($file->getFileUri());

      throw new \Exception(strtr('Invalid file @name. @errors', [
        '@name' => $name,
        '@errors' => implode('; ', $errors),
      ]));
    }
  }

  return $file;
}

/**
 * Get the content of remote file.
 *
 * @param string $url
 *   Remote file URL.
 * @param string $mimetype
 *   Accepted mimetype.
 * @param string $max_size
 *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
 *
 * @return string
 *   Downloaded content.
 */
function get_remote_file_content(string $url, string $mimetype, string $max_size = ''): string {
  $content = '';
  $max_size = !empty($max_size) ? Bytes::toNumber($max_size) : Environment::getUploadMaxSize();

  try {
    $response = \Drupal::httpClient()->get($url, [
      'stream' => TRUE,
      // @todo retrieve that from the configuration.
      'connect_timeout' => 30,
      'timeout' => 600,
      'headers' => [
        'Accept' => $mimetype,
      ],
    ]);

    // Validate file size.
    if ($max_size > 0 && $response->getHeaderLine('Content-Length') > $max_size) {
      throw new \Exception('File is too large.');
    }

    $body = $response->getBody();

    // Read in the body in chunks so that we can check the actual size.
    if ($max_size > 0) {
      $size = 0;
      while (!$body->eof()) {
        $chunk = $body->read(1024);
        $size += strlen($chunk);
        if ($size > $max_size) {
          $body->close();
          throw new \Exception('File is too large.');
        }
        else {
          $content .= $chunk;
        }
      }
    }
    else {
      $content = $body->getContents();
    }
  }
  catch (\Exception $exception) {
    throw $exception;
  }
  finally {
    if (isset($body)) {
      $body->close();
    }
  }

  return $content;
}

/**
 * Validate a file against a list of validators.
 *
 * @param \Drupal\file\Entity\File $file
 *   File to validate.
 * @param array $validators
 *   Associative array of upload validators with their ID as key and
 *   expected parameters as values.
 *
 * @return array
 *   List of validation error messages if any.
 */
function validate_file(File $file, array $validators = []): array {
  if (empty($validators)) {
    return [];
  }

  /** @var \Symfony\Component\Validator\ConstraintViolationListInterface $violations */
  $violations = \Drupal::service('file.validator')->validate($file, $validators);

  $errors = [];
  foreach ($violations as $violation) {
    $errors[] = $violation->getMessage();
  }

  return $errors;
}

/**
 * Generate UUID.
 */
function generate_uuid(string $string, ?string $namespace = NULL) {
  $namespace = $namespace ?? '8e27a998-c362-5d1f-b152-d474e1d36af2';
  return Uuid::v5(Uuid::fromString($namespace), $string)->toRfc4122();
}

