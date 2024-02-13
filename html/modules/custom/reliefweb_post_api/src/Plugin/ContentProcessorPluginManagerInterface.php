<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin;

/**
 * Interface for a Content Processor plugin manager.
 */
interface ContentProcessorPluginManagerInterface {

  /**
   * Get the instance of the plugin with the given ID.
   *
   * @param string $plugin_id
   *   Plugin ID.
   *
   * @return \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface|null
   *   An instance of the plugin or NULL if none was found.
   */
  public function getPlugin(string $plugin_id): ?ContentProcessorPluginInterface;

  /**
   * Get a plugin by bundle.
   *
   * @return \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface|null
   *   An instance of the plugin or NULL if none was found.
   */
  public function getPluginByBundle(string $bundle): ?ContentProcessorPluginInterface;

}
