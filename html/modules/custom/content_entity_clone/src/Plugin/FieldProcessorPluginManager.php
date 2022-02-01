<?php

namespace Drupal\content_entity_clone\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Plugin manager for the content entity clone field processor plugins.
 */
class FieldProcessorPluginManager extends DefaultPluginManager implements FieldProcessorPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/content_entity_clone/FieldProcessor',
      $namespaces,
      $module_handler,
      'Drupal\content_entity_clone\Plugin\FieldProcessorPluginInterface',
      'Drupal\content_entity_clone\Annotation\ContentEntityCloneFieldProcessor'
    );

    $this->setCacheBackend($cache_backend, 'content_entity_clone_field_processor_plugins');
    $this->alterInfo('content_entity_clone_field_processor_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailablePlugins(FieldDefinitionInterface $field_definition) {
    $plugins = [];
    foreach ($this->getDefinitions() as $plugin_id => $plugin_definition) {
      $plugin = $this->createInstance($plugin_id, $plugin_definition);
      if ($plugin->supports($field_definition)) {
        $plugins[] = $plugin;
      }
    }
    return $plugins;
  }

  /**
   * {@inheritdoc}
   */
  public function processField($plugin_id, FieldItemListInterface $field) {
    $plugin_definition = $this->getDefinition($plugin_id, FALSE);
    if (!empty($plugin_definition)) {
      $plugin = $this->createInstance($plugin_id, $plugin_definition);
      $plugin->process($field);
    }
  }

}
