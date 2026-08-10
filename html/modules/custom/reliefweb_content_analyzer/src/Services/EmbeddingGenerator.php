<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\ContentEmbeddingsSettings;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingGenerateOptions;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingGenerateResult;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingSourceSettings;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingTextResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates and stores content embeddings via the AI helper embed endpoint.
 */
final class EmbeddingGenerator {

  /**
   * Constructs EmbeddingGenerator.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity field manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   HTTP client for the embed endpoint.
   * @param \Drupal\reliefweb_content_analyzer\Services\EmbeddingsStorageInterface $storage
   *   Embeddings storage.
   * @param \Drupal\reliefweb_content_analyzer\Services\EmbeddingTextBuilder $textBuilder
   *   Text builder.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly EmbeddingsStorageInterface $storage,
    private readonly EmbeddingTextBuilder $textBuilder,
    #[Autowire(service: 'logger.channel.reliefweb_content_analyzer')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Load settings from config.
   *
   * @return \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\ContentEmbeddingsSettings
   *   Content embeddings settings.
   */
  public function settings(): ContentEmbeddingsSettings {
    return ContentEmbeddingsSettings::fromConfigArray(
      $this->configFactory->get('reliefweb_content_analyzer.settings')->get('content_embeddings'),
    );
  }

