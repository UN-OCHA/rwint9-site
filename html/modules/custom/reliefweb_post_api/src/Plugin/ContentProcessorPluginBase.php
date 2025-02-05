<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase as CorePluginBase;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\Validation\FileValidatorInterface;
use Drupal\media\MediaInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use Drupal\reliefweb_post_api\Helpers\UrlHelper;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use GuzzleHttp\ClientInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Base content processor plugin.
 */
abstract class ContentProcessorPluginBase extends CorePluginBase implements ContainerFactoryPluginInterface, ContentProcessorPluginInterface {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The schema validator.
   *
   * @var \Opis\JsonSchema\Validator
   */
  protected Validator $schemaValidator;

  /**
   * The JSON schema for the content handled by this plugin.
   *
   * @var string
   */
  protected string $jsonSchema;

  /**
   * Static cache for the providers.
   *
   * @var array
   */
  protected array $providers = [];

  /**
   * Plugin settings.
   *
   * @var array
   */
  protected array $settings = [];

  /**
   * Constructs a \Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $pathResolver
   *   The path resolver service.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\file\Validation\FileValidatorInterface $fileValidator
   *   The file validator.
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $mimeTypeGuesser
   *   The file mimetype guesser.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected Connection $database,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ExtensionPathResolver $pathResolver,
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
    protected FileValidatorInterface $fileValidator,
    protected MimeTypeGuesserInterface $mimeTypeGuesser,
    protected LanguageManagerInterface $languageManager,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('extension.path.resolver'),
      $container->get('http_client'),
      $container->get('file_system'),
      $container->get('file.validator'),
      $container->get('file.mime_type.guesser'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginLabel(): MarkupInterface|string {
    $definition = $this->getPluginDefinition();
    return $definition['label'] ?? $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType(): string {
    return $this->getPluginDefinition()['entityType'];
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle(): string {
    return $this->getPluginDefinition()['bundle'];
  }

  /**
   * {@inheritdoc}
   */
  public function getResource(): string {
    return $this->getPluginDefinition()['resource'];
  }

