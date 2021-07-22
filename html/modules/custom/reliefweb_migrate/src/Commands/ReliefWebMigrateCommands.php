<?php

namespace Drupal\reliefweb_migrate\Commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManager;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb migration Drush commandfile.
 */
class ReliefWebMigrateCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;

  /**
   * Config Installer.
   *
   * @var Drupal\Core\Config\ConfigInstallerInterface
   */
  protected $configInstaller;

  /**
   * Config Manager.
   *
   * @var Drupal\Core\Config\ConfigManager
   */
  protected $configManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigInstallerInterface $config_installer, ConfigManager $config_manager) {
    $this->configInstaller = $config_installer;
    $this->configManager = $config_manager;
  }

  /**
   * Reload ReliefWeb migration configurations.
   *
   * @command rw-migrate:reload-configuration
   * @aliases rw-mrc,rw-migrate-reload-configuration
   * @usage rw-migrate:reload-configuration
   *   Reload all ReliefWeb migration configurations.
   * @validate-module-enabled reliefweb_migrate
   */
  public function reloadConfiguration() {
    // Uninstall and reinstall all configuration.
    $this->configManager->uninstall('module', 'reliefweb_migrate');
    $this->configInstaller->installDefaultConfig('module', 'reliefweb_migrate');

    // Rebuild cache.
    $process = $this->processManager()->drush($this->siteAliasManager()->getSelf(), 'cache-rebuild');
    $process->mustrun();

    $this->logger()->success(dt('Config reload complete.'));
    return TRUE;
  }

}
