<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Psr\Log\LoggerInterface;

/**
 * Interface for the importer plugins.
 */
interface ImporterPluginInterface {

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
   * Import newest and update content.
   *
   * @param int $limit
   *   Number of documents to import at once (batch).
   *
   * @return bool
   *   TRUE if the batch import was successful.
   */
  public function importContent(int $limit = 50): bool;

}