  /**
   * {@inheritdoc}
   */
  public function getLogger(): LoggerInterface {
    if (!isset($this->logger)) {
      $this->logger = $this->loggerFactory->get($this->getPluginId());
    }
    return $this->logger;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaValidator(): Validator {
    if (!isset($this->schemaValidator)) {
      $this->schemaValidator = new Validator();
      $this->schemaValidator->setMaxErrors(5);
    }
    return $this->schemaValidator;
  }

  /**
   * {@inheritdoc}
   */
  public function getJsonSchema(): string {
    if (!isset($this->jsonSchema)) {
      $bundle = $this->getbundle();
      $path = $this->pathResolver->getPath('module', 'reliefweb_post_api');
      $schema = @file_get_contents($path . '/schemas/v2/' . $bundle . '.json');
      if ($schema === FALSE) {
        throw new ContentProcessorException(strtr('Missing @bundle JSON schema.', [
          '@bundle' => $bundle,
        ]));
      }
      $this->jsonSchema = $schema;
    }
    return $this->jsonSchema;
  }

  /**
   * {@inheritdoc}
   */
  public function getProvider(string $uuid): ProviderInterface {
    if (!Uuid::isValid($uuid)) {
      throw new ContentProcessorException('Invalid provider UUID.');
    }
    if (array_key_exists($uuid, $this->providers)) {
      $provider = $this->providers[$uuid];
    }
    else {
      $provider = $this->entityRepository->loadEntityByUuid('reliefweb_post_api_provider', $uuid);
      $this->providers[$uuid] = $provider;
    }
    if (is_null($provider)) {
      throw new ContentProcessorException('Invalid provider.');
    }
    elseif (empty($provider->status->value)) {
      throw new ContentProcessorException('Blocked provider.');
    }
    return $provider;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function process(array $data): ?ContentEntityInterface;

  /**
   * {@inheritdoc}
   */
  public function isProcessable(string $uuid): bool {
    $storage = $this->entityTypeManager->getStorage($this->getEntityType());
    $uuid_key = $storage->getEntityType()->getKey('uuid');

    // Check if the entity is marked as refused, in which case it cannot be
    // processed.
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition($uuid_key, $uuid, '=')
      ->condition('moderation_status', 'refused', '=')
      ->execute();

    return empty($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $data): void {
    $this->validateSchema($data);
    $this->validateUuid($data);
    $this->validateSources($data);
    $this->validateUrls($data);
    $this->validateFiles($data);
  }

  /**
   * {@inheritdoc}
   */
  public function validateSchema(array $data): void {
    unset($data['bundle']);
    unset($data['provider']);
    unset($data['user']);
    $data = Helper::toJSON($data);
    $schema = $this->getPluginSetting('schema', $this->getJsonSchema());
    $result = $this->getSchemaValidator()->validate($data, $schema);
    if (!$result->isValid()) {
      $formatter = new ErrorFormatter();
      $errors = $formatter->formatKeyed($result->error());
      $message = json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      throw new ContentProcessorException($message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateUuid(array $data): void {
    if (empty($data['url'])) {
      throw new ContentProcessorException('Missing document URL.');
    }
    elseif (empty($data['uuid'])) {
      throw new ContentProcessorException('Missing document UUID.');
    }
    elseif (!Uuid::isValid($data['uuid'])) {
      throw new ContentProcessorException('Invalid document UUID.');
    }
    // @todo if we want to allow providers to edit existing ReliefWeb content
    // then we cannot do this comparison because the UUID is not generated this
    // way and is not derived from the document URL.
    elseif ($this->generateUuid($data['url']) !== $data['uuid']) {
      throw new ContentProcessorException('The UUID does not match the one generated from the URL.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateSources(array $data): void {
    $provider = $this->getProvider($data['provider'] ?? '');
    $sources = $provider->getAllowedSources() ?? [];
    // Empty allowed sources means any source is allowed.
    // @todo review the logic here or in the UI because currently the source
    // field is mandatory.
    if (empty($sources)) {
      return;
    }

    // @todo for existing documents we may want to check that the document
    // source is among the allowed sources.
    // Check if any of the given sources is not in the list of allowed ones.
    if (empty($data['source']) || count(array_diff($data['source'], $sources)) > 0) {
      throw new ContentProcessorException('Unallowed source(s)');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateUrls(array $data): void {
    $provider = $this->getProvider($data['provider'] ?? '');

    $document_pattern = $provider->getUrlPattern('document');
    if (empty($data['url'])) {
      throw new ContentProcessorException('Missing document URL.');
    }
    elseif (!$this->validateUrl($data['url'], $document_pattern)) {
      throw new ContentProcessorException('Unallowed document URL: ' . $data['url']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateUrl(string $url, string $pattern): bool {
    // An empty pattern means any URL is ok.
    return empty($pattern) || preg_match($pattern, $url) === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function validateFiles(array $data): void {
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeTerms(string $vocabulary, array $terms): array {
    if (empty($terms)) {
      return [];
    }

    $ids = $this->database
      ->select('taxonomy_term_field_data', 'td')
      ->fields('td', ['tid'])
      ->condition('td.vid', $vocabulary, '=')
      ->condition('td.tid', $terms, 'IN')
      ->execute()
      ?->fetchAllKeyed(0, 0) ?? [];

    // Preserve the order of the given term ids.
    $result = [];
    foreach ($terms as $id) {
      if (isset($ids[$id])) {
        $result[$id] = $id;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeString(string $string): string {
    return TextHelper::cleanText($string, [
      'line_breaks' => TRUE,
      'consecutive' => TRUE,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeText(string $text, int $max_heading_level = 2): string {
    // Clean the text, removing notably control characters and trimming it.
    $text = TextHelper::cleanText($text);
    if (empty($text)) {
      return '';
    }

    // We assume the input is in markdow as recommended in the specificiations.
    // We convert it to HTML and sanitize the output to remove any unsupported
    // HTML markup.
    $html = HtmlSanitizer::sanitizeFromMarkdown($text, FALSE, $max_heading_level - 1);

    // Remove embedded content.
    $html = TextHelper::stripEmbeddedContent($html);

    // Finally we convert the HTML to markdown which is our storage format.
    $converter = new HtmlConverter();
    $converter->getConfig()->setOption('strip_tags', TRUE);
    $converter->getConfig()->setOption('use_autolinks', FALSE);
    $converter->getConfig()->setOption('header_style', 'atx');
    $converter->getConfig()->setOption('strip_placeholder_links', TRUE);
    $converter->getConfig()->setOption('italic_style', '*');
    $converter->getConfig()->setOption('bold_style', '**');

    $text = trim($converter->convert($html));

    return $text;
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeDate(string $date, bool $strip_time = TRUE): string {
    if (empty($date)) {
      return '';
    }
    $timezone = timezone_open('UTC');
    $date = date_create($date, $timezone);
    if (empty($date)) {
      return '';
    }
    $format = $strip_time ? 'Y-m-d' : 'Y-m-d\TH:i:s';
    // Convert to UTC and format.
    return $date->setTimezone($timezone)->format($format);
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeUrl(string $url, string $pattern): string {
    if (empty($url)) {
      return '';
    }
    return $this->validateUrl($url, $pattern) ? $url : '';
  }

  /**
   * {@inheritdoc}
   */
  public function setField(ContentEntityInterface $entity, string $field_name, mixed $value): void {
    if ($entity->hasField($field_name)) {
      $value = is_null($value) || is_array($value) ? $value : [$value];
      $entity->get($field_name)->setValue($value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setStringField(ContentEntityInterface $entity, string $field_name, string $string): void {
    $this->setField($entity, $field_name, $this->sanitizeString($string));
  }

  /**
   * {@inheritdoc}
   */
  public function setTextField(ContentEntityInterface $entity, string $field_name, string $text, int $max_heading_level = 2, string $format = ''): void {
    $value = $this->sanitizeText($text, $max_heading_level);
    if (!empty($format)) {
      $value = [
        'value' => $value,
        'format' => $format,
      ];
    }
    $this->setField($entity, $field_name, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function setDateField(ContentEntityInterface $entity, string $field_name, string $date, bool $strip_time = TRUE): void {
    $this->setField($entity, $field_name, $this->sanitizeDate($date, $strip_time) ?: NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function setTermField(ContentEntityInterface $entity, string $field_name, string $vocabulary, array $terms): void {
    $this->setField($entity, $field_name, $this->sanitizeTerms($vocabulary, $terms));
  }

  /**
   * {@inheritdoc}
   */
  public function setUrlField(ContentEntityInterface $entity, string $field_name, string $url, string $pattern): void {
    $this->setField($entity, $field_name, $this->sanitizeUrl($url, $pattern) ?: NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function setReliefWebFileField(ContentEntityInterface $entity, string $field_name, array $files): void {
    if (!$entity->hasField($field_name)) {
      return;
    }

    /** @var \Drupal\Core\Field\FieldItemListInterface $field **/
    $field = $entity->get($field_name);
    $definition = $field->getItemDefinition();

    $mimetypes = $this->getPluginSetting('attachments.allowed_mimetypes', ['application/pdf']);
    $max_size = $this->getPluginSetting('attachments.allowed_max_size', '20MB');

    // Map the existing attached files from their field item UUID to their
    // file UUID so that we can determine if they need to be updated.
    $existing = [];
    foreach ($field as $item) {
      $existing[$item->getUuid()] = $item;
    }

    // Process the attachments.
    $values = [];
    foreach ($files as $file) {
      if (!isset($file['url'], $file['checksum'])) {
        continue;
      }

      $url = $file['url'];
      $file_name = $file['filename'];
      $checksum = $file['checksum'];
      $uuid = $this->generateUuid($url, $entity->uuid());
      $file_uuid = $this->generateUuid($uuid . $checksum, $entity->uuid());

      try {
        // Nothing to do if the file didn't change.
        if (isset($existing[$uuid]) && $existing[$uuid]->getFileUuid() === $file_uuid) {
          $item = $existing[$uuid];
        }
        // If the file has changed or didn't exist, then download it.
        else {
          // We use the file name to guess the mimetype not the URL because it
          // may not have an extension.
          $mimetype = $this->guessFileMimeType($file_name, $mimetypes);
          $item = $this->createReliefWebFileFieldItem($definition, $file_uuid, $file_name, $url, $checksum, $mimetype, $max_size);
        }

        // Update the file description and language.
        $item->get('description')->setValue($file['description'] ?? '');
        $item->get('language')->setValue($file['language'] ?? '');

        $values[] = $item->getValue();
      }
      catch (\Exception $exception) {
        $this->getLogger()->error($exception->getMessage());
      }
    }

    // Relace the field values.
    $field->setValue($values);
  }

  /**
   * {@inheritdoc}
   */
  public function setImageField(ContentEntityInterface $entity, string $field_name, array $image): void {
    if (!$entity->hasField($field_name) || !isset($image['url'], $image['checksum'])) {
      return;
    }

    /** @var \Drupal\Core\Field\FieldItemListInterface $field **/
    $field = $entity->get($field_name);

    $url = $image['url'];
    $checksum = $image['checksum'];
    $uuid = $this->generateUuid($checksum . $url, $entity->uuid());

    // Attempt to load the media for the given image.
    $media = $this->entityRepository->loadEntityByUuid('media', $uuid);

    // If the image has changed, we'll create a new media for it.
    if (isset($media) && $field->first()?->entity?->uuid() !== $media->uuid()) {
      $media = NULL;
    }

    // Attempt to create a new media.
    if (!isset($media)) {
      $mimetypes = $this->getPluginSetting('images.allowed_mimetypes', ['image/jpeg', 'image/png', 'image/webp']);
      $max_size = $this->getPluginSetting('images.allowed_max_size', '5MB');

      try {
        $bundle = 'image_' . $entity->bundle();
        $mimetype = $this->guessFileMimeType($url, $mimetypes);
        $alt = $image['description'] ?? '';
        $media = $this->createImageMedia($bundle, $uuid, $url, $checksum, $mimetype, $max_size, $alt);
      }
      catch (\Exception $exception) {
        $this->getLogger()->error($exception->getMessage());
        $media = NULL;
      }
    }

    if (!empty($media)) {
      // Update the copyright and description.
      $this->setStringField($media, 'field_copyright', $image['copyright'] ?? '');
      $this->setStringField($media, 'field_description', $image['description'] ?? '');

      // Publish and save the media.
      $media->setPublished()->save();
      $field->setValue($media);
    }
    else {
      $field->setValue(NULL);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createImageMedia(string $bundle, string $uuid, string $url, string $checksum, string $mimetype, string $max_size, string $alt): ?MediaInterface {
    $file_info = pathinfo($url);
    $file_name = $file_info['basename'];
    $file_uuid = $this->generateUuid($uuid, $uuid);

    // Create a new media.
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $bundle,
      'uuid' => $uuid,
      'name' => $file_name,
      'langcode' => $this->getDefaultLangcode(),
      'status' => 0,
      // The media doesn't belong to a particular user so use the system user.
      'uid' => 2,
      'revision_user' => 2,
    ]);

    // Create an instance of the image field item.
    $item = $media->get('field_media_image')->appendItem();

    // Retrieve the directory and scheme from the media image field definition.
    $definition = $item->getFieldDefinition();
    $directory = $definition->getSetting('file_directory');
    $scheme = $definition->getFieldStorageDefinition()->getSetting('uri_scheme');

    // Generate the file URI based on its UUID.
    // @see reliefweb_utility_file_presave()
    $file_uri = implode('/', [
      $scheme . '://' . $directory,
      substr($file_uuid, 0, 2),
      substr($file_uuid, 2, 2),
      $file_uuid . '.' . strtolower($file_info['extension']),
    ]);

    // Retrieve the upload validators to validate the created file as if
    // uploaded via the form.
    $validators = $item->getUploadValidators() ?? [];

    // Create the file entity with the content.
    $file = $this->createFile($file_uuid, $file_uri, $file_name, $mimetype, $url, $checksum, $max_size, $validators);

    // Save the file permanently.
    $file->setPermanent();
    $file->save();

    // Populate the image field.
    [$width, $height] = @getimagesize($file->getFileUri());

    $item->setValue([
      'target_id' => $file->id(),
      'alt' => $alt,
      'title' => '',
      'width' => $width,
      'height' => $height,
    ]);

    return $media;
  }

  /**
   * {@inheritdoc}
   */
  public function createReliefWebFileFieldItem(DataDefinitionInterface $definition, string $uuid, string $file_name, string $url, string $checksum, string $mimetype, string $max_size = ''): ?ReliefWebFile {
    // Create a new field item.
    $item = ReliefWebFile::createInstance($definition);

    // Generate a private URI for the file. It will be changed to public
    // when the entity the file is attached to is published.
    $extension = ReliefWebFile::extractFileExtension($file_name);
    $file_uri = ReliefWebFile::getFileUriFromUuid($uuid, $extension, TRUE);

    // Retrieve the upload validators to validate the created file as if
    // uploaded via the form.
    $validators = $item->getUploadValidators() ?? [];

    // Create the file entity with the content.
    $file = $this->createFile($uuid, $file_uri, $file_name, $mimetype, $url, $checksum, $max_size, $validators);

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
    $violations = $item->validate();
    if ($violations->count() > 0) {
      foreach ($violations as $violation) {
        $this->getLogger()->error('Field item violation at %property_path for file %name : @message', [
          '%property_path' => $violation->getPropertyPath(),
          '%name' => $file->getFilename(),
          '@message' => $violation->getMessage(),
        ]);
      }

      // Remove the uploaded file. There is no need to remove the file entity
      // as it hasn't been saved to the database yet.
      $this->fileSystem->unlink($file->getFileUri());

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
   * {@inheritdoc}
   */
  public function createFile(string $uuid, string $uri, string $name, string $mimetype, string $url, string $checksum, string $max_size, array $validators = []): ?FileInterface {

    // Attempt to load the file if already exists.
    $file = $this->entityRepository->loadEntityByUuid('file', $uuid);

    if (empty($file)) {
      // Skip if we cannot retrieve the new image.
      $content = $this->getRemoteFileContent($url, $checksum, $mimetype, $max_size);
      if (empty($content)) {
        return NULL;
      }

      // Create a temporary managed file entity.
      $file = $this->entityTypeManager->getStorage('file')->create([
        'uuid' => $uuid,
        'langcode' => $this->getDefaultLangcode(),
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
      $directory = $this->fileSystem->dirname($uri);
      if (!$this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY)) {
        throw new \Exception(strtr('Unable to create the destination directory for the file @name.', [
          '@name' => $name,
        ]));
      }

      // Move the uploaded file.
      if (!$this->fileSystem->saveData($content, $uri)) {
        throw new \Exception(strtr('Unable to copy the file @name.', [
          '@name' => $name,
        ]));
      }

      // Validate the file (file name length, file size etc.).
      $errors = $this->validateFile($file, $validators);

      // Bail out if the uploaded file is invalid.
      if (!empty($errors)) {
        $this->fileSystem->unlink($file->getFileUri());

        throw new \Exception(strtr('Invalid file @name. @errors', [
          '@name' => $name,
          '@errors' => implode('; ', $errors),
        ]));
      }
    }

    return $file;
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteFileContent(string $url, string $checksum, string $mimetype, string $max_size = ''): string {
    $content = '';
    $max_size = !empty($max_size) ? Bytes::toNumber($max_size) : Environment::getUploadMaxSize();

    try {
      $response = $this->httpClient->get(UrlHelper::replaceBaseUrl($url), [
        'stream' => TRUE,
        // @todo retrieve that from the configuration.
        'connect_timeout' => 30,
        'timeout' => 600,
        'headers' => [
          'Accept' => $mimetype,
        ],
      ]);

      // Validate the content type.
      $validate_content_type = $this->getPluginSetting('validate_file_content_type', TRUE);
      $content_type = $response->getHeaderLine('Content-Type');
      if ($validate_content_type && $content_type !== $mimetype) {
        throw new \Exception(strtr('File type "@content_type" is not "@mimetype".', [
          '@mimetype' => $mimetype,
          '@content_type' => $content_type,
        ]));
      }

      // Validate file size.
      if ($max_size > 0 && $response->getHeaderLine('Content-Length') > $max_size) {
        throw new \Exception('File is too large.');
      }

      $body = $response->getBody();

      // Read in the body in chiunk so that we can check the actual size.
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

    if (hash('sha256', $content) !== $checksum) {
      throw new \Exception('Invalid file checksum.');
    }

    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function validateFile(File $file, array $validators = []): array {
    if (empty($validators)) {
      return [];
    }

    /** @var \Symfony\Component\Validator\ConstraintViolationListInterface $violations */
    $violations = $this->fileValidator->validate($file, $validators);

    $errors = [];
    foreach ($violations as $violation) {
      $errors[] = $violation->getMessage();
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function generateUuid(string $string, ?string $namespace = NULL): string {
    /* The default namespace is the UUID generated with
     * Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), 'reliefweb.int')->toRfc4122(); */
    $namespace = $namespace ?? '8e27a998-c362-5d1f-b152-d474e1d36af2';
    return Uuid::v5(Uuid::fromString($namespace), $string)->toRfc4122();
  }

  /**
   * {@inheritdoc}
   */
  public function guessFileMimeType(string $path, array $allowed_mimetypes = []): string {
    $mimetype = $this->mimeTypeGuesser->guessMimeType($path);
    if (empty($mimetype) || (!empty($allowed_mimetypes) && !in_array($mimetype, $allowed_mimetypes))) {
      throw new ContentProcessorException(strtr('Unsupported @mimetype mimetype for @path.', [
        '@mimetype' => $mimetype ?? 'unknown',
        '@path' => $path,
      ]));
    }
    return $mimetype;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultLangcode(): string {
    return $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * {@inheritdoc}
   */
  public function setPluginSetting(string $name, mixed $value): void {
    NestedArray::setValue($this->settings, explode('.', $name), $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginSetting(string $name, mixed $default = NULL): mixed {
    return NestedArray::getValue($this->settings, explode('.', $name)) ?? $default;
  }

}
