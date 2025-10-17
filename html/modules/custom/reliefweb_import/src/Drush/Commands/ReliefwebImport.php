<?php

namespace Drupal\reliefweb_import\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Core\Config\ConfigFactory;
use Drupal\reliefweb_import\Plugin\ReliefwebImporterPluginManagerInterface;
use Drupal\reliefweb_import\Service\JobFeedsImporterInterface;
use Drupal\reliefweb_import\Service\WorkDayJobImporter;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb Import Drush commandfile.
 */
class ReliefwebImport extends DrushCommands implements SiteAliasManagerAwareInterface {

  // Drush traits.
  use ProcessManagerAwareTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected JobFeedsImporterInterface $jobImporter,
    protected WorkDayJobImporter $workdayJobImporter,
    protected ReliefwebImporterPluginManagerInterface $importerPluginManager,
    protected ConfigFactory $configFactory,
  ) {}

  /**
   * Import jobs.
   *
   * @param int $limit
   *   Max number of items to import.
   *
   * @command reliefweb_import:jobs
   *
   * @usage reliefweb_import:jobs
   *   Import jobs.
   *
   * @validate-module-enabled reliefweb_import
   * @aliases reliefweb-import-jobs
   */
  public function importJobs(int $limit = 50): void {
    $this->jobImporter->importJobs($limit);
  }

  /**
   * Import workday jobs.
   *
   * @param int $limit
   *   Max number of items to import.
   *
   * @command reliefweb_import:workday
   *
   * @usage reliefweb_import:workday
   *   Import workday jobs.
   *
   * @validate-module-enabled reliefweb_import
   * @aliases reliefweb-import-workday
   */
  public function importWorkdayJobs(int $limit = 50): void {
    // Load configuration.
    $tenants_config = $this->configFactory->listAll('reliefweb_import.workday.');

    foreach ($tenants_config as $name) {
      if ($name === 'reliefweb_import.workday') {
        // Skip the base config.
        continue;
      }

      $config = $this->configFactory->get($name)->get();

      // Skip if not enabled.
      if (empty($config['enabled'])) {
        $this->logger()->info(strtr('Skipping import for WorkDay tenant: @name.', [
          '@name' => $config['name'] ?? $name,
        ]));
        continue;
      }

      $this->logger()->info(strtr('Starting import for WorkDay tenant: @name.', [
        '@name' => $config['name'] ?? $name,
      ]));

      try {
        $this->workdayJobImporter->setSettings($config);
        $this->workdayJobImporter->validateSettings();
        $this->workdayJobImporter->importJobs($limit);
      }
      catch (\Exception $e) {
        $this->logger()->error('Import complete: something went wrong.');
        $this->logger()->error(strtr('WorkDay tenant: @name import failed with error: @message', [
          '@name' => $config['name'] ?? $name,
          '@message' => $e->getMessage(),
        ]));
        continue;
      }
    }

    $this->logger()->info('Import complete: job well done.');
  }

  /**
   * Import content.
   *
   * @param string $plugin_id
   *   ID of the importer plugin to use.
   * @param int $limit
   *   Max number of items to import.
   *
   * @command reliefweb_import:content
   *
   * @usage reliefweb_import:content test 10
   *  Import 10 documents from the 'test' importer plugin.
   *
   * @validate-module-enabled reliefweb_import
   * @aliases reliefweb-import-content
   *
   * @todo allow passing 'all' to import content from all the enabled plugins.
   */
  public function import(string $plugin_id, int $limit = 50): bool {
    if (!$this->importerPluginManager->hasDefinition($plugin_id)) {
      $this->logger()->error(strtr('Unknown importer plugin: @plugin_id.', [
        '@plugin_id' => $plugin_id,
      ]));
      return FALSE;
    }

    /** @var ?\Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginInterface $plugin */
    $plugin = $this->importerPluginManager->getPlugin($plugin_id);
    if (empty($plugin)) {
      $this->logger()->error(strtr('Unable to create importer plugin: @plugin_id.', [
        '@plugin_id' => $plugin_id,
      ]));
      return FALSE;
    }

    if (!$plugin->enabled()) {
      $this->logger()->error(strtr('Importer plugin: @plugin_id not enabled.', [
        '@plugin_id' => $plugin_id,
      ]));
      return TRUE;
    }

    $this->logger()->info(strtr('Starting import plugin: @plugin_id.', [
      '@plugin_id' => $plugin_id,
    ]));

    try {
      $plugin->importContent($limit);
    }
    catch (\Exception $e) {
      $this->logger()->error('Import complete: something went wrong.');
      $this->logger()->error(strtr('Importer plugin: @plugin_id setup failed with error: @message', [
        '@plugin_id' => $plugin_id,
        '@message' => $e->getMessage(),
      ]));
      return FALSE;
    }

    $this->logger()->info('Import complete: job well done.');
    return TRUE;
  }

}