  /**
   * Validate fields for an entity type/bundle.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string[] $fields
   *   Fields.
   *
   * @return string[]
   *   Invalid field names.
   */
  public function invalidFields(string $entity_type_id, string $bundle, array $fields): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    return $this->textBuilder->invalidFields(
      $entity_type_id,
      $bundle,
      $fields,
      EmbeddingSourceSettings::ALLOWED_FIELDS,
      static fn(string $type, string $b, string $field): bool => isset($definitions[$field]),
    );
  }

  /**
   * Run embedding generation.
   *
   * @param \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingGenerateOptions $options
   *   Run options.
   * @param callable|null $progress
   *   Optional progress callback(string $message).
   *
   * @return \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingGenerateResult
   *   Counters.
   *
   * @throws \InvalidArgumentException
   *   On invalid options / disabled source without --fields.
   * @throws \RuntimeException
   *   When storage is unavailable or a write fails.
   */
  public function generate(EmbeddingGenerateOptions $options, ?callable $progress = NULL): EmbeddingGenerateResult {
    $wall_start = hrtime(TRUE);
    $result = new EmbeddingGenerateResult();
    $log = $progress ?? static function (string $message): void {};

    $settings = $this->settings();
    $source = $settings->getSource($options->entityTypeId, $options->bundle);
    if ($source === NULL && !$options->fieldsExplicit) {
      throw new \InvalidArgumentException(sprintf(
        'No embedding source configured for %s.%s. Pass --fields to force.',
        $options->entityTypeId,
        $options->bundle,
      ));
    }
    if ($source !== NULL && !$source->enabled && !$options->fieldsExplicit) {
      throw new \InvalidArgumentException(sprintf(
        'Embedding source %s.%s is disabled. Pass --fields to force, or enable it in settings.',
        $options->entityTypeId,
        $options->bundle,
      ));
    }

    $invalid = $this->invalidFields($options->entityTypeId, $options->bundle, $options->fields);
    if ($invalid !== []) {
      throw new \InvalidArgumentException('Invalid fields: ' . implode(', ', $invalid));
    }
    if ($options->fields === []) {
      throw new \InvalidArgumentException('At least one field is required.');
    }

    if (!in_array($options->skipExisting, [
      EmbeddingGenerateOptions::SKIP_ID,
      EmbeddingGenerateOptions::SKIP_HASH,
      EmbeddingGenerateOptions::SKIP_NO,
    ], TRUE)) {
      throw new \InvalidArgumentException('skip-existing must be id, hash, or no.');
    }

    if (!$options->dryRun) {
      $this->storage->ensureReady($options->dimensions);
    }
    elseif (!$this->storage->isAvailable()) {
      throw new \RuntimeException('Embeddings storage is not available.');
    }

    $ids = $this->resolveCandidateIds($options);
    $result->candidates = count($ids);
    $log(sprintf('Candidates: %d', $result->candidates));

    if ($ids === []) {
      $result->wallMs = (hrtime(TRUE) - $wall_start) / 1e6;
      return $result;
    }

    if ($options->skipExisting === EmbeddingGenerateOptions::SKIP_ID) {
      $existing = $this->storage->existingIds($options->entityTypeId, $ids);
      $filtered = [];
      foreach ($ids as $id) {
        if (isset($existing[$id])) {
          $result->skippedId++;
          continue;
        }
        $filtered[] = $id;
      }
      $ids = $filtered;
    }

    if ($ids === []) {
      $result->wallMs = (hrtime(TRUE) - $wall_start) / 1e6;
      return $result;
    }

    $storage = $this->entityTypeManager->getStorage($options->entityTypeId);
    $batch_size = max(1, $options->batchSize);
    $total = count($ids);

    for ($offset = 0; $offset < $total; $offset += $batch_size) {
      $batch_ids = array_slice($ids, $offset, $batch_size);
      $t_prep = hrtime(TRUE);
      $entities = $storage->loadMultiple($batch_ids);

      $batch_rows = [];
      $existing_hashes = $options->skipExisting === EmbeddingGenerateOptions::SKIP_HASH
        ? $this->storage->loadHashes($options->entityTypeId, $batch_ids)
        : [];

      foreach ($batch_ids as $id) {
        $entity = $entities[$id] ?? NULL;
        if ($entity === NULL) {
          $result->skippedEmpty++;
          continue;
        }
        $built = $this->textBuilder->build($entity, $options->fields, $options->minTextLength);
        if (!$built->isEmbeddable()) {
          if ($built->skipReason === EmbeddingTextResult::SKIP_SHORT) {
            $result->skippedShort++;
          }
          else {
            $result->skippedEmpty++;
          }
          continue;
        }
        if (
          $options->skipExisting === EmbeddingGenerateOptions::SKIP_HASH
          && isset($existing_hashes[$id])
          && $existing_hashes[$id] === $built->hash
        ) {
          $result->skippedHash++;
          continue;
        }
        $batch_rows[] = [
          'id' => $id,
          'text' => $built->text,
          'hash' => $built->hash,
          'language' => $built->language,
        ];
      }
      $result->prepareMs += (hrtime(TRUE) - $t_prep) / 1e6;

      if ($batch_rows === []) {
        continue;
      }

      if ($options->dryRun) {
        $result->stored += count($batch_rows);
        $log(sprintf(
          'Dry-run progress %d/%d would_store=%d skipped_id=%d skipped_hash=%d skipped_short=%d skipped_empty=%d',
          min($offset + $batch_size, $total),
          $total,
          $result->stored,
          $result->skippedId,
          $result->skippedHash,
          $result->skippedShort,
          $result->skippedEmpty,
        ));
        continue;
      }

      $t_embed = hrtime(TRUE);
      try {
        $embeddings = $this->requestEmbeddings(
          $options->endpoint,
          array_column($batch_rows, 'text'),
          $options->timeout,
        );
      }
      catch (\Throwable $exception) {
        $result->errors += count($batch_rows);
        $this->logger->error('Embedding batch failed: @message', ['@message' => $exception->getMessage()]);
        $log(sprintf('ERROR batch starting id=%d: %s', $batch_rows[0]['id'], $exception->getMessage()));
        continue;
      }
      $result->embedMs += (hrtime(TRUE) - $t_embed) / 1e6;

      if (count($embeddings) !== count($batch_rows)) {
        $result->errors += count($batch_rows);
        $log(sprintf(
          'ERROR batch starting id=%d: embedding count %d != texts %d',
          $batch_rows[0]['id'],
          count($embeddings),
          count($batch_rows),
        ));
        continue;
      }

      $t_store = hrtime(TRUE);
      foreach ($batch_rows as $index => $row) {
        $vector = $embeddings[$index];
        try {
          if (!is_array($vector)) {
            throw new \InvalidArgumentException('Non-array embedding.');
          }
          $this->storage->upsert(
            $options->entityTypeId,
            $row['id'],
            $options->bundle,
            $vector,
            $row['hash'],
            $row['language'],
            $options->dimensions,
          );
          $result->stored++;
        }
        catch (\Throwable $exception) {
          $result->errors++;
          $log(sprintf('ERROR store id=%d: %s', $row['id'], $exception->getMessage()));
        }
      }
      $result->storeMs += (hrtime(TRUE) - $t_store) / 1e6;

      $log(sprintf(
        'Progress %d/%d stored=%d skipped_id=%d skipped_hash=%d skipped_short=%d skipped_empty=%d errors=%d',
        min($offset + $batch_size, $total),
        $total,
        $result->stored,
        $result->skippedId,
        $result->skippedHash,
        $result->skippedShort,
        $result->skippedEmpty,
        $result->errors,
      ));
    }

    $result->wallMs = (hrtime(TRUE) - $wall_start) / 1e6;
    return $result;
  }

  /**
   * Resolve candidate entity IDs.
   *
   * @param \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingGenerateOptions $options
   *   Run options.
   *
   * @return int[]
   *   IDs.
   *
   * @throws \InvalidArgumentException
   *   When a full scan is requested for an unsupported entity type.
   */
  private function resolveCandidateIds(EmbeddingGenerateOptions $options): array {
    if ($options->ids !== []) {
      $ids = $options->ids;
      sort($ids, SORT_NUMERIC);
      if ($options->sort === EmbeddingGenerateOptions::SORT_DESC) {
        $ids = array_reverse($ids);
      }
      if ($options->limit > 0) {
        $ids = array_slice($ids, 0, $options->limit);
      }
      return $ids;
    }

    if ($options->entityTypeId !== 'node') {
      throw new \InvalidArgumentException('Only entity-type=node is supported for full scans currently. Use --ids for other types.');
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.type', $options->bundle);
    if ($options->minId !== NULL) {
      $query->condition('n.nid', $options->minId, '>=');
    }
    if ($options->maxId !== NULL) {
      $query->condition('n.nid', $options->maxId, '<=');
    }
    $query->orderBy('n.nid', $options->sort === EmbeddingGenerateOptions::SORT_ASC ? 'ASC' : 'DESC');
    if ($options->limit > 0) {
      $query->range(0, $options->limit);
    }

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Embed texts via the configured content-embeddings endpoint.
   *
   * @param string[] $texts
   *   Non-empty texts to embed (order preserved).
   * @param string|null $endpoint
   *   Override endpoint, or NULL to use settings.
   * @param float|null $timeout
   *   Override timeout, or NULL to use settings.
   *
   * @return list<float[]>
   *   One float vector per input text.
   *
   * @throws \InvalidArgumentException
   *   When texts is empty.
   * @throws \RuntimeException
   *   When the endpoint is empty, the response is invalid, or a vector is bad.
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the HTTP request fails.
   */
  public function embedTexts(array $texts, ?string $endpoint = NULL, ?float $timeout = NULL): array {
    if ($texts === []) {
      throw new \InvalidArgumentException('At least one text is required to embed.');
    }

    $settings = $this->settings();
    $endpoint = trim((string) ($endpoint ?? $settings->embedEndpoint));
    $timeout = $timeout ?? $settings->defaultTimeout;
    $raw = $this->requestEmbeddings($endpoint, $texts, $timeout);
    if (count($raw) !== count($texts)) {
      throw new \RuntimeException(sprintf(
        'Embed response count (%d) does not match texts (%d).',
        count($raw),
        count($texts),
      ));
    }

    $vectors = [];
    foreach ($raw as $item) {
      if (!is_array($item) || $item === []) {
        throw new \RuntimeException('Embed response contains a non-array embedding.');
      }
      $vector = [];
      foreach ($item as $value) {
        if (!is_int($value) && !is_float($value)) {
          throw new \RuntimeException('Embed response contains a non-numeric embedding value.');
        }
        $vector[] = (float) $value;
      }
      $vectors[] = $vector;
    }
    return $vectors;
  }

  /**
   * POST texts to the embed endpoint.
   *
   * @param string $endpoint
   *   URL.
   * @param string[] $texts
   *   Texts.
   * @param float $timeout
   *   Timeout seconds.
   *
   * @return list<mixed>
   *   Embeddings array from the response.
   *
   * @throws \RuntimeException
   *   When the endpoint is empty or the response is invalid.
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the HTTP request fails.
   */
  private function requestEmbeddings(string $endpoint, array $texts, float $timeout): array {
    if ($endpoint === '') {
      throw new \RuntimeException('Embed endpoint is empty.');
    }

    $response = $this->httpClient->request('POST', $endpoint, [
      RequestOptions::JSON => [
        // Required by helper Request schema; unused by /embed.
        'language' => 'en',
        'texts' => array_values($texts),
      ],
      RequestOptions::TIMEOUT => $timeout,
      RequestOptions::HTTP_ERRORS => TRUE,
    ]);
    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload) || !isset($payload['embeddings']) || !is_array($payload['embeddings'])) {
      throw new \RuntimeException('Invalid embed response shape.');
    }
    return $payload['embeddings'];
  }

}
