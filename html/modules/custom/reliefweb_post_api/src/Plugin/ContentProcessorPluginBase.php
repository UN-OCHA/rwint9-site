<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Serialization\Json;
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
use Drupal\reliefweb_files\Plugin\Validation\Constraint\ReliefWebFileHashConstraint;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use Drupal\reliefweb_post_api\Enum\ContentProcessorMessage;
use Drupal\reliefweb_post_api\Exception\DocumentNotFoundException;
use Drupal\reliefweb_post_api\Exception\DuplicateException;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
use Drupal\reliefweb_post_api\Helpers\UrlHelper;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use GuzzleHttp\ClientInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\JsonPointer;
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
   * Whether the current schema validation is a partial (PATCH) update.
   *
   * Set only for the duration of ::validateSchema() so the error formatter
   * can distinguish mandatory field clears from generic type errors.
   *
   * @var bool
   */
  protected bool $schemaValidationPartial = FALSE;

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
        throw new ContentProcessorException(ContentProcessorMessage::MissingBundleJsonSchema->format([
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
      throw new ContentProcessorException(ContentProcessorMessage::InvalidProviderUuid->value);
    }
    if (array_key_exists($uuid, $this->providers)) {
      $provider = $this->providers[$uuid];
    }
    else {
      $provider = $this->entityRepository->loadEntityByUuid('reliefweb_post_api_provider', $uuid);
      $this->providers[$uuid] = $provider;
    }
    if (is_null($provider)) {
      throw new ContentProcessorException(ContentProcessorMessage::InvalidProvider->value);
    }
    elseif (empty($provider->status->value)) {
      throw new ContentProcessorException(ContentProcessorMessage::BlockedProvider->value);
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
  public function save(ContentEntityInterface $entity, ProviderInterface $provider, array $data): int {
    $user_id = $data['user'] ?? $provider->getUserId();

    // Set the provider.
    $this->setField($entity, 'field_post_api_provider', $provider);

    // Store the hash of the Post API data.
    //
    // We allow providing the hash already to help with content importer that
    // may want to alter the content to hash.
    $hash = $data['hash'] ?? HashHelper::generateHash($data, ['provider', 'user']);
    $this->setField($entity, 'field_post_api_hash', $hash);

    // Set the moderation status.
    // - Explicit status (importers) wins.
    // - Creates use the provider default intake status.
    // - Updates re-enter the workflow as pending so posting rights re-run in
    //   preSave (trusted may return to published; others stay pending, etc.).
    $status = match (TRUE) {
      !empty($data['status']) => $data['status'],
      $entity->isNew() => $provider->getDefaultResourceStatus(),
      default => 'pending',
    };
    $entity->setModerationStatus($status);

    // Set the log message based on whether it was updated or created.
    // Importers may pass a richer log_message for partial reimports.
    $message = $data['log_message'] ?? match (TRUE) {
      $entity->isNew() => 'Automatic creation from Post API.',
      !empty($data['partial']) => 'Automatic partial update from Post API.',
      default => 'Automatic update from Post API.',
    };

    // Save the entity.
    $entity->setNewRevision(TRUE);
    $entity->setRevisionCreationTime(time());
    $entity->setRevisionUserId($user_id);
    if ($entity instanceof EntityRevisionedInterface) {
      $entity->updateRevisionLogMessage($message, 'replace', FALSE);
    }

    return $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function isProcessable(string $uuid): bool {
    $status = $this->getModerationStatusByUuid($uuid);
    if ($status === NULL) {
      return TRUE;
    }
    return !$this->isTerminalModerationStatus($status);
  }

  /**
   * {@inheritdoc}
   */
  public function getModerationStatusByUuid(string $uuid): ?string {
    $entity_type = $this->entityTypeManager->getDefinition($this->getEntityType());
    $base_table = $entity_type->getBaseTable();
    $data_table = $entity_type->getDataTable() ?? $base_table;
    $id_key = $entity_type->getKey('id');
    $uuid_key = $entity_type->getKey('uuid');

    $query = $this->database->select($data_table, 'data');
    $query->addField('data', 'moderation_status');
    if ($data_table !== $base_table) {
      $query->join($base_table, 'base', "base.{$id_key} = data.{$id_key}");
      $query->condition("base.{$uuid_key}", $uuid, '=');
    }
    else {
      $query->condition("data.{$uuid_key}", $uuid, '=');
    }
    $default_langcode_key = $entity_type->getKey('default_langcode');
    if ($default_langcode_key) {
      $query->condition('data.' . $default_langcode_key, 1);
    }
    $query->range(0, 1);

    $status = $query->execute()?->fetchField();
    return is_string($status) && $status !== '' ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isOwnedByProvider(string $uuid, ProviderInterface $provider): bool {
    $storage = $this->entityTypeManager->getStorage($this->getEntityType());
    $uuid_key = $storage->getEntityType()->getKey('uuid');

    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition($uuid_key, $uuid, '=')
      ->condition('field_post_api_provider', $provider->id(), '=')
      ->range(0, 1)
      ->execute();

    return !empty($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function isTerminalModerationStatus(string $status): bool {
    return in_array($status, $this->getTerminalModerationStatuses(), TRUE);
  }

  /**
   * Terminal moderation statuses that block further Post API processing.
   *
   * @return list<string>
   *   Status machine names.
   */
  protected function getTerminalModerationStatuses(): array {
    $service = ModerationServiceBase::getModerationService($this->getBundle());
    return $service ? $service->getTerminalStatuses() : [];
  }

  /**
   * Retired moderation statuses that skip Post API hash no-op.
   *
   * @return list<string>
   *   Status machine names.
   */
  protected function getRetiredModerationStatuses(): array {
    $service = ModerationServiceBase::getModerationService($this->getBundle());
    return $service ? $service->getRetiredStatuses() : [];
  }

  /**
   * Ensure an existing entity matches the plugin bundle.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity being processed.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   When the entity bundle does not match this plugin.
   */
  protected function validateEntityBundle(ContentEntityInterface $entity): void {
    if ($entity->isNew()) {
      return;
    }

    $bundle = $this->getBundle();
    if ($entity->bundle() !== $bundle) {
      throw new ContentProcessorException(ContentProcessorMessage::ExistingEntityWrongBundle->format([
        '@uuid' => $entity->uuid(),
        '@bundle' => $bundle,
      ]));
    }
  }

  /**
   * Ensure an existing entity is not in a terminal moderation status.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity being processed.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   When the entity is in a terminal moderation status.
   */
  protected function validateEntityProcessable(ContentEntityInterface $entity): void {
    if ($entity->isNew() || !($entity instanceof EntityModeratedInterface)) {
      return;
    }

    $status = $entity->getModerationStatus();
    if (in_array($status, $this->getTerminalModerationStatuses(), TRUE)) {
      throw new ContentProcessorException(ContentProcessorMessage::SkippingTerminalEntity->format([
        '@uuid' => $entity->uuid(),
        '@status' => $status,
      ]));
    }
  }

  /**
   * Resolve the document UUID for processing.
   *
   * Partial updates use the payload UUID. Full creates/updates derive it from
   * the document URL.
   *
   * @param array $data
   *   Post API data.
   *
   * @return string
   *   Document UUID.
   */
  protected function resolveDocumentUuid(array $data): string {
    if (!empty($data['partial'])) {
      return (string) ($data['uuid'] ?? '');
    }
    return $this->generateUuid((string) ($data['url'] ?? ''));
  }

  /**
   * Load an existing entity or create one for a full submission.
   *
   * Partial updates never create entities.
   *
   * @param array $data
   *   Post API data.
   * @param int $user_id
   *   Owner user ID for new entities.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Entity to process.
   *
   * @throws \Drupal\reliefweb_post_api\Exception\DocumentNotFoundException
   *   When a partial update targets a missing document.
   */
  protected function loadEntityForProcessing(array $data, int $user_id): ContentEntityInterface {
    $uuid = $this->resolveDocumentUuid($data);
    $bundle = $this->getBundle();
    $entity = $this->entityRepository->loadEntityByUuid($this->getEntityType(), $uuid);

    if (!empty($data['partial'])) {
      if (empty($entity) || !($entity instanceof ContentEntityInterface)) {
        throw new DocumentNotFoundException(ContentProcessorMessage::DocumentNotFound->value);
      }
      return $entity;
    }

    if ($entity instanceof ContentEntityInterface) {
      return $entity;
    }

    // Create a new entity.
    return $this->entityTypeManager->getStorage($this->getEntityType())->create([
      'uuid' => $uuid,
      'type' => $bundle,
      'langcode' => $this->getDefaultLangcode(),
      'uid' => $user_id,
      // This is important to avoid content imported in the same batch
      // to have the exact same timestamp.
      'created' => time(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function isUnchanged(ContentEntityInterface $entity, array $data): bool {
    if ($entity->isNew() || !$entity->hasField('field_post_api_hash')) {
      return FALSE;
    }

    // Allow identical payloads to reopen retired content.
    if ($entity instanceof EntityModeratedInterface && $entity->isRetiredModerationStatus()) {
      return FALSE;
    }

    $stored = $entity->get('field_post_api_hash')->value ?? '';
    if ($stored === '') {
      return FALSE;
    }

    return hash_equals((string) $stored, $this->getSubmissionHash($data));
  }

  /**
   * {@inheritdoc}
   */
  public function isUnchangedSubmission(string $uuid, array $data): bool {
    $storage = $this->entityTypeManager->getStorage($this->getEntityType());
    $uuid_key = $storage->getEntityType()->getKey('uuid');
    $hash = $this->getSubmissionHash($data);

    $query = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition($uuid_key, $uuid, '=')
      ->condition('field_post_api_hash', $hash, '=')
      ->range(0, 1);

    $retired_statuses = $this->getRetiredModerationStatuses();
    if ($retired_statuses !== []) {
      $query->condition('moderation_status', $retired_statuses, 'NOT IN');
    }

    $ids = $query->execute();

    return !empty($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function withdraw(string $uuid, int $user_id): ContentEntityInterface {
    $entity = $this->entityRepository->loadEntityByUuid($this->getEntityType(), $uuid);
    if (empty($entity) || !($entity instanceof ContentEntityInterface)) {
      throw new DocumentNotFoundException(ContentProcessorMessage::DocumentNotFound->value);
    }

    $this->validateEntityBundle($entity);
    $this->validateEntityProcessable($entity);

    if (!($entity instanceof EntityModeratedInterface)) {
      throw new ContentProcessorException(ContentProcessorMessage::DocumentCannotBeWithdrawn->value);
    }

    if ($entity->getModerationStatus() === 'withdrawn') {
      return $entity;
    }

    $entity->setModerationStatus('withdrawn');
    $entity->setNewRevision(TRUE);
    $entity->setRevisionCreationTime(time());
    $entity->setRevisionUserId($user_id);
    if ($entity instanceof EntityRevisionedInterface) {
      $entity->updateRevisionLogMessage('Withdrawn via Post API.', 'replace', FALSE);
    }
    $entity->save();

    return $entity;
  }

  /**
   * Compute the Post API payload hash used for change detection.
   *
   * @param array $data
   *   Post API data.
   *
   * @return string
   *   Hash string.
   */
  protected function getSubmissionHash(array $data): string {
    return $data['hash'] ?? HashHelper::generateHash($data, ['provider', 'user']);
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
    $this->validatePartialConditionals($data);
  }

  /**
   * Validate cross-field rules for partial updates against stored state.
   *
   * Schemas may contain conditionals (e.g. a field required when another
   * field has a given value). Those can fail on a partial update when only
   * one of the related fields are in the payload for example.
   *
   * For partial updates, ::validateSchema() removes those conditionals from
   * the schema. Bundle plugins that need them should override this method and
   * re-check the rules in PHP (using the current entity values for fields not
   * present in the patch).
   *
   * @param array $data
   *   Post API data (includes partial flag when applicable).
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   When the effective document would violate a conditional rule.
   */
  protected function validatePartialConditionals(array $data): void {
    // No cross-field conditionals for this bundle.
  }

  /**
   * {@inheritdoc}
   */
  public function validateSchema(array $data): void {
    unset($data['bundle']);
    unset($data['provider']);
    unset($data['user']);
    unset($data['hash']);
    unset($data['status']);
    unset($data['log_message']);

    // Partial update.
    $partial = !empty($data['partial']);
    unset($data['partial']);

    $schema = $this->getPluginSetting('schema', $this->getJsonSchema());
    $decoded = Json::decode($schema) ?: [];
    $mandatory_fields = $decoded['required'] ?? [];

    $this->applySchemaMutations($decoded);
    if ($partial) {
      $this->applyPartialSchemaMutations($decoded, $mandatory_fields);
    }

    // Opis caches parsed schemas by root $id. Always derive $id from the
    // schema content so file defaults, importer overrides, and in-method
    // mutations (partial / allow_raw_bytes) never collide under a stale id,
    // while identical schemas still share a cache entry.
    unset($decoded['$id']);
    $schema_hash = hash('sha256', Json::encode($decoded));
    $decoded['$id'] = 'schema:///reliefweb-post-api/' . $schema_hash . '.json';

    // Opis expects a string, not an array, for the schema.
    $schema = Json::encode($decoded);

    // Use Opis helper to ensure the data contains objects not associative
    // arrays.
    $data = Helper::toJSON($data);

    $this->schemaValidationPartial = $partial;
    try {
      $result = $this->getSchemaValidator()->validate($data, $schema);
      if (!$result->isValid()) {
        $formatter = new ErrorFormatter();
        $errors = $formatter->formatKeyed(
          error: $result->error(),
          formatter: [$this, 'schemaErrorFormatter'],
        );
        $errors = $this->filterNullableOneOfNoise($errors);
        $message = json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        throw new ContentProcessorException($message);
      }
    }
    finally {
      $this->schemaValidationPartial = FALSE;
    }
  }

  /**
   * Custom JSON schema error formatter.
   *
   * Prefers schema descriptions for opaque keywords (pattern/not/allOf/anyOf),
   * then keyword-specific friendly messages, then Opis placeholder
   * substitution.
   *
   * @param \Opis\JsonSchema\Errors\ValidationError $error
   *   Validation error.
   * @param ?string $message
   *   Error message override.
   *
   * @return string
   *   The formatted error message.
   *
   * @see \Opis\JsonSchema\Errors\ErrorFormatter::formatErrorMessage()
   * @see \Opis\JsonSchema\Errors\ErrorFormatter::getDefaultArgs()
   */
  public function schemaErrorFormatter(ValidationError $error, ?string $message = NULL): string {
    $message ??= $error->message();

    $data = $error->data();
    $info = $error->schema()->info();
    $info_data = $info->data();
    $keyword = $error->keyword();
    $error_args = $error->args();

    if (in_array($keyword, ['not', 'allOf', 'anyOf', 'pattern'], TRUE)) {
      $keyword_data = $info_data?->{$keyword};

      // The ReliefWeb POST API specifications contain descriptions that are
      // more useful indications of what to do than the obscure regex pattern
      // etc. so we use them as error messages.
      if (isset($keyword_data->description)) {
        return $keyword_data->description;
      }
      elseif (isset($info_data->description)) {
        return $info_data->description;
      }
    }

    $friendly = $this->formatSchemaKeywordError($keyword, $error, $info_data, $error_args);
    if ($friendly !== NULL) {
      return $friendly;
    }

    // Code from ErrorFormatter::getDefaultArgs().
    $path = $info->path();
    $path[] = $error->keyword();

    $args = [
      'data:type' => $data->type(),
      'data:value' => $data->value(),
      'data:path' => JsonPointer::pathToString($data->fullPath()),

      'schema:id' => $info->id(),
      'schema:root' => $info->root(),
      'schema:base' => $info->base(),
      'schema:draft' => $info->draft(),
      'schema:keyword' => $error->keyword(),
      'schema:path' => JsonPointer::pathToString($path),
    ] + $error_args;

    // Code from ErrorFormatter::formatErrorMessage().
    if (!$args) {
      return $message;
    }

    return preg_replace_callback(
      '~{([^}]+)}~imu',
      static function (array $m) use ($args) {
        if (!isset($args[$m[1]])) {
          return $m[0];
        }

        $value = $args[$m[1]];

        if (is_array($value)) {
          return implode(', ', $value);
        }

        return (string) $value;
      },
      $message
    );
  }

  /**
   * Format a friendly message for a known JSON Schema keyword error.
   *
   * @param string $keyword
   *   Opis validation keyword.
   * @param \Opis\JsonSchema\Errors\ValidationError $error
   *   Validation error.
   * @param object|string|null $info_data
   *   Schema node data from the error's schema info.
   * @param array $error_args
   *   Opis error args.
   *
   * @return string|null
   *   Friendly message, or NULL to fall back to Opis formatting.
   */
  protected function formatSchemaKeywordError(string $keyword, ValidationError $error, object|string|null $info_data, array $error_args): ?string {
    $data = $error->data();
    $data_path = $data->fullPath();

    switch ($keyword) {
      case 'type':
        $expected = (string) ($error_args['expected'] ?? '');
        $actual = (string) ($error_args['type'] ?? $data->type());
        if ($this->schemaValidationPartial && $actual === 'null' && $expected !== 'null') {
          $field = $data_path === [] ? '/' : (string) end($data_path);
          return ContentProcessorMessage::CannotClearMandatoryField->format([
            '@field' => $field,
          ]);
        }
        // Marker for null-branch oneOf failures; removed by
        // filterNullableOneOfNoise().
        if ($expected === 'null') {
          return ContentProcessorMessage::SchemaInvalidType->format([
            '@expected' => 'null',
            '@actual' => $actual,
          ]);
        }
        return ContentProcessorMessage::SchemaInvalidType->format([
          '@expected' => $expected !== '' ? $expected : 'unknown',
          '@actual' => $actual,
        ]);

      case 'required':
        $missing = $error_args['missing'] ?? [];
        return ContentProcessorMessage::SchemaMissingRequired->format([
          '@missing' => is_array($missing) ? implode(', ', $missing) : (string) $missing,
        ]);

      case 'format':
        $format = (string) ($error_args['format'] ?? '');
        return match ($format) {
          // phpcs:ignore Drupal.WhiteSpace.ScopeIndent.IncorrectExact
          'iri' => ContentProcessorMessage::SchemaInvalidUrl->value,
          'uuid' => $this->isFileMapKeyFormatError($data_path)
            ? ContentProcessorMessage::SchemaInvalidFileMapKey->value
            : ContentProcessorMessage::SchemaInvalidUuid->value,
          'date-time' => ContentProcessorMessage::SchemaInvalidDateTime->value,
          'idn-email' => ContentProcessorMessage::SchemaInvalidEmail->value,
          default => ContentProcessorMessage::SchemaInvalidFormat->format([
            '@format' => $format,
          ]),
        };

      case 'enum':
        $values = [];
        if (is_object($info_data) && isset($info_data->enum) && is_array($info_data->enum)) {
          foreach ($info_data->enum as $value) {
            if (is_scalar($value) || $value === NULL) {
              $values[] = (string) $value;
            }
          }
        }
        if ($values === []) {
          return ContentProcessorMessage::SchemaInvalidEnum->format([
            '@values' => '(see schema)',
          ]);
        }
        return ContentProcessorMessage::SchemaInvalidEnum->format([
          '@values' => implode(', ', $values),
        ]);

      case 'unevaluatedProperties':
        $properties = $error_args['properties'] ?? [];
        return ContentProcessorMessage::SchemaUnknownProperties->format([
          '@properties' => is_array($properties) ? implode(', ', $properties) : (string) $properties,
        ]);

      case 'minItems':
        return ContentProcessorMessage::SchemaMinItems->format([
          '@min' => (string) ($error_args['min'] ?? ''),
          '@count' => (string) ($error_args['count'] ?? ''),
        ]);

      case 'maxItems':
        return ContentProcessorMessage::SchemaMaxItems->format([
          '@max' => (string) ($error_args['max'] ?? ''),
          '@count' => (string) ($error_args['count'] ?? ''),
        ]);

      case 'minLength':
        return ContentProcessorMessage::SchemaMinLength->format([
          '@min' => (string) ($error_args['min'] ?? ''),
          '@length' => (string) ($error_args['length'] ?? ''),
        ]);

      case 'maxLength':
        return ContentProcessorMessage::SchemaMaxLength->format([
          '@max' => (string) ($error_args['max'] ?? ''),
          '@length' => (string) ($error_args['length'] ?? ''),
        ]);

      case 'minimum':
        return ContentProcessorMessage::SchemaMinimum->format([
          '@min' => (string) ($error_args['min'] ?? ''),
        ]);

      case 'uniqueItems':
        return ContentProcessorMessage::SchemaUniqueItems->value;

      case 'maxProperties':
        return ContentProcessorMessage::SchemaMaxProperties->format([
          '@max' => (string) ($error_args['max'] ?? ''),
          '@count' => (string) ($error_args['count'] ?? ''),
        ]);
    }

    return NULL;
  }

  /**
   * Whether a uuid format error is for a file attachment map key.
   *
   * @param array $data_path
   *   JSON pointer path segments of the failing data.
   *
   * @return bool
   *   TRUE when the failure is on the report file map property names.
   */
  protected function isFileMapKeyFormatError(array $data_path): bool {
    // PropertyNames failures report the object path (e.g. ['file']).
    return $data_path === ['file'];
  }

  /**
   * Remove oneOf null-branch type failures that obscure real errors.
   *
   * Partial schemas wrap optional fields in oneOf[null, schema]. Opis then
   * reports leaf errors for both branches; "expected null" is never useful.
   *
   * @param array<string, list<string>> $errors
   *   Keyed errors from ErrorFormatter::formatKeyed().
   *
   * @return array<string, list<string>>
   *   Filtered errors.
   */
  protected function filterNullableOneOfNoise(array $errors): array {
    $null_type_message = ContentProcessorMessage::SchemaInvalidType->format([
      '@expected' => 'null',
      '@actual' => '__placeholder__',
    ]);
    // Match any "Invalid type: expected null, got X." message.
    $null_type_prefix = strstr($null_type_message, '__placeholder__', TRUE);
    if ($null_type_prefix === FALSE) {
      $null_type_prefix = 'Invalid type: expected null, got ';
    }

    foreach ($errors as $path => $messages) {
      $filtered = array_values(array_filter(
        $messages,
        static fn(string $message): bool => !str_starts_with($message, $null_type_prefix),
      ));
      if ($filtered === []) {
        unset($errors[$path]);
      }
      else {
        $errors[$path] = $filtered;
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function validateUuid(array $data): void {
    $partial = !empty($data['partial']);

    if (empty($data['uuid'])) {
      throw new ContentProcessorException(ContentProcessorMessage::MissingDocumentUuid->value);
    }
    elseif (!Uuid::isValid($data['uuid'])) {
      throw new ContentProcessorException(ContentProcessorMessage::InvalidDocumentUuid->value);
    }

    // Partial updates may omit the document URL and rely on the path UUID.
    if ($partial && empty($data['url'])) {
      return;
    }

    if (empty($data['url'])) {
      throw new ContentProcessorException(ContentProcessorMessage::MissingDocumentUrl->value);
    }
    // @todo if we want to allow providers to edit existing ReliefWeb content
    // then we cannot do this comparison because the UUID is not generated this
    // way and is not derived from the document URL.
    elseif ($this->generateUuid($data['url']) !== $data['uuid']) {
      throw new ContentProcessorException(ContentProcessorMessage::UuidUrlMismatch->value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateSources(array $data): void {
    // In case of partial update, the source may not be present in which case
    // we skip the validation. Null means clear and is handled elsewhere.
    if (!empty($data['partial']) && empty($data['source'])) {
      return;
    }

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
      throw new ContentProcessorException(ContentProcessorMessage::UnallowedSources->value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateUrls(array $data): void {
    // Partial updates may omit the document URL.
    if (!empty($data['partial']) && empty($data['url'])) {
      return;
    }

    $provider = $this->getProvider($data['provider'] ?? '');

    $document_pattern = $provider->getUrlPattern('document');
    if (empty($data['url'])) {
      throw new ContentProcessorException(ContentProcessorMessage::MissingDocumentUrl->value);
    }
    elseif (!$this->validateUrl($data['url'], $document_pattern)) {
      throw new ContentProcessorException(ContentProcessorMessage::UnallowedDocumentUrl->format([
        '@url' => $data['url'],
      ]));
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
   * Mutate the decoded JSON schema for every validate (PUT and PATCH).
   *
   * Default: when allow_raw_bytes is enabled, add a bytes property on file map
   * value objects and on image. Bundle plugins may override. Runs before
   * applyPartialSchemaMutations() so bytes land on the object branch before
   * nullable oneOf wraps.
   *
   * @param array &$decoded
   *   Decoded JSON schema (mutated in place).
   */
  protected function applySchemaMutations(array &$decoded): void {
    if (!$this->getPluginSetting('allow_raw_bytes', FALSE)) {
      return;
    }
    // Must run before applyPartialSchemaMutations() wraps schemas in oneOf.
    if (isset($decoded['properties']['file']['patternProperties']) && is_array($decoded['properties']['file']['patternProperties'])) {
      foreach ($decoded['properties']['file']['patternProperties'] as &$value_schema) {
        if (is_array($value_schema)) {
          $this->addRawBytesToSchema($value_schema, 'Raw bytes of the file content.');
        }
      }
      unset($value_schema);
    }
    if (isset($decoded['properties']['image']) && is_array($decoded['properties']['image'])) {
      $this->addRawBytesToSchema($decoded['properties']['image'], 'Raw bytes of the image content.');
    }
  }

  /**
   * Mutate the decoded JSON schema for a partial (PATCH) payload.
   *
   * Data-independent: loosens top-level required to uuid, drops root allOf
   * (enforced later via validatePartialConditionals()), and wraps every
   * non-mandatory root property in oneOf null|current so optional fields can
   * be cleared. Bundle plugins may override (e.g. Report wraps file map values
   * first, then calls parent). The schema $id is hashed after this runs in
   * validateSchema().
   *
   * @param array &$decoded
   *   Decoded JSON schema (mutated in place).
   * @param array $mandatory_fields
   *   Original schema required list (before this mutation).
   */
  protected function applyPartialSchemaMutations(array &$decoded, array $mandatory_fields): void {
    // Only UUID is mandatory at the top level on PATCH. URL is optional; if
    // provided it is still validated separately.
    $decoded['required'] = ['uuid'];
    // Root if/then/else cannot be evaluated on a partial payload alone
    // (missing fields make some ifs succeed incorrectly).
    unset($decoded['allOf']);

    // Wrap every non-mandatory property in oneOf null|current so optional
    // fields can be cleared.
    if (!empty($decoded['properties']) && is_array($decoded['properties'])) {
      foreach ($decoded['properties'] as $property => &$schema) {
        if (!is_array($schema) || in_array($property, $mandatory_fields, TRUE)) {
          continue;
        }
        $this->wrapSchemaWithNullableOneOf($schema);
      }
      unset($schema);
    }
  }

  /**
   * Ensure a schema accepts null via oneOf (idempotent).
   *
   * If $schema already has a oneOf with a null branch, leave it unchanged.
   * If it has a oneOf without null, prepend ['type' => 'null'].
   * Otherwise replace $schema with oneOf [null, original].
   *
   * @param array &$schema
   *   A JSON Schema subschema (mutated in place).
   */
  protected function wrapSchemaWithNullableOneOf(array &$schema): void {
    if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
      foreach ($schema['oneOf'] as $branch) {
        // Skip if there is already a null branch.
        if (is_array($branch) && ($branch['type'] ?? NULL) === 'null') {
          return;
        }
      }
      // Prepend a null branch to the oneOf.
      array_unshift($schema['oneOf'], ['type' => 'null']);
    }
    // Otherwise wrap the schema in a new oneOf with a null branch.
    else {
      $schema = [
        'oneOf' => [
          ['type' => 'null'],
          $schema,
        ],
      ];
    }
  }

  /**
   * Add a raw bytes property to an object schema.
   *
   * @param array &$schema
   *   Object schema (mutated in place). Must not be wrapped in oneOf yet.
   * @param string $description
   *   Description for the bytes property.
   */
  protected function addRawBytesToSchema(array &$schema, string $description): void {
    $schema['properties']['bytes'] = [
      'description' => $description,
      'type' => 'string',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setReliefWebFileField(ContentEntityInterface $entity, string $field_name, ?array $files, bool $partial = FALSE, ?array $file_order = NULL): void {
    if (!$entity->hasField($field_name)) {
      return;
    }

    /** @var \Drupal\Core\Field\FieldItemListInterface $field **/
    $field = $entity->get($field_name);
    $definition = $field->getItemDefinition();

    $mimetypes = $this->getPluginSetting('attachments.allowed_mimetypes', ['application/pdf']);
    $max_size = $this->getPluginSetting('attachments.allowed_max_size', '20MB');
    $max_files = 10;

    // NULL clears all attachments; ignore file_order.
    if ($files === NULL) {
      $field->setValue([]);
      return;
    }

    // PUT + empty map clears. PATCH + empty map is a no-op unless reordering.
    if ($files === [] && !$partial) {
      $field->setValue([]);
      return;
    }
    if ($files === [] && $partial && $file_order === NULL) {
      return;
    }

    // Index existing attachments by permanent UUID and managed-file UUID
    // (legacy items used the managed UUID as the permanent UUID). On PATCH,
    // seed $attachments in current field order (keep/append when file_order
    // is omitted). On PUT, start empty (exact-set).
    $existing_by_uuid = [];
    $attachments = [];
    foreach ($field as $item) {
      $item_uuid = $item->getUuid();
      if (empty($item_uuid)) {
        continue;
      }
      $existing_by_uuid[$item_uuid] = $item;
      if ($partial) {
        $attachments[$item_uuid] = $item;
      }
      $item_file_uuid = $item->getFileUuid();
      if (!empty($item_file_uuid)) {
        $existing_by_uuid[$item_file_uuid] = $item;
      }
    }

    foreach ($files as $key => $file) {
      $key = (string) $key;

      // PATCH delete.
      if ($file === NULL) {
        if ($partial) {
          $resolved = $this->resolveAttachmentMapKey($key, $attachments, $existing_by_uuid, $entity->uuid());
          if ($resolved !== NULL) {
            unset($attachments[$resolved]);
          }
        }
        continue;
      }
      if (!is_array($file) || empty($file['checksum']) || empty($file['download_url']) || empty($file['filename'])) {
        continue;
      }

      $download_url = $file['download_url'];
      $file_name = $file['filename'];
      $checksum = $file['checksum'];
      $bytes = $file['bytes'] ?? NULL;
      // Map key (validated against url when present). May differ from the
      // stored permanent UUID for legacy items.
      $uuid = $key;
      // Managed file UUID: changes when content (checksum) changes. Derived
      // from the map key so URL-derived keys still match legacy items whose
      // permanent UUID was wrongly set to the managed id.
      $file_uuid = $this->generateUuid($uuid . $checksum, $entity->uuid());

      try {
        $existing_item = $existing_by_uuid[$uuid] ?? $existing_by_uuid[$file_uuid] ?? NULL;
        // Always index $attachments by the stored permanent UUID so a request
        // key that only matches via the managed-UUID alias does not leave the
        // seeded permanent entry in place (duplicate field items on PATCH).
        $permanent_uuid = $existing_item !== NULL ? $existing_item->getUuid() : $uuid;
        if ($existing_item !== NULL) {
          unset($existing_by_uuid[$permanent_uuid], $existing_by_uuid[$existing_item->getFileUuid()]);
        }

        // Nothing to do if the file content didn't change.
        if ($existing_item !== NULL && $existing_item->getFileUuid() === $file_uuid) {
          $item = $existing_item;
        }
        // New download or content change: create a managed file. Preserve the
        // previous permanent UUID when replacing so published URLs stay stable
        // (including legacy items that wrongly used the managed UUID).
        else {
          // We use the file name to guess the mimetype not the URL because it
          // may not have an extension.
          $mimetype = $this->guessFileMimeType($file_name, $mimetypes);
          $item = $this->createReliefWebFileFieldItem($definition, $entity, $permanent_uuid, $file_uuid, $file_name, $download_url, $checksum, $mimetype, $max_size, $bytes);
          // Carry optional metadata across content replace; payload may
          // override below.
          if ($existing_item !== NULL) {
            $item->get('description')->setValue($existing_item->get('description')->getValue());
            $item->get('language')->setValue($existing_item->get('language')->getValue());
          }
        }

        // Set/unset description and language.
        // PATCH: omit preserves, null clears, value sets.
        // PUT: omit defaults to empty.
        if (array_key_exists('description', $file)) {
          $item->get('description')->setValue($file['description'] ?? '');
        }
        elseif (!$partial || $existing_item === NULL) {
          $item->get('description')->setValue('');
        }
        if (array_key_exists('language', $file)) {
          $item->get('language')->setValue($this->resolveFileLanguageCode($file['language'] ?: ''));
        }
        elseif (!$partial || $existing_item === NULL) {
          $item->get('language')->setValue('');
        }

        $attachments[$permanent_uuid] = $item;
      }
      catch (DuplicateException $exception) {
        $message = $exception->getMessage();
        $this->getLogger()->error($message);

        // Throw an exception so that upstream can refuse the submission.
        // We replace the file UUID with the UUID provided in the payload
        // to help the submitter identify which file is the duplicate.
        $message = strtr($message, $file_uuid, $uuid);
        throw new DuplicateException($message);
      }
      catch (\Exception $exception) {
        $this->getLogger()->error($exception->getMessage());
      }
    }

    if (count($attachments) > $max_files) {
      throw new ContentProcessorException(ContentProcessorMessage::TooManyFiles->format([
        '@max' => (string) $max_files,
      ]));
    }

    // Prefer attachments listed in file_order (unrecognized UUIDs ignored),
    // then remaining attachments in their current relative order.
    $values = [];
    if ($file_order !== NULL) {
      foreach ($file_order as $uuid) {
        if (isset($attachments[$uuid])) {
          $values[] = $attachments[$uuid]->getValue();
          unset($attachments[$uuid]);
        }
      }
    }
    foreach ($attachments as $item) {
      $values[] = $item->getValue();
    }

    $field->setValue($values);
  }

  /**
   * Resolve a file map key to the stored permanent UUID in $attachments.
   *
   * Direct hit, managed-UUID alias via $existing_by_uuid, or legacy match:
   * URL-derived map key + stored file_hash yields the managed file UUID.
   *
   * @param string $key
   *   Map key from the payload.
   * @param array<string, \Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile> $attachments
   *   Working attachment set keyed by stored permanent UUID.
   * @param array<string, \Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile> $existing_by_uuid
   *   Existing items keyed by permanent and managed-file UUID.
   * @param string $document_uuid
   *   Document UUID used as UUID v5 namespace.
   *
   * @return string|null
   *   Stored permanent UUID to use in $attachments, or NULL if unknown.
   */
  protected function resolveAttachmentMapKey(string $key, array $attachments, array $existing_by_uuid, string $document_uuid): ?string {
    if (isset($attachments[$key])) {
      return $key;
    }
    if (isset($existing_by_uuid[$key])) {
      return $existing_by_uuid[$key]->getUuid();
    }
    foreach ($attachments as $stored_key => $item) {
      $hash = (string) ($item->getFileHash() ?? '');
      if ($hash !== '' && $this->generateUuid($key . $hash, $document_uuid) === $item->getFileUuid()) {
        return $stored_key;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setImageField(ContentEntityInterface $entity, string $field_name, array $image): void {
    if (!$entity->hasField($field_name)) {
      return;
    }

    /** @var \Drupal\Core\Field\FieldItemListInterface $field **/
    $field = $entity->get($field_name);

    // Empty payload clears the image (PATCH null → []).
    if (!isset($image['download_url'], $image['checksum'])) {
      $field->setValue(NULL);
      return;
    }

    $download_url = $image['download_url'];
    $checksum = $image['checksum'];
    $bytes = $image['bytes'] ?? NULL;
    $uuid = $this->generateUuid($checksum . $download_url, $entity->uuid());

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
        $mimetype = $this->guessFileMimeType($download_url, $mimetypes);
        $alt = $image['description'] ?? '';
        $media = $this->createImageMedia($bundle, $uuid, $download_url, $checksum, $mimetype, $max_size, $alt, $bytes);
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
  public function createImageMedia(
    string $bundle,
    string $uuid,
    string $url,
    string $checksum,
    string $mimetype,
    string $max_size,
    string $alt,
    ?string $bytes = NULL,
  ): ?MediaInterface {
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
    $file = $this->createFile($file_uuid, $file_uri, $file_name, $mimetype, $url, $checksum, $max_size, $validators, $bytes);

    // Save the file permanently.
    $file->setPermanent();
    $file->save();

    // Populate the image field.
    [$width, $height] = @getimagesize($file->getFileUri()) ?: [NULL, NULL];

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
  public function createReliefWebFileFieldItem(
    DataDefinitionInterface $definition,
    ContentEntityInterface $entity,
    string $uuid,
    string $file_uuid,
    string $file_name,
    string $url,
    string $checksum,
    string $mimetype,
    string $max_size = '',
    ?string $bytes = NULL,
  ): ?ReliefWebFile {
    // Create a new field item.
    $item = ReliefWebFile::createInstance($definition);

    // Temporary private URI uses the managed-file UUID (same as the form
    // widget). It is moved to the permanent UUID URI when the entity is saved.
    $extension = ReliefWebFile::extractFileExtension($file_name);
    $file_uri = ReliefWebFile::getFileUriFromUuid($file_uuid, $extension, TRUE);

    // Retrieve the upload validators to validate the created file as if
    // uploaded via the form.
    $validators = $item->getUploadValidators($entity, FALSE) ?? [];

    // Create the file entity with the content.
    $file = $this->createFile($file_uuid, $file_uri, $file_name, $mimetype, $url, $checksum, $max_size, $validators, $bytes);
    if (empty($file)) {
      throw new \Exception(strtr('Unable to create the file entity for the uploaded file @url with UUID @uuid.', [
        '@url' => $url,
        '@uuid' => $file_uuid,
      ]));
    }

    // Set the properties of the ReliefWeb file field item so it's fully
    // constructed and can be added to the field item list.
    $item->setValue([
      // Permanent UUID (public /attachments/{uuid}/… identity), typically
      // derived from the immutable file url and document UUID.
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
  public function createFile(
    string $uuid,
    string $uri,
    string $name,
    string $mimetype,
    string $url,
    string $checksum,
    string $max_size,
    array $validators = [],
    ?string $bytes = NULL,
  ): ?FileInterface {

    // Attempt to load the file if already exists.
    $file = $this->entityRepository->loadEntityByUuid('file', $uuid);

    if (empty($file)) {
      $content = $this->getRemoteFileContent($url, $checksum, $mimetype, $max_size, $bytes);

      // Skip if we cannot retrieve the new file.
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
  public function getRemoteFileContent(
    string $url,
    string $checksum,
    string $mimetype,
    string $max_size = '',
    ?string $bytes = NULL,
  ): string {
    $content = '';
    $max_size = !empty($max_size) ? Bytes::toNumber($max_size) : Environment::getUploadMaxSize();

    // Use the raw bytes directly if available.
    if (!empty($bytes)) {
      if ($max_size > 0 && strlen($bytes) > $max_size) {
        throw new \Exception('File is too large.');
      }
      if (hash('sha256', $bytes) !== $checksum) {
        throw new \Exception('Invalid file checksum.');
      }
      return $bytes;
    }

    $body = NULL;
    try {
      $response = $this->httpClient->get(UrlHelper::replaceBaseUrl($url), [
        'stream' => TRUE,
        // @todo retrieve that from the configuration.
        'connect_timeout' => 30,
        'timeout' => 600,
        'headers' => [
          'Accept' => '*/*',
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Unexpected HTTP status: ' . $response->getStatusCode());
      }

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
      $content_length = $response->getHeaderLine('Content-Length');
      if ($content_length !== '' && $max_size > 0 && ((int) $content_length) > $max_size) {
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
      $constraint = $violation->getConstraint();
      // Handle file duplication differently since we refuse duplicates.
      if (isset($constraint) && $constraint instanceof ReliefWebFileHashConstraint) {
        $message = new FormattableMarkup($violation->getMessage(), $violation->getParameters());
        throw new DuplicateException((string) $message);
      }
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
      throw new ContentProcessorException(ContentProcessorMessage::UnsupportedMimetype->format([
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
   * Resolve a file attachment language code against known taxonomy languages.
   *
   * Unknown codes (for example zh when no Chinese language term exists) fall
   * back to ot (other). Empty codes and ot are left unchanged.
   *
   * @param string $code
   *   Submitted ISO 639-1 language code, or ot.
   *
   * @return string
   *   Resolved language code to store on the file field.
   */
  public function resolveFileLanguageCode(string $code): string {
    if ($code === '' || $code === 'ot') {
      return $code;
    }

    $languages = $this->getFileLanguages();
    return isset($languages[$code]) ? $code : 'ot';
  }

  /**
   * Get the list of languages supported for file attachments.
   *
   * @return array
   *   Languages keyed by ISO 639-1 code (or ot).
   */
  protected function getFileLanguages(): array {
    return function_exists('reliefweb_files_get_languages')
      ? reliefweb_files_get_languages()
      : [];
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
