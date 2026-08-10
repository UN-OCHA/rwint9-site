<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Drush\Commands;

use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingGenerateOptions;
use Drupal\reliefweb_content_analyzer\Services\EmbeddingGenerator;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generate / upsert content embeddings into configured storage.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Generate content embeddings for configured entity bundles.',
  aliases: ['rwca:embed'],
)]
final class GenerateEmbeddingsCommand extends Command {

  use AutowireTrait;

  /**
   * Command name.
   */
  public const NAME = 'reliefweb_content_analyzer:generate-embeddings';

  /**
   * Constructs GenerateEmbeddingsCommand.
   *
   * @param \Drupal\reliefweb_content_analyzer\Services\EmbeddingGenerator $generator
   *   Embedding generator service.
   */
  public function __construct(
    private readonly EmbeddingGenerator $generator,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption('entity-type', NULL, InputOption::VALUE_REQUIRED, 'Entity type ID.', 'node')
      ->addOption('bundle', NULL, InputOption::VALUE_REQUIRED, 'Bundle.', 'report')
      ->addOption('fields', NULL, InputOption::VALUE_REQUIRED, 'Comma-separated fields (title,body,field_file). Default: config source fields.')
      ->addOption('min-length', NULL, InputOption::VALUE_REQUIRED, 'Minimum text length. Default: config.')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Max entities (0 = all).', '0')
      ->addOption('batch', NULL, InputOption::VALUE_REQUIRED, 'HTTP batch size.', '8')
      ->addOption('sort', NULL, InputOption::VALUE_REQUIRED, 'asc|desc by entity id.', 'desc')
      ->addOption('ids', NULL, InputOption::VALUE_REQUIRED, 'Comma-separated entity IDs.')
      ->addOption('min-id', NULL, InputOption::VALUE_REQUIRED, 'Minimum entity ID (inclusive).')
      ->addOption('max-id', NULL, InputOption::VALUE_REQUIRED, 'Maximum entity ID (inclusive).')
      ->addOption('skip-existing', NULL, InputOption::VALUE_REQUIRED, 'id|hash|no.', 'id')
      ->addOption('endpoint', NULL, InputOption::VALUE_REQUIRED, 'Embed endpoint URL. Default: config.')
      ->addOption('timeout', NULL, InputOption::VALUE_REQUIRED, 'HTTP timeout seconds. Default: config.')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Prepare only; no HTTP or writes.')
      ->addUsage('reliefweb_content_analyzer:generate-embeddings --limit=100')
      ->addUsage('rwca:embed --fields=body --skip-existing=hash --limit=50 --sort=desc')
      ->addUsage('rwca:embed --ids=4212273,4212205 --skip-existing=no');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $settings = $this->generator->settings();

    $entity_type = (string) $input->getOption('entity-type');
    $bundle = (string) $input->getOption('bundle');
    $source = $settings->getSource($entity_type, $bundle);

    $fields_option = $input->getOption('fields');
    $fields_explicit = is_string($fields_option) && trim($fields_option) !== '';
    if ($fields_explicit) {
      $fields = array_values(array_filter(array_map('trim', explode(',', (string) $fields_option))));
    }
    else {
      $fields = $source?->fields ?? ['body'];
    }

    $min_length_option = $input->getOption('min-length');
    $min_length = is_string($min_length_option) && $min_length_option !== ''
      ? max(1, (int) $min_length_option)
      : ($source?->minTextLength ?? 200);

    $endpoint_option = $input->getOption('endpoint');
    $endpoint = is_string($endpoint_option) && trim($endpoint_option) !== ''
      ? trim($endpoint_option)
      : $settings->embedEndpoint;

    $timeout_option = $input->getOption('timeout');
    $timeout = is_string($timeout_option) && $timeout_option !== ''
      ? max(1.0, (float) $timeout_option)
      : $settings->defaultTimeout;

    $sort = strtolower((string) $input->getOption('sort'));
    if (!in_array($sort, [EmbeddingGenerateOptions::SORT_ASC, EmbeddingGenerateOptions::SORT_DESC], TRUE)) {
      $io->error('sort must be asc or desc.');
      return Command::FAILURE;
    }

    $ids = [];
    $ids_option = $input->getOption('ids');
    if (is_string($ids_option) && trim($ids_option) !== '') {
      foreach (explode(',', $ids_option) as $raw) {
        $id = (int) trim($raw);
        if ($id > 0) {
          $ids[] = $id;
        }
      }
    }

    $min_id_raw = $input->getOption('min-id');
    $max_id_raw = $input->getOption('max-id');
    $min_id = is_string($min_id_raw) && $min_id_raw !== '' ? (int) $min_id_raw : NULL;
    $max_id = is_string($max_id_raw) && $max_id_raw !== '' ? (int) $max_id_raw : NULL;

    $options = new EmbeddingGenerateOptions(
      entityTypeId: $entity_type,
      bundle: $bundle,
      fields: $fields,
      minTextLength: $min_length,
      limit: max(0, (int) $input->getOption('limit')),
      batchSize: max(1, (int) $input->getOption('batch')),
      sort: $sort,
      ids: $ids,
      minId: $min_id,
      maxId: $max_id,
      skipExisting: strtolower((string) $input->getOption('skip-existing')),
      endpoint: $endpoint,
      timeout: $timeout,
      dimensions: $settings->dimensions,
      dryRun: (bool) $input->getOption('dry-run'),
      fieldsExplicit: $fields_explicit,
    );

    $io->writeln(sprintf(
      'endpoint=%s · fields=%s · skip=%s · sort=%s · batch=%d · dry-run=%s',
      $options->endpoint,
      implode(',', $options->fields),
      $options->skipExisting,
      $options->sort,
      $options->batchSize,
      $options->dryRun ? 'yes' : 'no',
    ));

    try {
      $result = $this->generator->generate($options, static function (string $message) use ($io): void {
        $io->writeln($message);
      });
    }
    catch (\InvalidArgumentException $exception) {
      $io->error($exception->getMessage());
      return Command::FAILURE;
    }
    catch (\RuntimeException $exception) {
      $io->error($exception->getMessage());
      return Command::FAILURE;
    }

    $io->section('SUMMARY');
    $io->listing([
      'Candidates: ' . $result->candidates,
      ($options->dryRun ? 'Would store: ' : 'Stored: ') . $result->stored,
      'Skipped (id): ' . $result->skippedId,
      'Skipped (hash): ' . $result->skippedHash,
      'Skipped (short): ' . $result->skippedShort,
      'Skipped (empty): ' . $result->skippedEmpty,
      'Errors: ' . $result->errors,
      sprintf('Prepare: %.0f ms', $result->prepareMs),
      sprintf('Embed HTTP: %.0f ms', $result->embedMs),
      sprintf('Store: %.0f ms', $result->storeMs),
      sprintf('Wall: %.0f ms', $result->wallMs),
    ]);

    return $result->errors > 0 ? Command::FAILURE : Command::SUCCESS;
  }

}
