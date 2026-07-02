<?php

namespace Drupal\reliefweb_guidelines\Commands;

use Drupal\reliefweb_guidelines\Services\GuidelineMigrationService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for guideline migration.
 */
class ReliefWebGuidelinesCommands extends DrushCommands {

  /**
   * Constructs ReliefWebGuidelinesCommands.
   */
  public function __construct(
    protected GuidelineMigrationService $migrationService,
  ) {
    parent::__construct();
  }

  /**
   * Migrate legacy guideline entities to nodes and taxonomy terms.
   *
   * @param array $options
   *   Command options.
   *
   * @command reliefweb:guidelines-migrate
   * @aliases rw-guidelines-migrate
   * @option dry-run Report what would be migrated without saving.
   * @option verify Compare legacy and migrated entity counts after migration.
   * @option force Re-run migration, rolling back previous migrated copies first.
   * @usage drush reliefweb:guidelines-migrate --dry-run
   * @usage drush reliefweb:guidelines-migrate --verify
   */
  public function migrate(
    array $options = [
      'dry-run' => FALSE,
      'verify' => FALSE,
      'force' => FALSE,
    ],
  ): void {
    $progress = NULL;
    if ($this->output()->isVerbose()) {
      $progress = fn (string $message) => $this->logger()->notice($message);
    }

    try {
      $summary = $this->migrationService->migrate(
        (bool) $options['dry-run'],
        (bool) $options['verify'],
        (bool) $options['force'],
        $progress,
      );
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return;
    }

    if (!empty($summary['dry_run'])) {
      $this->io()->writeln(sprintf(
        'Dry run: would migrate %d lists and %d guidelines.',
        $summary['lists'],
        $summary['guidelines'],
      ));
      return;
    }

    $this->io()->success(sprintf(
      'Migrated %d lists, %d guidelines (%d revisions).',
      $summary['lists_created'],
      $summary['guidelines_created'],
      $summary['revisions_created'] ?? 0,
    ));

    if (!empty($summary['verify'])) {
      $this->printVerification($summary['verify']);
    }
  }

  /**
   * Roll back migrated guideline nodes and taxonomy terms.
   *
   * @param array $options
   *   Command options.
   *
   * @command reliefweb:guidelines-migrate-rollback
   * @aliases rw-guidelines-migrate-rollback
   * @option orphans Delete all migrated guideline nodes/terms when state is missing or incomplete (dev recovery).
   * @usage drush reliefweb:guidelines-migrate-rollback
   * @usage drush reliefweb:guidelines-migrate-rollback --orphans
   */
  public function rollback(
    array $options = [
      'orphans' => FALSE,
    ],
  ): void {
    try {
      if (!empty($options['orphans'])) {
        $summary = $this->migrationService->rollbackOrphans();
      }
      else {
        $summary = $this->migrationService->rollback();
      }
    }
    catch (\RuntimeException $exception) {
      $this->logger()->error($exception->getMessage());
      return;
    }

    $this->io()->success(sprintf(
      'Rolled back %d guideline nodes and %d guideline list terms.',
      $summary['deleted_nodes'],
      $summary['deleted_terms'],
    ));

    if (!empty($summary['restored_aliases'])) {
      $this->io()->writeln(sprintf('Restored %d legacy path aliases.', $summary['restored_aliases']));
    }
  }

  /**
   * Print verification output.
   */
  protected function printVerification(array $verify): void {
    $this->io()->section('Verification');
    $this->io()->writeln(sprintf('Legacy lists: %d', $verify['legacy_lists']));
    $this->io()->writeln(sprintf('Migrated lists: %d', $verify['migrated_lists']));
    $this->io()->writeln(sprintf('Legacy guidelines: %d', $verify['legacy_guidelines']));
    $this->io()->writeln(sprintf('Migrated guidelines: %d', $verify['migrated_guidelines']));
    $this->io()->writeln(sprintf('Legacy revisions: %d', $verify['legacy_revisions'] ?? 0));
    $this->io()->writeln(sprintf('Migrated revisions: %d', $verify['migrated_revisions'] ?? 0));

    if ($verify['lists_match'] && $verify['guidelines_match']) {
      $this->io()->success('Entity counts match.');
    }
    else {
      $this->io()->warning('Entity counts do not match.');
    }

    if (!empty($verify['revisions_match'])) {
      $this->io()->success('Revision counts match.');
    }
    elseif (isset($verify['revisions_match'])) {
      $this->io()->warning('Revision counts do not match.');
    }
  }

}
