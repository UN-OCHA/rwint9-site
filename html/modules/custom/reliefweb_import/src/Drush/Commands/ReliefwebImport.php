<?php

namespace Drupal\reliefweb_import\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\reliefweb_import\Service\JobFeedsImporterInterface;
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
  ) {}

  /**
   * Import jobs.
   *
   * @param int $limit
   *   Max number of items to send.
   *
   * @command reliefweb_import:jobs
   * @usage reliefweb_import:jobs
   *   Send emails.
   * @validate-module-enabled reliefweb_import
   * @aliases reliefweb-import-jobs
   */
  public function jobs(int $limit = 50): void {
    $this->jobImporter->importJobs($limit);
  }

}
