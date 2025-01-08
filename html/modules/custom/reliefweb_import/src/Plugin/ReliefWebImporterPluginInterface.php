<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Psr\Log\LoggerInterface;

/**
 * Interface for the importer plugins.
 */
interface ReliefWebImporterPluginInterface {

  /**
   * Get the plugin label.
   *
   * @return string
   *   The plugin label.
   */
  public function getPluginLabel(): string;

  /**
   * Get the plugin type.
   *
   * @return string
   *   The plugin type.
   */
  public function getPluginType(): string;

  /**
   * Check if the plugin is enabled.
   *
   * @return bool
   *   TRUE if enabled.
   */
  public function enabled(): bool;

  /**
   * Get the plugin logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   Logger.
   */
  public function getLogger(): LoggerInterface;

  /**
   * Get a plugin setting.
   *
   * @param string $key
   *   The setting name. It can be nested in the form "a.b.c" to retrieve "c".
   * @param mixed $default
   *   Default value if the setting is missing.
   * @param bool $throw_if_null
   *   If TRUE and both the setting and default are NULL then an exception
   *   is thrown. Use this for example for mandatory settings.
   *
   * @return mixed
   *   The plugin setting for the key or the provided default.
   *
   * @throws \Drupal\reliefweb_import\Exception\InvalidConfigurationException
   *   Throws an exception if no setting could be found (= NULL).
   */
  public function getPluginSetting(string $key, mixed $default = NULL, bool $throw_if_null = TRUE): mixed;

  /**
   * Load the plugin configuration.
   *
   * @return array
   *   The plugin configuration.
   */
  public function loadConfiguration(): array;

  /**
   * Save the plugin configuration.
   *
   * @param array $configuration
   *   The plugin configuration to save.
   */
  public function saveConfiguration(array $configuration): void;

  /**
   * Get the name of the configuration for this plugin.
   *
   * @return string
   *   Configuration name.
   */
  public function getConfigurationKey(): string;

  /**
   * Import newest and update content.
   *
   * @param int $limit
   *   Number of documents to import at once (batch).
   *
   * @return bool
   *   TRUE if the batch import was successful.
   */
  public function importContent(int $limit = 50): bool;

  /**
   * Get the list of allowed extensions for the report attachments.
   *
   * @return array
   *   List of allowed extensions.
   */
  public function getReportAttachmentAllowedExtensions(): array;

  /**
   * Get the allowed max size of the report attachments.
   *
   * @return int
   *   Allowed max size in bytes.
   */
  public function getReportAttachmentAllowedMaxSize(): int;

  /**
   * Retrieve a Post API schema.
   *
   * @param string $bundle
   *   Resource bundle.
   *
   * @return string
   *   Schema.
   */
  public function getJsonSchema(string $bundle): string;

  /**
   * Generate a UUID for a string (ex: URL).
   *
   * @param string $string
   *   String for which to generate a UUID.
   * @param string|null $namespace
   *   Optional namespace. Defaults to `Uuid::NAMESPACE_URL`.
   *
   * @return string
   *   UUID.
   */
  public function generateUuid(string $string, ?string $namespace = NULL): string;

}
