<?php

declare(strict_types=1);

namespace Drupal\reliefweb_users\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_users\Service\InactiveUserDeletionServiceInterface;
use Drush\Commands\AutowireTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drush command to delete inactive ReliefWeb user accounts.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Delete inactive user accounts with no ReliefWeb activity.',
  aliases: ['reliefweb-users-delete-inactive', 'rw-users-delete-inactive'],
)]
final class DeleteInactiveUsersCommand extends Command {

  use AutowireTrait;

  /**
   * Command name.
   */
  public const NAME = 'reliefweb_users:delete-inactive';

  /**
   * Constructs a DeleteInactiveUsersCommand object.
   */
  public function __construct(
    private readonly InactiveUserDeletionServiceInterface $inactiveUserDeletion,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption(
        'weeks',
        NULL,
        InputOption::VALUE_REQUIRED,
        'Minimum number of weeks since last activity (last access, or account creation if never accessed).',
        '52',
      )
      ->addOption(
        'limit',
        NULL,
        InputOption::VALUE_REQUIRED,
        'Maximum number of accounts to process per run.',
        '100',
      )
      ->addOption(
        'dry-run',
        NULL,
        InputOption::VALUE_NONE,
        'List candidates without deleting.',
      )
      ->addOption(
        'sort',
        NULL,
        InputOption::VALUE_REQUIRED,
        'Process order by effective activity: oldest or newest.',
        InactiveUserDeletionServiceInterface::SORT_OLDEST,
      )
      ->addUsage('reliefweb_users:delete-inactive --weeks=26 --limit=50 --dry-run')
      ->addUsage('reliefweb_users:delete-inactive --weeks=52 --limit=100')
      ->addUsage('reliefweb_users:delete-inactive --weeks=26 --limit=50 --sort=newest --dry-run');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    if (!empty($this->state->get('system.maintenance_mode', 0))) {
      $io->warning('Maintenance mode, aborting.');
      return Command::FAILURE;
    }

    $weeks = (int) $input->getOption('weeks');
    if ($weeks < 1) {
      $io->error('Weeks must be at least 1.');
      return Command::FAILURE;
    }

    $limit = (int) $input->getOption('limit');
    if ($limit < 1) {
      $io->error('Limit must be at least 1.');
      return Command::FAILURE;
    }

    $dry_run = (bool) $input->getOption('dry-run');
    $sort = strtolower((string) $input->getOption('sort'));
    if ($sort !== InactiveUserDeletionServiceInterface::SORT_OLDEST && $sort !== InactiveUserDeletionServiceInterface::SORT_NEWEST) {
      $io->error(strtr('Sort must be "@oldest" or "@newest".', [
        '@oldest' => InactiveUserDeletionServiceInterface::SORT_OLDEST,
        '@newest' => InactiveUserDeletionServiceInterface::SORT_NEWEST,
      ]));
      return Command::FAILURE;
    }

    $cutoff = $this->inactiveUserDeletion->getCutoffTimestamp($weeks);
    $candidates = $this->inactiveUserDeletion->findCandidateUids($weeks, $limit, $sort);

    if (empty($candidates)) {
      $io->success("No inactive user accounts found (inactive >= {$weeks} weeks).");
      return Command::SUCCESS;
    }

    $deleted = 0;
    $skipped = 0;

    foreach ($candidates as $candidate) {
      $uid = $candidate['uid'];
      $mail = $candidate['mail'] ?? '';
      $activity = $this->inactiveUserDeletion->formatActivityForLog(
        $candidate['access'],
        $candidate['created'],
      );

      // Check if the user is still eligible for deletion, in case it was
      // modified between now and the time we retrieved the candidate list.
      if (!$this->inactiveUserDeletion->isEligible($uid, $cutoff)) {
        $skipped++;
        $this->logger->warning('Skipped uid {uid} ({mail}): no longer eligible.', [
          'uid' => $uid,
          'mail' => $mail,
        ]);
        continue;
      }

      if ($dry_run) {
        $this->logger->info('Would delete uid {uid} ({mail}), last activity {activity}.', [
          'uid' => $uid,
          'mail' => $mail,
          'activity' => $activity,
        ]);
        $deleted++;
        continue;
      }

      if ($this->inactiveUserDeletion->deleteUser($uid, $cutoff)) {
        $deleted++;
        $this->logger->info('Deleted uid {uid} ({mail}), last activity {activity}.', [
          'uid' => $uid,
          'mail' => $mail,
          'activity' => $activity,
        ]);
      }
      else {
        $skipped++;
        $this->logger->warning('Failed to delete uid {uid} ({mail}).', [
          'uid' => $uid,
          'mail' => $mail,
        ]);
      }
    }

    if ($dry_run) {
      $io->success("Dry run: {$deleted} candidate(s) would be deleted (inactive >= {$weeks} weeks).");
    }
    else {
      $io->success("Deleted {$deleted} account(s), skipped {$skipped} (inactive >= {$weeks} weeks).");
    }

    return Command::SUCCESS;
  }

}
