<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\State\StateInterface;
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
    /* The default namespace is the UUID generated with
     * Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), 'reliefweb.int')->toRfc4122(); */
    $namespace = $namespace ?? '8e27a998-c362-5d1f-b152-d474e1d36af2';
    return Uuid::v5(Uuid::fromString($namespace), $string)->toRfc4122();
  }

  /**
   * {@inheritdoc}
   */
  public function skipContentClassification(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationSpecifiedFieldCheck(array &$fields): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields): void {
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

}
