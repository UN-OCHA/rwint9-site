<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_import\Exception\InvalidConfigurationException;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Base importer plugin class.
 */
abstract class ReliefWebImporterPluginBase extends PluginBase implements ReliefWebImporterPluginInterface, ContainerFactoryPluginInterface, PluginFormInterface, ConfigurableInterface {

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $mimeTypeGuesser
   *   The mime type guesser.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface $contentProcessorPluginManager
   *   The Post API content processor plugin manager.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $pathResolver
   *   The path resolver service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ClientInterface $httpClient,
    protected MimeTypeGuesserInterface $mimeTypeGuesser,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected Connection $database,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager,
    protected ExtensionPathResolver $pathResolver,
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
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('logger.factory'),
      $container->get('http_client'),
      $container->get('file.mime_type.guesser.extension'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('database'),
      $container->get('plugin.manager.reliefweb_post_api.content_processor'),
      $container->get('extension.path.resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginLabel(): string {
    $definition = $this->getPluginDefinition();
    return (string) ($definition['label'] ?? $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'reliefweb_importer';
  }

  /**
   * {@inheritdoc}
   */
  public function enabled(): bool {
    return (bool) $this->getPluginSetting('enabled', FALSE, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getLogger(): LoggerInterface {
    if (!isset($this->logger)) {
      $this->logger = $this->loggerFactory->get(implode('.', [
        'reliefweb_import',
        $this->getPluginType(),
        $this->getPluginId(),
      ]));
    }
    return $this->logger;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginSetting(string $key, mixed $default = NULL, bool $throw_if_null = TRUE): mixed {
    if (empty($key)) {
      return NULL;
    }

    $configuration = $this->getConfiguration();

    $parts = explode('.', $key);
    if (count($parts) === 1) {
      $setting = $configuration[$key] ?? $default;
    }
    else {
      $value = NestedArray::getValue($configuration, $parts, $key_exists);
      $setting = $key_exists ? $value : $default;
    }

    if (is_null($setting) && $throw_if_null) {
      throw new InvalidConfigurationException(strtr('Missing @key for @type plugin @id', [
        '@key' => $key,
        '@type' => $this->getPluginType(),
        '@id' => $this->getPluginId(),
      ]));
    }
    return $setting;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function loadConfiguration(): array {
    $key = $this->getConfigurationKey();

    // Retrieve the plugin configuration.
    $configuration = $this->configFactory->get($key)?->get() ?? [];

    // Retrieve the provider UUID from the state.
    $configuration['provider_uuid'] = $this->state->get($key . '.provider_uuid', '');

    return $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function saveConfiguration(array $configuration): void {
    $key = $this->getConfigurationKey();

    // Exclude the provider UUID since that's content. We save it in the state
    // instead.
    $provider_uuid = $configuration['provider_uuid'] ?? '';
    unset($configuration['provider_uuid']);

    // Update the plugin configuration.
    $config = $this->configFactory->getEditable($key);
    $config->setData($configuration);
    $config->save();

    // Store the provider UUID associated with the importer.
    $this->state->set($key . '.provider_uuid', $provider_uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigurationKey(): string {
    return 'reliefweb_import.plugin.importer.' . $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $form_state->getValue('enabled', $this->enabled()),
    ];

    // Retrieve the list of available Post API providers.
    $provider_options = [];
    $storage = $this->entityTypeManager->getStorage('reliefweb_post_api_provider');
    $providers = $storage->loadMultiple();
    foreach ($providers as $provider) {
      $provider_options[$provider->uuid()] = $provider->label();
    }

    $provider_uuid = $form_state->getValue('provider_uuid', $this->getPluginSetting('provider_uuid', '', FALSE));
    $provider_uuid = isset($provider_options[$provider_uuid]) ? $provider_uuid : '';

    $form['provider_uuid'] = [
      '#type' => 'select',
      '#title' => $this->t('Post API Provider'),
      '#description' => $this->t('The Post API provider associated with this importer.'),
      '#options' => $provider_options,
      '#default_value' => $provider_uuid,
      '#empty_option' => $this->t('- Select a provider -'),
    ];

    $max_import_attemps = $form_state->getValue('max_import_attempts', $this->getPluginSetting('max_import_attempts', '', FALSE));
    $form['max_import_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Max import attemps'),
      '#default_value' => $max_import_attemps,
      '#min' => 1,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration($form_state->cleanValues()->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getReportAttachmentAllowedExtensions(): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'report');
    if (isset($definitions['field_file'])) {
      $extensions = $definitions['field_file']->getSetting('file_extensions') ?? '';
      return explode(' ', $extensions);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getReportAttachmentAllowedMaxSize(): int {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'report');
    if (isset($definitions['field_file'])) {
      $max_size = $definitions['field_file']->getSetting('max_filesize') ?? '';
      $max_size = !empty($max_size) ? Bytes::toNumber($max_size) : Environment::getUploadMaxSize();
      return (int) $max_size;
    }
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getJsonSchema(string $bundle): string {
    $path = $this->pathResolver->getPath('module', 'reliefweb_post_api');
    $schema = @file_get_contents($path . '/schemas/v2/' . $bundle . '.json');
    if ($schema === FALSE) {
      throw new ContentProcessorException(strtr('Missing @bundle JSON schema.', [
        '@bundle' => $bundle,
      ]));
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function generateUuid(string $string, ?string $namespace = NULL): string {
    if (empty($string)) {
      return '';
    }
    /* The default namespace is the UUID generated with
     * Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), 'reliefweb.int')->toRfc4122(); */
    $namespace = $namespace ?? '8e27a998-c362-5d1f-b152-d474e1d36af2';
    return Uuid::v5(Uuid::fromString($namespace), $string)->toRfc4122();
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationSkipClassification(bool &$skip, ClassificationWorkflowInterface $workflow, array $context): void {
    // Skip the classification by default.
    $skip = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationUserPermissionCheck(bool &$check, AccountInterface $account, array $context): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationSpecifiedFieldCheck(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationClassifiedFields(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    // Nothing to do.
  }

  /**
   * Sanitize a file name.
   *
   * @param string $filename
   *   File name to sanitize.
   * @param array $allowed_extensions
   *   Allowed file name extensions.
   *
   * @return string
   *   Sanitized file name.
   *
   * @see \Drupal\system\EventSubscriber\SecurityFileUploadEventSubscriber::sanitizeName()
   */
  protected function sanitizeFileName(string $filename, array $allowed_extensions = []): string {
    if (empty($allowed_extensions)) {
      return '';
    }

    // Sanitize the filename.
    $filename = $this->sanitizeText($filename);

    // Always rename dot files.
    $filename = trim($filename, '.');

    // Remove any null bytes.
    // @see https://php.net/manual/security.filesystem.nullbytes.php
    $filename = str_replace(chr(0), '', $filename);

    // Split up the filename by periods. The first part becomes the basename,
    // the last part the final extension.
    $filename_parts = explode('.', $filename);

    // Remove file basename.
    $basename = array_shift($filename_parts);

    // Remove final extension.
    $extension = strtolower((string) array_pop($filename_parts));

    // Ensure the extension is allowed.
    if (!in_array($extension, $allowed_extensions)) {
      return '';
    }

    return $basename . '.' . $extension;
  }

  /**
   * Sanitize a UTF-8 string.
   *
   * @param string $text
   *   The input UTF-8 string to be processed.
   * @param bool $preserve_newline
   *   If TRUE, ensure the new lines are preserved.
   *
   * @return string
   *   Sanitized text.
   *
   * @see \Drupal\reliefweb_utility\Helpers\TextHelper::sanitizeText()
   */
  protected function sanitizeText(string $text, bool $preserve_newline = FALSE): string {
    return TextHelper::sanitizeText($text, $preserve_newline);
  }

  /**
   * Get the list of manually posted documents matching the given patterns.
   *
   * @param array $document_ids
   *   An array of external document IDs to check.
   * @param string $url_pattern_template
   *   A URL pattern template with '{id}' placeholder for the document ID.
   *   Ex: 'https://data.unhcr.org/%/documents/%/{id}%'.
   * @param string $id_extraction_regex
   *   A regex pattern to extract the document ID from the URL.
   *   Must contain one capturing group for the ID.
   *   Ex: '#^https://data.unhcr.org/(?:[^/]+/)?documents/[^/]+/(\d+)[^/]*$#'.
   *
   * @return array
   *   Associative array mapping external document IDs (keys) to their
   *   corresponding report node IDs (values).
   */
  protected function getManuallyPostedDocuments(
    array $document_ids,
    string $url_pattern_template,
    string $id_extraction_regex,
  ): array {
    if (empty($document_ids)) {
      return [];
    }

    // Query to find existing manually posted records with these document IDs.
    $query = $this->database->select('node__field_origin_notes', 'fon');
    $query->addField('fon', 'entity_id', 'entity_id');
    $query->addField('fon', 'field_origin_notes_value', 'url');
    $query->condition('fon.bundle', 'report', '=');

    // Join the provider table. Manually posted content do not have one.
    $query->leftJoin('node__field_post_api_provider', 'fpap', '%alias.entity_id = fon.entity_id');
    $query->isNull('fpap.field_post_api_provider_target_id');

    // Use OR condition group for MySQL compatibility.
    $or_group = $query->orConditionGroup();
    foreach ($document_ids as $document_id) {
      $pattern = str_replace('{id}', (string) $document_id, $url_pattern_template);
      $or_group->condition('fon.field_origin_notes_value', $pattern, 'LIKE');
    }
    $query->condition($or_group);

    // Get the list of records keyed by the entity ID.
    $records = $query->execute()?->fetchAllKeyed() ?? [];
    if (empty($records)) {
      return [];
    }

    $manually_posted = [];
    $document_ids = array_flip($document_ids);
    foreach ($records as $entity_id => $url) {
      $matches = [];
      if (preg_match($id_extraction_regex, $url, $matches) && isset($matches[1], $document_ids[$matches[1]])) {
        $manually_posted[$matches[1]] = $entity_id;
      }
    }

    return $manually_posted;
  }

  /**
   * Retrieve existing import records for the given URLs.
   *
   * @param array $uuids
   *   An array of import item UUIDs to look up.
   *
   * @return array
   *   An array of import records keyed by the import item UUID.
   */
  protected function getExistingImportRecords(array $uuids): array {
    if (empty($uuids)) {
      return [];
    }

    $records = $this->database->select('reliefweb_import_records', 'r')
      ->fields('r')
      ->condition('imported_item_uuid', $uuids, 'IN')
      ->execute()
      ?->fetchAllAssoc('imported_item_uuid', \PDO::FETCH_ASSOC) ?? [];

    return $records;
  }

  /**
   * Save import records (create new or update existing).
   *
   * @param array $records
   *   List of import records. Each record must contain at least the 'uuid' key.
   *   Other fields will be created or updated as provided.
   *
   * @return array
   *   Array of results with counts of records created, updated or skipped if
   *   unchanged.
   */
  protected function saveImportRecords(array $records): array {
    if (empty($records)) {
      return [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
      ];
    }

    $created = 0;
    $updated = 0;
    $skipped = 0;

    // Get all URLs from the records to check which ones already exist.
    $urls = array_column($records, 'imported_item_uuid');
    $existing_records = $this->getExistingImportRecords($urls);

    foreach ($records as $record) {
      // Skip records without a URL.
      if (empty($record['imported_item_uuid'])) {
        continue;
      }

      // Set timestamp for changed field.
      $record['changed'] = time();

      // Check if this record already exists.
      $url = $record['imported_item_uuid'];
      if (isset($existing_records[$url])) {
        // Get existing record.
        $existing_record = $existing_records[$url];

        // Create comparison copies without the 'changed' timestamp.
        $compare_existing = $existing_record;
        $compare_new = $record;
        unset($compare_existing['changed']);
        unset($compare_new['changed']);

        // Only update if the record has actually changed.
        if ($compare_existing != $compare_new) {
          $this->database->update('reliefweb_import_records')
            ->fields($record)
            ->condition('imported_item_uuid', $url)
            ->execute();
          $updated++;
        }
        else {
          $skipped++;
        }
      }
      else {
        // Set timestamp for created field if not provided.
        if (!isset($record['created'])) {
          $record['created'] = time();
        }

        // Insert new record.
        $this->database->insert('reliefweb_import_records')
          ->fields($record)
          ->execute();
        $created++;
      }
    }

    return [
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
    ];
  }

}
