<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\reliefweb_import\Exception\InvalidConfigurationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base importer plugin class.
 */
abstract class ImporterPluginBase extends PluginBase implements ImporterPluginInterface, ContainerFactoryPluginInterface, PluginFormInterface, ConfigurableInterface {

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
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
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
      $container->get('logger.factory'),
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
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
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
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [];
  }

}
