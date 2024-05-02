<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;

/**
 * Provides a Content Processor plugin manager.
 *
 * @see \Drupal\reliefweb_post_api\Attribute\ContentProcessor
 * @see \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface
 * @see plugin_api
 */
class ContentProcessorPluginManager extends DefaultPluginManager implements ContentProcessorPluginManagerInterface {

  /**
   * Static cache for the plugin instances.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\PluginInterface[]
   */
  protected array $instances = [];

  /**
   * Constructs a new class instance.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/reliefweb_post_api/ContentProcessor',
      $namespaces,
      $module_handler,
      'Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface',
      ContentProcessor::class,
    );
    $this->alterInfo('reliefweb_post_api_content_processor_info');
    $this->setCacheBackend($cache_backend, 'reliefweb_post_api_content_processors');
  }

  /**
   * {@inheritdoc}
   */
  public function getPlugin(string $plugin_id): ?ContentProcessorPluginInterface {
    if (!array_key_exists($plugin_id, $this->instances)) {
      try {
        $this->instances[$plugin_id] = $this->createInstance($plugin_id);
      }
      catch (\Exception $exception) {
        $this->instances[$plugin_id] = NULL;
      }
    }
    return $this->instances[$plugin_id];
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginFromProperty(string $property, string $value): ?ContentProcessorPluginInterface {
    if ($value === '') {
      return NULL;
    }
    foreach ($this->getDefinitions() as $id => $definition) {
      if (isset($definition[$property]) && $definition[$property] === $value) {
        return $this->getPlugin($id);
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginByBundle(string $bundle): ?ContentProcessorPluginInterface {
    return $this->getPluginFromProperty('bundle', $bundle);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginByResource(string $resource): ?ContentProcessorPluginInterface {
    return $this->getPluginFromProperty('resource', $resource);
  }

}
