<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\reliefweb_import\Attribute\Importer;

/**
 * Plugin manager for the importer plugins.
 */
class ImporterPluginManager extends DefaultPluginManager implements ImporterPluginManagerInterface {

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
      ImporterPluginInterface::class,
      Importer::class
    );

    $this->setCacheBackend($cache_backend, 'reliefweb_import_reliefweb_importer_plugins');
    $this->alterInfo('reliefweb_import_reliefweb_importer_info');
  }

}
