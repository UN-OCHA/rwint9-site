<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

/**
 * Interface for the importer plugin manager.
 */
interface ReliefWebImporterPluginManagerInterface {

  /**
   * Get a pre-configured plugin instance.
   *
   * @param string $plugin_id
   *   Plugin ID.
   *
   * @return ?\Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginInterface
   *   The plugin instance if it could be created.
   */
  public function getPlugin(string $plugin_id): ?ReliefWebImporterPluginInterface;

}
