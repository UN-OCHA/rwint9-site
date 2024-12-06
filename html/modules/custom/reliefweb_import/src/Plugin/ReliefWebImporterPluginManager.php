<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;

/**
 * Plugin manager for the ReliefWeb importer plugins.
 */
class ReliefWebImporterPluginManager extends DefaultPluginManager implements ReliefWebImporterPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ReliefWebImporter',
      $namespaces,
      $module_handler,
      ReliefWebImporterPluginInterface::class,
      ReliefWebImporter::class
    );

    $this->setCacheBackend($cache_backend, 'reliefweb_import_reliefweb_importer_plugins');
    $this->alterInfo('reliefweb_import_reliefweb_importer_info');
  }

}
