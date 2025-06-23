<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
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
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionIllegalFilename;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionFileTooBig;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
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
    // Load without overrides.
    $key = $this->getConfigurationKey();
    $configuration = $this->configFactory->get($key)->getOriginal('', FALSE) ?? [];
    // Preserve the provider UUID since it's from the state.
    $configuration['provider_uuid'] = $this->getConfiguration()['provider_uuid'] ?? '';
    $this->setConfiguration($configuration);

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

    $classification_settings = $form_state->getValue('classification', $this->getPluginSetting('classification', [], FALSE));

    $form['classification'] = [
      '#type' => 'details',
      '#title' => $this->t('Classification settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['classification']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => !empty($classification_settings['enabled']),
    ];
    $form['classification']['check_user_permissions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check user permissions'),
      '#default_value' => !empty($classification_settings['check_user_permissions']),
    ];
    $form['classification']['prevent_publication'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prevent publication during classification'),
      '#default_value' => !empty($classification_settings['prevent_publication']),
    ];
    $form['classification']['specified_field_check'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Field emptiness check rules'),
      '#description' => $this->t('Control which fields should be checked for emptiness before classification. Format: "field:yes/no" (one per line). Use "*:yes/no" to set default behavior for all fields. "yes" = check if field is empty, "no" = ignore emptiness.'),
      '#default_value' => $classification_settings['specified_field_check'] ?? NULL,
    ];
    $form['classification']['force_field_update'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Force field update rules'),
      '#description' => $this->t('Control which fields should be updated even if already filled. Format: "field:yes/no" (one per line). Use "*:yes/no" to set default behavior for all fields. "yes" = always update field, "no" = only update if empty.'),
      '#default_value' => $classification_settings['force_field_update'] ?? NULL,
    ];
    $form['classification']['classified_fields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Classified field rules'),
      '#description' => $this->t('Control which fields should be updated with classifier results. Format: "field:yes/no" (one per line). Use "*:yes/no" to set the default. "yes" = update field, "no" = skip field.'),
      '#default_value' => $classification_settings['classified_fields'] ?? NULL,
    ];

    $reimport_settings = $form_state->getValue('reimport', $this->getPluginSetting('reimport', [], FALSE));

    $form['reimport'] = [
      '#type' => 'details',
      '#title' => $this->t('Reimport settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['reimport']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('Allow reimporting content.'),
      '#default_value' => !empty($reimport_settings['enabled']),
    ];
    $form['reimport']['type'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reimport type rules'),
      '#description' => $this->t('Control the type of reimport (full, partial, none) based on the entity moderation status. Format: "status:full/partial/none" (one per line). Use "*:full/partial/none" to set default behavior for all statuses. "full" = replace all data like the initial import, "partial" = update only some fields, "none" = skip reimport.'),
      '#default_value' => $reimport_settings['type'] ?? NULL,
    ];
    $form['reimport']['fields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Field update rules'),
      '#description' => $this->t('Control which fields should be updated when reimporting. Format: "field:yes/no" (one per line). Use "*:yes/no" to set default behavior for all fields. "yes" = update field, "no" = skip update.'),
      '#default_value' => $reimport_settings['fields'] ?? NULL,
    ];
    $form['reimport']['statuses'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Moderations status mapping rules'),
      '#description' => $this->t('Control how to change the moderation status when reimporting. Format: "status:other_status" (one per line). Use "*:status" to set default behavior for all statuses.'),
      '#default_value' => $reimport_settings['statuses'] ?? NULL,
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
  public function getEntityTypeId(): string {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityBundle(): string {
    return 'report';
  }

  /**
   * Process a document's data into importable data.
   *
   * @param string $uuid
   *   Document UUID.
   * @param array $document
   *   Raw imported document data.
   *
   * @return array
   *   Data to import.
   */
  abstract protected function processDocumentData(string $uuid, array $document): array;

  /**
   * Get the data to import.
   *
   * @param string $uuid
   *   Item uuid.
   * @param array $document
   *   Raw document data.
   *
   * @return array
   *   Data to import.
   */
  protected function getImportData(string $uuid, array $document): array {
    // If the entity doesn't already exist, then it's the initial import and
    // we just return the processed item data.
    // Otherwise we apply the reimport rules.
    $entity = $this->entityRepository->loadEntityByUuid($this->getEntityTypeId(), $uuid);

    // If the entity was already imported check if reimport is allowed.
    if (!empty($entity) && $this->getPluginSetting('reimport.enabled', FALSE, FALSE) == FALSE) {
      return [];
    }

    // Process the data and filter it out in case of reimport.
    $data = $this->processDocumentData($uuid, $document);
    return empty($entity) ? $data : $this->processReimportData($uuid, $data, $entity);
  }

  /**
   * Process data for a reimport.
   *
   * @param string $uuid
   *   Item uuid.
   * @param array $data
   *   Data to import.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Existing entity.
   *
   * @return array
   *   Filtered data to import.
   */
  protected function processReimportData(string $uuid, array $data, EntityInterface $entity): array {
    $reimport_type_setting = $this->getPluginSetting('reimport.type', '', FALSE);
    $reimport_type_rules = $this->parseReimportType($reimport_type_setting);

    // Skip reimport if there are no rules.
    if (empty($reimport_type_rules)) {
      return [];
    }

    // Get the current moderation status of the entity.
    $status = $entity->getModerationStatus();
    $reimport_type = $reimport_type_rules[$status] ?? $reimport_type_rules['*'] ?? 'none';

    // Skip if reimport is disabled for the moderation status.
    if ($reimport_type === 'none') {
      return [];
    }

    // Use the full import data if the reimport type is full.
    if ($reimport_type === 'full') {
      return $data;
    }

    // Only keep certain fields.
    $field_rules_setting = $this->getPluginSetting('reimport.fields', '', FALSE);
    $field_rules = $this->parseFieldRules($field_rules_setting);

    // No fields to preserve so nothing to reimport.
    if (empty($field_rules)) {
      return [];
    }

    // Only preserve certain fields for the update.
    $filtered_data = [];
    foreach ($data as $field => $value) {
      $update = $field_rules[$field] ?? $field_rules['*'] ?? FALSE;
      if ($update) {
        $filtered_data[$field] = $value;
      }
    }

    // Nothing to reimport.
    if (empty($filtered_data)) {
      return [];
    }

    // Update the moderation status of the current version for the entity.
    $status_mapping_setting = $this->getPluginSetting('reimport.statuses', '', FALSE);
    $status_mapping = $this->parseReimportStatusMapping($status_mapping_setting);
    if (!empty($status_mapping)) {
      // Update the moderation status.
      $status = match(TRUE) {
        !empty($status_mapping[$status]) => $status_mapping[$status],
        !empty($status_mapping['*']) => $status_mapping['*'],
        // No status override, let the content processor decide what to use.
        default => NULL,
      };
      if (isset($status)) {
        $filtered_data['status'] = $status;
      }
    }

    // Set the partial reimport flag.
    $filtered_data['partial'] = TRUE;

    return $filtered_data;
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
    // Skip if classification is not enabled for this plugin.
    $setting = $this->getPluginSetting('classification.enabled', FALSE, FALSE);
    $skip = empty($setting);
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationUserPermissionCheck(bool &$check, AccountInterface $account, array $context): void {
    $setting = $this->getPluginSetting('classification.check_user_permissions', TRUE, FALSE);
    $check = !empty($setting);
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationSpecifiedFieldCheck(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    $setting = $this->getPluginSetting('classification.specified_field_check', '', FALSE);
    $rules = $this->parseFieldRules($setting);

    if (!empty($rules)) {
      foreach ($fields as $field => $check) {
        $fields[$field] = $rules[$field] ?? $rules['*'] ?? $check;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    $setting = $this->getPluginSetting('classification.force_field_update', '', FALSE);
    $rules = $this->parseFieldRules($setting);

    if (!empty($rules)) {
      foreach ($fields as $field => $force_update) {
        $fields[$field] = $rules[$field] ?? $rules['*'] ?? $force_update;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationClassifiedFields(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    $setting = $this->getPluginSetting('classification.classified_fields', '', FALSE);
    $rules = $this->parseFieldRules($setting);

    if (!empty($rules)) {
      foreach ($fields as $type => $field_list) {
        foreach ($field_list as $field => $value) {
          $update = $rules[$field] ?? $rules['*'] ?? TRUE;
          if (!$update) {
            unset($fields[$type][$field]);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterReliefWebEntitiesModerationStatusAdjustment(bool &$bypass, EntityInterface $entity): void {
    // Nothing to do.
  }

  /**
   * Parse field rules string into an associative array.
   *
   * Convert a multi-line string of field rules in the format "field:yes/no"
   * into an associative array with field names as keys and boolean values.
   *
   * @param string $input
   *   Text containing field rules, one per line, in the format "field:yes/no".
   *   The wildcard "*" can be used to set a default rule for all fields.
   *
   * @return array
   *   Associative array where:
   *   - Keys are field names (or "*" for default rule)
   *   - Values are booleans: TRUE for "yes", FALSE for "no"
   *
   * @example
   *   Input:
   *   ```
   *   *:no
   *   field1:yes
   *   field2:yes
   *   ```
   *
   *   Output:
   *   ```
   *   [
   *     '*' => FALSE,
   *     'field1' => TRUE,
   *     'field2' => TRUE,
   *   ]
   *   ```
   */
  protected function parseFieldRules(string $input): array {
    $input = strtolower($input);
    preg_match_all('/^\s*(?<field>[^:]+)\s*:\s*(?<value>yes|no)\s*$/nim', $input, $matches, \PREG_SET_ORDER);

    return array_reduce($matches, function ($result, $match) {
      $result[$match['field']] = ($match['value'] === 'yes');
      return $result;
    }, []);
  }

  /**
   * Parse reimport type rules string into an associative array.
   *
   * @param string $input
   *   Text with reimport type rules, one per line, in the format
   *   "status:full/partial/none".
   *   The wildcard "*" can be used to set a default rule for all statuses.
   *
   * @return array
   *   Associative array where:
   *   - Keys are statuses (or "*" for default rule)
   *   - Values are strings: "full", "partial", "none"
   *
   * @example
   *   Input:
   *   ```
   *   *:full
   *   refused:none
   *   published:partial
   *   ```
   *
   *   Output:
   *   ```
   *   [
   *     '*' => 'full',
   *     'refused' => 'none',
   *     'published' => 'partial',
   *   ]
   *   ```
   */
  protected function parseReimportType(string $input): array {
    $input = strtolower($input);
    preg_match_all('/^\s*(?<status>[^:]+)\s*:\s*(?<value>full|partial|none)\s*$/nim', $input, $matches, \PREG_SET_ORDER);

    return array_reduce($matches, function ($result, $match) {
      $result[$match['status']] = $match['value'];
      return $result;
    }, []);
  }

  /**
   * Parse the reimport status mapping into an associative array.
   *
   * Converts a multi-line string of status to status mapping.
   *
   * @param string $input
   *   Text with status mapping, one per line, in the format "source:target".
   *   The wildcard "*" can be used to set a default mapping for all statuses.
   *
   * @return array
   *   Associative array where:
   *   - Keys are source statuses (or "*" for default rule)
   *   - Values are target statuses
   *
   * @example
   *   Input:
   *   ```
   *   *:pending
   *   published:to-review
   *   ```
   *
   *   Output:
   *   ```
   *   [
   *     '*' => 'pending',
   *     'published' => 'to-review',
   *   ]
   *   ```
   */
  protected function parseReimportStatusMapping(string $input): array {
    $input = strtolower($input);
    preg_match_all('/^\s*(?<source_status>[^:]+)\s*:\s*(?<target_status>\S+)\s*$/nim', $input, $matches, \PREG_SET_ORDER);

    return array_reduce($matches, function ($result, $match) {
      $result[$match['source_status']] = $match['target_status'];
      return $result;
    }, []);
  }

  /**
   * Sanitize a file name.
   *
   * @param string $filename
   *   File name to sanitize.
   * @param array $allowed_extensions
   *   Allowed file name extensions.
   * @param string $default_extension
   *   Default file extension if none could be extracted from the file name.
   *
   * @return string
   *   Sanitized file name.
   *
   * @see \Drupal\system\EventSubscriber\SecurityFileUploadEventSubscriber::sanitizeName()
   */
  protected function sanitizeFileName(string $filename, array $allowed_extensions = [], string $default_extension = 'pdf'): string {
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
    if (!empty($filename_parts)) {
      $extension = strtolower((string) array_pop($filename_parts));
    }
    // Use the default extension if none was found.
    else {
      $extension = $default_extension;
    }

    // Ensure the extension is allowed.
    if (empty($extension) || !in_array($extension, $allowed_extensions)) {
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
   *
   * @todo this assumes report nodes currently. Should be extended.
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
   * Get the list of manually posted documents matching the given urls.
   *
   * @param array $urls
   *   An array of origin URLs to check.
   *
   * @return array
   *   Associative array mapping the origin URLs (keys) to their corresponding
   *   report node IDs (values).
   *
   * @todo this assumes report nodes currently. Should be extended.
   */
  protected function getManuallyPostedDocumentsFromUrls(array $urls): array {
    if (empty($urls)) {
      return [];
    }

    // Query to find existing manually posted records with these origin URLs.
    $query = $this->database->select('node__field_origin_notes', 'fon');
    $query->addField('fon', 'field_origin_notes_value', 'url');
    $query->addField('fon', 'entity_id', 'entity_id');
    $query->condition('fon.bundle', 'report', '=');
    $query->condition('fon.field_origin_notes_value', $urls, 'IN');

    // Join the provider table. Manually posted content do not have one.
    $query->leftJoin('node__field_post_api_provider', 'fpap', '%alias.entity_id = fon.entity_id');
    $query->isNull('fpap.field_post_api_provider_target_id');

    // Get the list of records keyed by the original URL.
    return $query->execute()?->fetchAllKeyed() ?? [];
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

    // Deserialize the extra field.
    foreach ($records as &$record) {
      if (isset($record['extra'])) {
        $record['extra'] = json_decode($record['extra'], TRUE);
      }
    }

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

      // Serialize json data.
      if (isset($record['extra'])) {
        $record['extra'] = json_encode($record['extra']);
      }

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

  /**
   * Get the checksum and filename of a remote file.
   *
   * @param string $url
   *   Remote file URL.
   * @param string $default_extension
   *   Default file extension if none could be extracted from the file name.
   * @param ?string $bytes
   *   Raw bytes of the file content.
   *
   * @return array
   *   Checksum, filename and raw bytes of the remote file.
   */
  protected function getRemoteFileInfo(string $url, string $default_extension = 'pdf', ?string $bytes = NULL): array {
    $max_size = $this->getReportAttachmentAllowedMaxSize();
    if (empty($max_size)) {
      throw new InvalidConfigurationException('No allowed file max size.');
    }

    $allowed_extensions = $this->getReportAttachmentAllowedExtensions();
    if (empty($allowed_extensions)) {
      throw new InvalidConfigurationException('No allowed file extensions.');
    }

    // Support raw bytes.
    if (!empty($bytes)) {
      // Validate the size.
      if ($max_size > 0 && strlen($bytes) > $max_size) {
        throw new ReliefwebImportExceptionFileTooBig('File is too large.');
      }

      // Sanitize the file name.
      $extracted_filename = basename($url);
      $filename = $this->sanitizeFileName($extracted_filename, $allowed_extensions, $default_extension);
      if (empty($filename)) {
        throw new ReliefwebImportExceptionIllegalFilename(strtr('Invalid filename: @filename.', [
          '@filename' => $extracted_filename,
        ]));
      }

      // Compute the checksum.
      $checksum = hash('sha256', $bytes);

      return [
        'checksum' => $checksum,
        'filename' => $filename,
        'bytes' => $bytes,
      ];
    }

    $body = NULL;

    // Remote file.
    try {
      $response = $this->httpClient->get($url, [
        'stream' => TRUE,
        // @todo retrieve that from the configuration.
        'connect_timeout' => 30,
        'timeout' => 600,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
          'Accept' => '*/*',
        ],
      ]);

      if ($response->getStatusCode() == 406) {
        // Stream not supported.
        $response = $this->httpClient->get($url, [
          'stream' => FALSE,
          // @todo retrieve that from the configuration.
          'connect_timeout' => 30,
          'timeout' => 600,
          'headers' => [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
            'Accept' => '*/*',
          ],
        ]);
      }

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Unexpected HTTP status: ' . $response->getStatusCode());
      }

      $content_length = $response->getHeaderLine('Content-Length');
      if ($content_length !== '' && $max_size > 0 && ((int) $content_length) > $max_size) {
        throw new \Exception('File is too large.');
      }

      // Try to get the filename from the Content Disposition header.
      $content_disposition = $response->getHeaderLine('Content-Disposition') ?? '';
      $extracted_filename = UrlHelper::getFilenameFromContentDisposition($content_disposition);

      // Fallback to the URL if no filename is provided.
      if (empty($extracted_filename)) {
        $matches = [];
        $clean_url = UrlHelper::stripParametersAndFragment($url);
        if (preg_match('/\/([^\/]+)$/', $clean_url, $matches) === 1) {
          $extracted_filename = rawurldecode($matches[1]);
        }
        else {
          throw new \Exception('Unable to retrieve file name.');
        }
      }

      // Sanitize the file name.
      $filename = $this->sanitizeFileName($extracted_filename, $allowed_extensions, $default_extension);
      if (empty($filename)) {
        throw new \Exception(strtr('Invalid filename: @filename.', [
          '@filename' => $extracted_filename,
        ]));
      }

      $body = $response->getBody();

      $content = '';
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

      $checksum = hash('sha256', $content);
    }
    catch (\Exception $exception) {
      $this->getLogger()->notice(strtr('Unable to retrieve file information for @url: @exception', [
        '@url' => $url,
        '@exception' => $exception->getMessage(),
      ]));
      return [];
    }
    finally {
      if (isset($body)) {
        $body->close();
      }
    }

    return [
      'checksum' => $checksum,
      'filename' => $filename,
      // Return the raw bytes so that we don't have to download the file again
      // in the post api content processor.
      'bytes' => $content,
    ];
  }

  /**
   * Filters an associative array using dot notation with wildcard support.
   *
   * This function allows retrieving all values for a specific key within nested
   * arrays without specifying individual array indices.
   *
   * @param array $data
   *   The original associative array to filter.
   * @param array $paths
   *   Array of property paths in dot notation format to keep.
   *
   * @return array
   *   The filtered array containing only the specified keys.
   */
  protected function filterArrayByKeys(array $data, array $paths): array {
    $result = [];

    foreach ($paths as $path) {
      // Split the dot notation path into segments.
      $segments = explode('.', $path);

      // Extract values based on the dot path and add to result.
      $this->extractNestedValues($data, $segments, $result);
    }

    return $result;
  }

  /**
   * Recursively extracts values from nested arrays.
   *
   * @param array $source
   *   The source array to extract values from.
   * @param array $segments
   *   The remaining segments of the dot notation path.
   * @param array &$target
   *   The target array where extracted values will be stored.
   * @param array $current_path
   *   The current path in the target array.
   */
  protected function extractNestedValues(array $source, array $segments, array &$target, array $current_path = []): void {
    // Get the current segment and remaining segments.
    $current_segment = array_shift($segments);

    if (empty($current_segment)) {
      return;
    }

    // If this is a direct key in the source array.
    if (isset($source[$current_segment])) {
      $value = $source[$current_segment];

      // If we're at the last segment, add the value to the result.
      if (empty($segments)) {
        $this->setNestedValue($target, [...$current_path, $current_segment], $value);
        return;
      }

      // If value is an array, continue recursion.
      if (is_array($value)) {
        // If the value is a list of arrays, process each item.
        if (array_is_list($value)) {
          foreach ($value as $index => $item) {
            if (is_array($item)) {
              // Process each item with the remaining segments.
              $this->extractNestedValues($item, $segments, $target, [...$current_path, $current_segment, $index]);
            }
          }
        }
        else {
          // Continue with the next segment for associative arrays.
          $this->extractNestedValues($value, $segments, $target, [...$current_path, $current_segment]);
        }
      }
    }
  }

  /**
   * Sets a nested value in an array based on a path.
   *
   * @param array &$array
   *   The array to modify.
   * @param array $path
   *   The path to set the value at.
   * @param mixed $value
   *   The value to set.
   */
  protected function setNestedValue(array &$array, array $path, mixed $value): void {
    $current = &$array;

    foreach ($path as $key) {
      if (!isset($current[$key]) || !is_array($current[$key])) {
        $current[$key] = [];
      }
      $current = &$current[$key];
    }

    $current = $value;
  }

  /**
   * Find country by iso code.
   */
  protected function getCountryByIso(string $iso3): ?int {
    if (empty($iso3)) {
      return 254;
    }

    static $country_mapping = [];
    if (empty($country_mapping)) {
      $countries = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'country']);
      foreach ($countries as $country) {
        $country_mapping[strtolower($country->get('field_iso3')->value)] = (int) $country->id();
      }
    }

    $iso3 = strtolower($iso3);
    return $country_mapping[$iso3] ?? 254;
  }

  /**
   * Find country by name.
   */
  protected function getCountryByName(string $name): ?int {
    if (empty($name)) {
      return 254;
    }

    static $country_mapping = [];
    if (empty($country_mapping)) {
      $countries = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'country']);
      foreach ($countries as $country) {
        $country_mapping[strtolower($country->label())] = (int) $country->id();
      }
    }

    $name = strtolower($name);
    return $country_mapping[$name] ?? 254;
  }

  /**
   * Find source by name or short name.
   */
  protected function getSourceByName(string $name): ?int {
    if (empty($iso3)) {
      return 0;
    }

    static $source_mapping = [];
    if (empty($source_mapping)) {
      $sources = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'source']);
      foreach ($sources as $source) {
        $source_mapping[strtolower($source->label())] = (int) $source->id();
        if ($source->hasField('field_shortname') && !$source->get('field_shortname')->isEmpty()) {
          $source_mapping[strtolower($source->get('field_shortname')->value)] = (int) $source->id();
        }
      }
    }

    $name = strtolower($name);
    return $source_mapping[$name] ?? 0;
  }

}
