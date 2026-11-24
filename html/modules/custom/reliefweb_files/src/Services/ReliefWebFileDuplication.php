<?php

declare (strict_types=1);

namespace Drupal\reliefweb_files\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\Entity\File;
use Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient;
use Drupal\reliefweb_utility\Helpers\FileHelper;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * ReliefWeb file duplication service class.
 *
 * This service handles file duplication detection.
 */
class ReliefWebFileDuplication implements ReliefWebFileDuplicationInterface {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient $elasticsearch
   *   The Elasticsearch client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected ReliefWebElasticsearchClient $elasticsearch,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected AccountInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function findSimilarDocuments(
    string $input_text,
    string $bundle = 'report',
    array $exclude_document_ids = [],
    ?int $max_documents = NULL,
    ?string $minimum_should_match = NULL,
    ?int $max_files = NULL,
    ?bool $skip_access_check = NULL,
  ): array {
    $logger = $this->getLogger();

    if (empty($input_text)) {
      $logger->warning('Input text is empty.');
      return [];
    }

    if (empty($bundle)) {
      $logger->warning('Bundle is empty.');
      return [];
    }

    [
      'max_documents' => $max_documents,
      'minimum_should_match' => $minimum_should_match,
      'max_files' => $max_files,
      'skip_access_check' => $skip_access_check,
    ] = $this->resolveDuplicateDetectionOptions(
      $max_documents,
      $minimum_should_match,
      $max_files,
      $skip_access_check,
    );

    $exclude_file_ids = $this->getExcludeFileIds($bundle, $exclude_document_ids);
    $similar_files = $this->findSimilarFileFingerprints(
      $input_text,
      $max_files,
      $minimum_should_match,
      $exclude_file_ids,
    );
    if (empty($similar_files)) {
      $logger->notice('No similar files found.');
      return [];
    }

    return $this->mapSimilarFilesToDocuments(
      $similar_files,
      $bundle,
      $exclude_document_ids,
      $max_documents,
      $minimum_should_match,
      $skip_access_check,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function findSimilarDocumentsForFile(
    File $file,
    string $bundle = 'report',
    array $exclude_document_ids = [],
    ?int $max_documents = NULL,
    ?string $minimum_should_match = NULL,
    ?int $max_files = NULL,
    ?bool $skip_access_check = NULL,
  ): array {
    $logger = $this->getLogger();
    $file_id = $file->id();
    if (empty($file_id) || empty($bundle)) {
      return [];
    }

    [
      'max_documents' => $max_documents,
      'minimum_should_match' => $minimum_should_match,
      'max_files' => $max_files,
      'skip_access_check' => $skip_access_check,
    ] = $this->resolveDuplicateDetectionOptions(
      $max_documents,
      $minimum_should_match,
      $max_files,
      $skip_access_check,
    );

    if (!$this->hasFileFingerprint($file_id)) {
      if (!$this->indexFileFingerprint($file)) {
        $logger->warning('Unable to index fingerprint for file @id; falling back to text search.', [
          '@id' => $file_id,
        ]);
        $text = $this->extractFingerprintText($file);
        if ($text === '') {
          return [];
        }
        return $this->findSimilarDocuments(
          $text,
          $bundle,
          $exclude_document_ids,
          $max_documents,
          $minimum_should_match,
          $max_files,
          $skip_access_check,
        );
      }
    }

    $exclude_file_ids = $this->getExcludeFileIds($bundle, $exclude_document_ids);
    $similar_files = $this->findSimilarFileFingerprintsById(
      $file_id,
      $max_files,
      $minimum_should_match,
      $exclude_file_ids,
    );
    if (empty($similar_files)) {
      $logger->notice('No similar files found.');
      return [];
    }

    return $this->mapSimilarFilesToDocuments(
      $similar_files,
      $bundle,
      $exclude_document_ids,
      $max_documents,
      $minimum_should_match,
      $skip_access_check,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function createFileFingerprintsIndex(
    ?int $shards = NULL,
    ?int $replicas = NULL,
  ): bool {
    $logger = $this->getLogger();
    $config = $this->getConfigFactory()->get('reliefweb_api.settings');
    $shards = $shards ?? $config->get('shards') ?? 1;
    $replicas = $replicas ?? $config->get('replicas') ?? 0;
    $index_name = $this->getFileFingerprintsIndexName();

    $settings = [
      'settings' => [
        'index' => [
          'number_of_shards' => $shards,
          'number_of_replicas' => $replicas,
          'analysis' => [
            'filter' => [
              'filter_minhash_shingle' => [
                'max_shingle_size' => '4',
                'min_shingle_size' => '4',
                'output_unigrams' => 'false',
                'type' => 'shingle',
              ],
              'filter_minhash' => [
                'hash_count' => '1',
                'type' => 'min_hash',
                'with_rotation' => 'true',
                'hash_set_size' => '1',
                'bucket_count' => '512',
              ],
            ],
            'analyzer' => [
              'minhash_analyzer' => [
                'filter' => [
                  'icu_folding',
                  'icu_normalizer',
                  'filter_minhash_shingle',
                  'filter_minhash',
                ],
                'type' => 'custom',
                'tokenizer' => 'icu_tokenizer',
              ],
            ],
          ],
        ],
      ],
      'mappings' => [
        'properties' => [
          'id' => [
            'type' => 'integer',
          ],
          'uuid' => [
            'type' => 'keyword',
          ],
          'minhash' => [
            'type' => 'text',
            'analyzer' => 'minhash_analyzer',
          ],
          'hash' => [
            'type' => 'keyword',
          ],
        ],
      ],
    ];

    try {
      $response = $this->requestFileFingerprintsIndex('PUT', '', [
        'json' => $settings,
      ]);

      if ($response->getStatusCode() === 200) {
        $logger->info('File fingerprints index created successfully: @index', ['@index' => $index_name]);
        return TRUE;
      }
    }
    catch (RequestException $exception) {
      $response = $exception->getResponse();

      // Check if it's a 400 with resource_already_exists_exception.
      if ($response && $response->getStatusCode() === 400) {
        $body = $response->getBody()->getContents();
        $data = json_decode($body, TRUE);

        if (isset($data['error']['type']) && $data['error']['type'] === 'resource_already_exists_exception') {
          $logger->info('File fingerprints index already exists: @index', ['@index' => $index_name]);
          return TRUE;
        }
      }

      $logger->error('Error creating file fingerprints index: @error', ['@error' => $exception->getMessage()]);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFileFingerprintsIndex(): bool {
    $logger = $this->getLogger();
    $index_name = $this->getFileFingerprintsIndexName();

    try {
      $response = $this->requestFileFingerprintsIndex('DELETE');

      if ($response->getStatusCode() === 200) {
        $logger->info('File fingerprints index deleted successfully: @index', ['@index' => $index_name]);
        return TRUE;
      }
    }
    catch (RequestException $exception) {
      $response = $exception->getResponse();

      // Check if it's a 404 with index_not_found_exception.
      if ($response && $response->getStatusCode() === 404) {
        $body = $response->getBody()->getContents();
        $data = json_decode($body, TRUE);

        if (isset($data['error']['type']) && $data['error']['type'] === 'index_not_found_exception') {
          $logger->info('File fingerprints index does not exist: @index', ['@index' => $index_name]);
          return TRUE;
        }
      }

      $logger->error('Error deleting file fingerprints index: @error', ['@error' => $exception->getMessage()]);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function findSimilarFileFingerprints(
    string $input_text,
    int $max_files = 50,
    string $minimum_should_match = '80%',
    array $exclude_file_ids = [],
  ): array {
    $logger = $this->getLogger();

    if (empty($input_text)) {
      return [];
    }

    // 1. Analyze the input text to get minhash tokens.
    try {
      $analyze_response = $this->executeFileFingerprintsRequest('POST', '/_analyze', [
        'json' => [
          'analyzer' => 'minhash_analyzer',
          'text' => $input_text,
        ],
      ]);

      if (!$analyze_response) {
        $logger->error('Failed to analyze text for minhash tokens.');
        return [];
      }

      $analyze_body = $analyze_response->getBody()->getContents();

      // MinHash tokens produced by Elasticsearch represent arbitrary binary
      // data (for document similarity estimation).
      // When these binary sequences are encoded in a JSON string, they may
      // contain byte patterns that correspond to "invalid" or unpaired Unicode
      // surrogates. While these tokens are valid as raw binary for MinHash,
      // their JSON-escaped string representations can break json_decode() in
      // PHP—since JSON and PHP expect only valid UTF-8 and valid Unicode.
      // To safely extract the tokens (without altering their content, which is
      // essential for correct similarity matching), we use a regex to pull the
      // "token" values directly from the analyzer's raw JSON output, bypassing
      // full JSON parsing.
      preg_match_all('/"token"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/u', $analyze_body, $matches);

      // We count the number of unique tokens. That will be used later to
      // calculate the exact similarity score of the similar files retrieved via
      // the more like this query.
      $num_query_tokens = count($matches[1]);
      $num_query_unique_tokens = count(array_unique($matches[1]));

      if ($num_query_tokens === 0) {
        $logger->warning('No minhash tokens returned for input text.');
        return [];
      }
    }
    catch (\Exception $exception) {
      $logger->error('Error analyzing text: @error', [
        '@error' => $this->formatRequestExceptionMessage($exception),
      ]);
      return [];
    }

    $like = [
      '_index' => $this->getFileFingerprintsIndexName(),
      'doc' => [
        'minhash' => $input_text,
      ],
    ];

    return $this->searchSimilarFileFingerprints(
      $like,
      $num_query_tokens,
      $num_query_unique_tokens,
      $max_files,
      $minimum_should_match,
      $exclude_file_ids,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function findSimilarFileFingerprintsById(
    int|string $file_id,
    int $max_files = 50,
    string $minimum_should_match = '80%',
    array $exclude_file_ids = [],
  ): array {
    $exclude_file_ids[] = $file_id;
    $exclude_file_ids = array_values(array_unique($exclude_file_ids));

    $like = [
      '_index' => $this->getFileFingerprintsIndexName(),
      '_id' => (string) $file_id,
    ];

    return $this->searchSimilarFileFingerprints(
      $like,
      self::MINHASH_BUCKET_COUNT,
      self::MINHASH_BUCKET_COUNT,
      $max_files,
      $minimum_should_match,
      $exclude_file_ids,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function hasFileFingerprint(int|string $file_id): bool {
    $existing = $this->filterExistingFingerprintIds([(string) $file_id]);
    return in_array((string) $file_id, $existing, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function findFilesByHash(
    string $file_hash,
    array $exclude_file_ids = [],
    int $max_files = 10,
  ): array {
    $logger = $this->getLogger();

    if (empty($file_hash)) {
      return [];
    }

    try {
      $query = [
        'size' => $max_files,
        '_source' => ['id', 'uuid', 'hash'],
        'query' => [
          'bool' => [
            'must' => [
              [
                'term' => [
                  'hash' => $file_hash,
                ],
              ],
            ],
          ],
        ],
      ];

      // Add filter to exclude specific file IDs if provided.
      if (!empty($exclude_file_ids)) {
        $query['query']['bool']['filter'] = [
          [
            'bool' => [
              'must_not' => [
                ['terms' => ['_id' => $exclude_file_ids]],
              ],
            ],
          ],
        ];
      }

      $search_response = $this->executeFileFingerprintsRequest('POST', '_search', [
        'json' => $query,
      ]);

      if (!$search_response) {
        $logger->error('Failed to search for files by hash.');
        return [];
      }

      $search_body = $search_response->getBody()->getContents();
      $search_data = json_decode($search_body, TRUE);
    }
    catch (RequestException $exception) {
      $logger->error('Error searching for files by hash: ' . $exception->getMessage());
      return [];
    }

    $hits = $search_data['hits']['hits'] ?? [];
    if (empty($hits)) {
      return [];
    }

    // Build results for exact hash matches.
    $results = [];
    foreach ($hits as $hit) {
      $source = $hit['_source'] ?? [];

      $results[] = [
        'id' => (int) $source['id'],
        'uuid' => $source['uuid'] ?? '',
        'hash' => $source['hash'] ?? '',
        // Exact match.
        'similarity' => 1.0,
        'similarity_percentage' => '100.0%',
        'score' => $hit['_score'] ?? 0,
      ];
    }

    return $results;
  }

  /**
   * Search similar fingerprints with a More Like This like clause.
   *
   * @param array $like
   *   MLT `like` clause (artificial doc or existing `_id`).
   * @param int $num_query_tokens
   *   Token count for max_query_terms.
   * @param int $num_query_unique_tokens
   *   Unique token count for Jaccard denominator.
   * @param int $max_files
   *   Maximum hits.
   * @param string $minimum_should_match
   *   MLT minimum_should_match.
   * @param array $exclude_file_ids
   *   File ids to exclude.
   *
   * @return array
   *   Matching fingerprints with similarity scores.
   */
  protected function searchSimilarFileFingerprints(
    array $like,
    int $num_query_tokens,
    int $num_query_unique_tokens,
    int $max_files,
    string $minimum_should_match,
    array $exclude_file_ids = [],
  ): array {
    $logger = $this->getLogger();

    try {
      $query = [
        'size' => $max_files,
        '_source' => ['id', 'uuid', 'minhash'],
        'query' => [
          'bool' => [
            'must' => [
              [
                'more_like_this' => [
                  'fields' => ['minhash'],
                  'like' => [$like],
                  'analyzer' => 'minhash_analyzer',
                  // Use a term and doc frequency of 1 so that all minhash
                  // tokens from the input text are considered.
                  'min_term_freq' => 1,
                  'min_doc_freq' => 1,
                  // Make sure we check all the tokens for accuracy.
                  'max_query_terms' => $num_query_tokens,
                  'minimum_should_match' => $minimum_should_match,
                ],
              ],
            ],
          ],
        ],
      ];

      // Add filter to exclude specific file IDs if provided.
      if (!empty($exclude_file_ids)) {
        $query['query']['bool']['filter'] = [
          [
            'bool' => [
              'must_not' => [
                ['terms' => ['_id' => $exclude_file_ids]],
              ],
            ],
          ],
        ];
      }

      $search_response = $this->executeFileFingerprintsRequest('POST', '_search', [
        'json' => $query,
      ]);

      if (!$search_response) {
        $logger->error('Failed to search for similar file fingerprints.');
        return [];
      }

      $search_body = $search_response->getBody()->getContents();
      $search_data = json_decode($search_body, TRUE);
    }
    catch (RequestException $exception) {
      $logger->error('Error searching for similar file fingerprints: @error', [
        '@error' => $this->formatRequestExceptionMessage($exception),
      ]);
      return [];
    }

    $hits = $search_data['hits']['hits'] ?? [];
    if (empty($hits)) {
      return [];
    }

    // Calculate similarity scores via explain (matching MinHash token count).
    $results = [];
    foreach ($hits as $hit) {
      $file_id = $hit['_id'];

      try {
        $explain_response = $this->executeFileFingerprintsRequest('POST', '_explain/' . $file_id, [
          'json' => [
            'query' => $query['query'],
          ],
        ]);

        if (!$explain_response) {
          $logger->error('Failed to explain match for file @id', ['@id' => $file_id]);
          continue;
        }

        $explain_body = $explain_response->getBody()->getContents();
        $matched_tokens = substr_count($explain_body, 'weight(minhash:');
      }
      catch (\Exception $exception) {
        $logger->error('Error explaining match for file @id: @error', [
          '@id' => $file_id,
          '@error' => $this->formatRequestExceptionMessage($exception),
        ]);
        continue;
      }

      $similarity = $num_query_unique_tokens > 0 ? ($matched_tokens / $num_query_unique_tokens) : 0;
      $source = $hit['_source'] ?? [];

      $results[] = [
        'id' => (int) ($source['id'] ?? $file_id),
        'uuid' => $source['uuid'] ?? '',
        'similarity' => $similarity,
        'similarity_percentage' => sprintf('%.1f%%', $similarity * 100),
        'score' => $hit['_score'] ?? 0,
      ];
    }

    return $results;
  }

  /**
   * Generate document data for file fingerprinting.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file entity to generate document data for.
   *
   * @return array|null
   *   The document data array or NULL if the file cannot be processed.
   */
  public function generateFileFingerprintDocument(File $file): ?array {
    $file_system = $this->getFileSystem();

    // Only process permanent files.
    if (!$file->isPermanent()) {
      return NULL;
    }

    // Calculate file hash first - this is required for all files.
    $file_hash = FileHelper::generateFileHash($file, file_system: $file_system);
    if (empty($file_hash)) {
      return NULL;
    }

    // Extract capped text for minhash (optional - files without text are still
    // indexed).
    $file_text = $this->extractFingerprintText($file);

    return [
      'id' => $file->id(),
      'uuid' => $file->uuid(),
      // Empty string for files without text.
      'minhash' => $file_text ?: '',
      'hash' => $file_hash,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function indexFileFingerprint(File $file): bool {
    $logger = $this->getLogger();

    // Generate the document data.
    $document = $this->generateFileFingerprintDocument($file);
    if (empty($document)) {
      return FALSE;
    }

    try {
      $response = $this->executeFileFingerprintsRequest('PUT', '_doc/' . $file->id(), [
        'json' => $document,
      ]);

      if ($response && ($response->getStatusCode() === 200 || $response->getStatusCode() === 201)) {
        return TRUE;
      }
    }
    catch (RequestException $exception) {
      $logger->error('Error indexing file fingerprint for file @id: @error', [
        '@id' => $file->id(),
        '@error' => $exception->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function bulkIndexFileFingerprints(array $files, int $processes = 4): array {
    $logger = $this->getLogger();
    $file_system = $this->getFileSystem();
    $results = ['success' => 0, 'failed' => 0];

    if (empty($files)) {
      return $results;
    }

    // Filter valid files and extract text in parallel.
    $valid_files = [];
    foreach ($files as $file) {
      if ($file instanceof File && $file->isPermanent()) {
        $valid_files[] = $file;
        continue;
      }

      $results['failed']++;
      if ($file instanceof File) {
        $logger->warning('Skipping non-permanent file @id during fingerprint bulk index.', [
          '@id' => $file->id(),
        ]);
      }
      else {
        $logger->warning('Skipping non-file value during fingerprint bulk index.');
      }
    }

    if (empty($valid_files)) {
      return $results;
    }

    // Extract capped text from all files in parallel.
    $extract_limits = $this->getExtractLimits();
    $extracted_texts = FileHelper::extractTextParallel(
      $valid_files,
      $processes,
      60,
      $file_system,
      $extract_limits['max_pages'],
      $extract_limits['max_chars'],
    );

    // Prepare bulk request body.
    $bulk_body = '';
    $documents = [];
    $index_name = $this->getFileFingerprintsIndexName();

    foreach ($valid_files as $file) {
      $file_id = $file->id();
      $file_text = $extracted_texts[$file_id] ?? '';

      // Calculate file hash - this is required for all files.
      $file_hash = FileHelper::generateFileHash($file, file_system: $file_system);
      if (empty($file_hash)) {
        $results['failed']++;
        $logger->warning('Failed to generate hash for file @id (@uri); skipping fingerprint index.', [
          '@id' => $file_id,
          '@uri' => $file->getFileUri() ?: '(empty uri)',
        ]);
        continue;
      }

      // Create document data - files without text are still indexed with empty
      // minhash so that we can still perform exact matching requests.
      $document = [
        'id' => $file_id,
        'uuid' => $file->uuid(),
        'minhash' => $file_text ?: '',
        'hash' => $file_hash,
      ];

      // Add action line for bulk API.
      $action = [
        'index' => [
          '_index' => $index_name,
          '_id' => $file_id,
        ],
      ];

      $bulk_body .= json_encode($action) . "\n";
      $bulk_body .= json_encode($document) . "\n";
      $documents[] = $file_id;
    }

    if (empty($bulk_body)) {
      return $results;
    }

    try {
      $response = $this->executeFileFingerprintsRequest('POST', '_bulk', [
        'body' => $bulk_body,
        'headers' => [
          'Content-Type' => 'application/x-ndjson',
        ],
      ]);

      if ($response && $response->getStatusCode() === 200) {
        $response_data = json_decode($response->getBody()->getContents(), TRUE);

        if (isset($response_data['items'])) {
          foreach ($response_data['items'] as $item) {
            if (isset($item['index']['status']) && in_array($item['index']['status'], [200, 201])) {
              $results['success']++;
            }
            else {
              $results['failed']++;
              $logger->warning('Failed to index file fingerprint @id in bulk operation: @error', [
                '@id' => $item['index']['_id'] ?? 'unknown',
                '@error' => $item['index']['error']['reason'] ?? $item['index']['error']['type'] ?? 'Unknown error',
              ]);
            }
          }
        }
      }
      else {
        $results['failed'] += count($documents);
        $logger->error('Bulk indexing failed with status code: @status', [
          '@status' => $response ? $response->getStatusCode() : 'No response',
        ]);
      }
    }
    catch (RequestException $exception) {
      $results['failed'] += count($documents);
      $logger->error('Error in bulk indexing file fingerprints: @error', [
        '@error' => $this->formatRequestExceptionMessage($exception),
      ]);
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFileFingerprint(File $file): bool {
    $logger = $this->getLogger();

    $file_id = $file->id();
    try {
      $response = $this->executeFileFingerprintsRequest('DELETE', '_doc/' . $file_id, [], FALSE);

      if ($response && ($response->getStatusCode() === 200 || $response->getStatusCode() === 404)) {
        return TRUE;
      }
    }
    catch (RequestException $exception) {
      // Don't log 404 errors as they are expected when the file fingerprint
      // doesn't exist.
      if ($exception->getCode() !== 404) {
        $logger->error('Error deleting file fingerprint for file @id: @error', [
          '@id' => $file_id,
          '@error' => $exception->getMessage(),
        ]);
      }
    }

    return FALSE;
  }

  /**
   * Execute a request to the file fingerprints index.
   *
   * @param string $method
   *   HTTP method (GET, POST, PUT, DELETE).
   * @param string $endpoint
   *   The endpoint path (e.g., '/_search', '/_doc/123').
   * @param array $options
   *   Request options (json, headers, etc.).
   * @param bool $retry_on_creation
   *   Whether to retry the original request after index creation.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The HTTP response or NULL if the request failed.
   */
  protected function executeFileFingerprintsRequest(
    string $method,
    string $endpoint,
    array $options = [],
    bool $retry_on_creation = TRUE,
  ): ?ResponseInterface {
    $logger = $this->getLogger();

    try {
      return $this->requestFileFingerprintsIndex($method, $endpoint, $options);
    }
    catch (RequestException $exception) {
      $response = $exception->getResponse();

      // Check if it's a 404 with index_not_found_exception.
      if ($response && $response->getStatusCode() === 404) {
        $body = $response->getBody()->getContents();
        $data = json_decode($body, TRUE);

        if (isset($data['error']['type']) && $data['error']['type'] === 'index_not_found_exception') {
          $logger->info('File fingerprints index not found, attempting to create it.');

          // Attempt to create the index.
          if ($this->createFileFingerprintsIndex()) {
            $logger->info('File fingerprints index created successfully, retrying original request.');

            // Retry the original request if requested.
            if ($retry_on_creation) {
              try {
                return $this->requestFileFingerprintsIndex($method, $endpoint, $options);
              }
              catch (RequestException $retry_exception) {
                $logger->error('Error retrying request after index creation: @error', [
                  '@error' => $retry_exception->getMessage(),
                ]);
              }
            }
          }
          else {
            $logger->error('Failed to create file fingerprints index.');
          }
        }
      }

      // Re-throw the original exception if we couldn't handle it.
      throw $exception;
    }
  }

  /**
   * Get the total number of files to index with optional ID range conditions.
   *
   * @param int $start_id
   *   Optional start file ID (inclusive).
   * @param int $end_id
   *   Optional end file ID (inclusive).
   * @param int $limit
   *   Optional limit on the count.
   * @param int $min_pages
   *   Optional minimum pages filter.
   *
   * @return int
   *   The total number of files to process.
   */
  public function getFileCountForIndexing(
    int $start_id = 0,
    int $end_id = 0,
    int $limit = 0,
    int $min_pages = 0,
  ): int {
    $query = $this->createNodeFieldFileQuery(min_pages: $min_pages);
    $this->applyFileIdRange($query, $start_id, $end_id);

    $count = (int) $query->countQuery()->execute()->fetchField();

    // Apply limit if provided and smaller than count.
    return $limit > 0 ? min($count, $limit) : $count;
  }

  /**
   * {@inheritdoc}
   */
  public function countFingerprintIndexStatus(
    int $start_id = 0,
    int $end_id = 0,
    int $limit = 0,
    int $min_pages = 0,
    int $chunk_size = 500,
  ): array {
    $total = $this->getFileCountForIndexing($start_id, $end_id, $limit, $min_pages);
    if ($total === 0) {
      return ['total' => 0, 'indexed' => 0, 'missing' => 0];
    }

    $indexed = 0;
    $processed = 0;
    $current_max_id = $start_id > 0 ? $start_id : $this->getMaxFileId($min_pages);
    $chunk_size = max(1, $chunk_size);

    while ($processed < $total && $current_max_id > 0) {
      $remaining = $total - $processed;
      $chunk_limit = min($chunk_size, $remaining);

      $chunk_file_query = $this->createNodeFieldFileQuery(min_pages: $min_pages)
        ->condition('fm.fid', $current_max_id, '<=')
        ->orderBy('fm.fid', 'DESC')
        ->range(0, $chunk_limit);
      if ($end_id > 0) {
        $chunk_file_query->condition('fm.fid', $end_id, '>=');
      }

      $chunk_file_ids = $chunk_file_query->execute()->fetchCol();
      if (empty($chunk_file_ids)) {
        break;
      }

      $existing = $this->filterExistingFingerprintIds($chunk_file_ids);
      $indexed += count($existing);
      $processed += count($chunk_file_ids);
      $current_max_id = min($chunk_file_ids) - 1;
    }

    return [
      'total' => $total,
      'indexed' => $indexed,
      'missing' => max(0, $total - $indexed),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function indexFiles(
    int $chunk_size,
    int $start_id = 0,
    int $end_id = 0,
    int $limit = 0,
    int $processes = 4,
    ?callable $progress_callback = NULL,
    int $min_pages = 0,
    bool $skip_existing = FALSE,
  ): array {
    $logger = $this->getLogger();
    $file_storage = $this->getEntityTypeManager()->getStorage('file');

    // Get the total number of files to process.
    $total_files = $this->getFileCountForIndexing($start_id, $end_id, $limit, $min_pages);
    if ($total_files === 0) {
      $logger->warning('No files found to index.');
      return ['success' => 0, 'failed' => 0];
    }

    $logger->info("Found $total_files files to index.");

    $indexed = 0;
    $errors = 0;
    $processed = 0;
    $skipped = 0;

    // Start with the highest file ID (or start_id if provided).
    $current_max_id = $start_id > 0 ? $start_id : $this->getMaxFileId($min_pages);

    // Process files in chunks using file ID tracking.
    while ($processed < $total_files && $current_max_id > 0) {
      // Calculate how many files to process in this chunk (respect the limit).
      $remaining_files = $total_files - $processed;
      $chunk_limit = min($chunk_size, $remaining_files);

      $logger->info("Processing chunk starting from file ID $current_max_id (up to $chunk_limit files)");

      // Get file IDs for this chunk (descending order).
      $chunk_file_query = $this->createNodeFieldFileQuery(min_pages: $min_pages)
        ->condition('fm.fid', $current_max_id, '<=')
        ->orderBy('fm.fid', 'DESC')
        ->range(0, $chunk_limit);

      // Add end_id condition if provided.
      if ($end_id > 0) {
        $chunk_file_query->condition('fm.fid', $end_id, '>=');
      }

      $chunk_file_ids = $chunk_file_query->execute()->fetchCol();
      if (empty($chunk_file_ids)) {
        break;
      }

      $ids_to_index = $chunk_file_ids;
      if ($skip_existing) {
        $existing = $this->filterExistingFingerprintIds($chunk_file_ids);
        $ids_to_index = array_values(array_diff($chunk_file_ids, $existing));
        $skipped += count($existing);
      }

      if (!empty($ids_to_index)) {
        // Load all files in this chunk at once for better performance.
        $files = $file_storage->loadMultiple($ids_to_index);
        if (empty($files)) {
          $logger->warning("No files found in chunk starting from file ID $current_max_id");
          $errors += count($ids_to_index);
        }
        else {
          // Use bulk indexing for better performance.
          $bulk_results = $this->bulkIndexFileFingerprints($files, $processes);
          $indexed += $bulk_results['success'];
          $errors += $bulk_results['failed'];
          $logger->info("Bulk indexed " . $bulk_results['success'] . " files, " . $bulk_results['failed'] . " failed in this chunk");
        }
      }

      $processed += count($chunk_file_ids);

      // Call progress callback if provided.
      if ($progress_callback) {
        $progress_callback($processed, $total_files, $indexed, $errors);
      }

      // Move to next chunk by setting current_max_id to the smallest ID minus
      // 1.
      $current_max_id = min($chunk_file_ids) - 1;

      // Clear entity cache between chunks to manage memory usage.
      $file_storage->resetCache();
    }

    if ($skip_existing && $skipped > 0) {
      $logger->info('Skipped @count already-indexed files.', [
        '@count' => $skipped,
      ]);
    }

    return ['success' => $indexed, 'failed' => $errors];
  }

  /**
   * Create a base query for node__field_file with file_managed join.
   *
   * @param string $bundle
   *   The bundle to filter by.
   * @param int $min_pages
   *   Only include files with field_file_page_count greater than this.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The configured query object returning distinct file IDs by default.
   */
  protected function createNodeFieldFileQuery(
    string $bundle = 'report',
    int $min_pages = 0,
  ): SelectInterface {
    $query = $this->getDatabase()->select('node__field_file', 'nff')
      ->fields('fm', ['fid'])
      ->condition('nff.bundle', $bundle, '=')
      ->distinct();

    // Join with file_managed table to get file IDs from UUIDs.
    $query->join('file_managed', 'fm', 'nff.field_file_file_uuid = fm.uuid');

    if ($min_pages > 0) {
      $query->condition('nff.field_file_page_count', $min_pages, '>');
    }

    return $query;
  }

  /**
   * Get the maximum file ID from the database.
   *
   * @param int $min_pages
   *   Optional page-count filter.
   *
   * @return int
   *   The maximum file ID, or 0 if no files exist.
   */
  protected function getMaxFileId(int $min_pages = 0): int {
    $query = $this->createNodeFieldFileQuery(min_pages: $min_pages)
      ->orderBy('fm.fid', 'DESC')
      ->range(0, 1);

    $result = $query->execute()->fetchField();
    return $result ? (int) $result : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function indexSingleFileById(int $file_id, bool $remove): bool {
    $logger = $this->getLogger();
    $action = $remove ? 'removed' : 'indexed';

    // Load the file.
    $file = $this->getEntityTypeManager()->getStorage('file')->load($file_id);
    if (!$file) {
      $logger->error("File $file_id not found.");
      return FALSE;
    }

    $success = $remove ? $this->deleteFileFingerprint($file) : $this->indexFileFingerprint($file);
    if ($success) {
      $logger->success("File fingerprint $action successfully for file ID: $file_id");
      return TRUE;
    }
    else {
      $logger->error("Failed to $action file fingerprint for file ID: $file_id");
      return FALSE;
    }
  }

  /**
   * Get the file fingerprints index name.
   *
   * @return string
   *   The file fingerprints index name.
   */
  protected function getFileFingerprintsIndexName(): string {
    return $this->elasticsearch->getBaseIndexName() . '_file_fingerprints';
  }

  /**
   * Send a request against the file fingerprints index.
   *
   * @param string $method
   *   HTTP method (GET, POST, PUT, DELETE).
   * @param string $endpoint
   *   Path relative to the index (e.g. `_search`, `_doc/123`). Empty for the
   *   index itself.
   * @param array $options
   *   Guzzle request options.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   */
  protected function requestFileFingerprintsIndex(
    string $method,
    string $endpoint = '',
    array $options = [],
  ): ResponseInterface {
    $path = $this->getFileFingerprintsIndexName();
    $endpoint = ltrim($endpoint, '/');
    if ($endpoint !== '') {
      $path .= '/' . $endpoint;
    }
    return $this->elasticsearch->request($method, $path, $options);
  }

  /**
   * Get the state service.
   *
   * @return \Drupal\Core\State\StateInterface
   *   The state service.
   */
  protected function getState(): StateInterface {
    return $this->state;
  }

  /**
   * Get the config factory service.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory service.
   */
  protected function getConfigFactory(): ConfigFactoryInterface {
    return $this->configFactory;
  }

  /**
   * Get the database connection.
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection.
   */
  protected function getDatabase(): Connection {
    return $this->database;
  }

  /**
   * Get the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function getEntityTypeManager(): EntityTypeManagerInterface {
    return $this->entityTypeManager;
  }

  /**
   * Get the file system service.
   *
   * @return \Drupal\Core\File\FileSystemInterface
   *   The file system service.
   */
  protected function getFileSystem(): FileSystemInterface {
    return $this->fileSystem;
  }

  /**
   * Get the current user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The current user.
   */
  protected function getCurrentUser(): AccountInterface {
    return $this->currentUser;
  }

  /**
   * Get the logger for this service.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger service.
   */
  protected function getLogger(): LoggerInterface {
    if (!isset($this->logger)) {
      $this->setLogger($this->loggerFactory->get('reliefweb_api'));
    }
    return $this->logger;
  }

  /**
   * Set the logger for this service.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function setLogger(LoggerInterface $logger): void {
    $this->logger = $logger;
  }

  /**
   * Resolve duplicate-detection options with config fallbacks.
   *
   * @return array{
   *   max_documents: int,
   *   minimum_should_match: string,
   *   max_files: int,
   *   skip_access_check: bool
   *   }
   *   Resolved options.
   */
  protected function resolveDuplicateDetectionOptions(
    ?int $max_documents,
    ?string $minimum_should_match,
    ?int $max_files,
    ?bool $skip_access_check,
  ): array {
    $duplicate_config = $this->getConfigFactory()
      ->get('reliefweb_files.settings')
      ->get('duplicate_detection') ?? [];

    return [
      'max_documents' => $max_documents ?? $duplicate_config['max_documents'] ?? 10,
      'minimum_should_match' => $minimum_should_match ?? $duplicate_config['minimum_should_match'] ?? '80%',
      'max_files' => $max_files ?? $duplicate_config['max_files'] ?? 20,
      'skip_access_check' => $skip_access_check ?? $duplicate_config['skip_access_check'] ?? FALSE,
    ];
  }

  /**
   * Get page/char extract limits from config.
   *
   * @return array{max_pages: int, max_chars: int}
   *   Extract limits.
   */
  protected function getExtractLimits(): array {
    $duplicate_config = $this->getConfigFactory()
      ->get('reliefweb_files.settings')
      ->get('duplicate_detection') ?? [];

    return [
      'max_pages' => (int) ($duplicate_config['max_extract_pages'] ?? 30),
      'max_chars' => (int) ($duplicate_config['max_extract_chars'] ?? 300000),
    ];
  }

  /**
   * Extract capped text for fingerprinting.
   *
   * @param \Drupal\file\Entity\File $file
   *   File entity.
   *
   * @return string
   *   Capped extracted text.
   */
  public function extractFingerprintText(File $file): string {
    $limits = $this->getExtractLimits();
    return FileHelper::extractTextForFingerprint(
      $file,
      $limits['max_pages'],
      $limits['max_chars'],
      $this->getFileSystem(),
    );
  }

  /**
   * File IDs attached to documents that should be excluded from search.
   *
   * @param string $bundle
   *   Node bundle.
   * @param array $exclude_document_ids
   *   Document ids to exclude.
   *
   * @return array
   *   File ids.
   */
  protected function getExcludeFileIds(string $bundle, array $exclude_document_ids): array {
    if (empty($exclude_document_ids)) {
      return [];
    }
    $exclude_file_query = $this->createNodeFieldFileQuery($bundle);
    $exclude_file_query->condition('nff.entity_id', $exclude_document_ids, 'IN');
    return $exclude_file_query->execute()->fetchCol();
  }

  /**
   * Map similar file fingerprints to accessible documents.
   *
   * @param array $similar_files
   *   Similar fingerprint hits.
   * @param string $bundle
   *   Node bundle.
   * @param array $exclude_document_ids
   *   Document ids to exclude.
   * @param int $max_documents
   *   Max documents to return.
   * @param string $minimum_should_match
   *   Similarity threshold percentage string.
   * @param bool $skip_access_check
   *   Whether to skip access checks.
   *
   * @return array
   *   Document result rows.
   */
  protected function mapSimilarFilesToDocuments(
    array $similar_files,
    string $bundle,
    array $exclude_document_ids,
    int $max_documents,
    string $minimum_should_match,
    bool $skip_access_check,
  ): array {
    $logger = $this->getLogger();
    $file_ids = array_column($similar_files, 'id');

    $query = $this->createNodeFieldFileQuery($bundle);
    $query->fields('nff', ['entity_id']);
    $query->condition('fm.fid', $file_ids, 'IN');

    if (!empty($exclude_document_ids)) {
      $query->condition('nff.entity_id', $exclude_document_ids, 'NOT IN');
    }

    $id_mapping = $query->execute()->fetchAllKeyed();
    if (empty($id_mapping)) {
      $logger->notice('No reports found with similar files.');
      return [];
    }

    $minimum_similarity_threshold = floatval($minimum_should_match) / 100;
    $report_data = [];
    foreach ($similar_files as $similar_file) {
      if (!isset($id_mapping[$similar_file['id']])) {
        continue;
      }

      $report_id = $id_mapping[$similar_file['id']];
      $similarity = $similar_file['similarity'];
      if ($similarity < $minimum_similarity_threshold) {
        continue;
      }

      if (!isset($report_data[$report_id]) || $similarity > $report_data[$report_id]['similarity']) {
        $report_data[$report_id] = $similar_file;
      }
    }

    uasort($report_data, static function ($a, $b) {
      return $b['similarity'] <=> $a['similarity'];
    });

    $reports = $this->getEntityTypeManager()->getStorage('node')->loadMultiple(array_keys($report_data));
    if ($skip_access_check !== TRUE) {
      $reports = array_filter($reports, function ($report) {
        return $report->access('view', $this->getCurrentUser());
      });
    }

    $reports = array_slice($reports, 0, $max_documents, TRUE);

    $results = [];
    foreach ($reports as $id => $report) {
      $file_data = $report_data[$id];
      $results[] = [
        'id' => $id,
        'title' => $report->label(),
        'url' => $report->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'similarity' => $file_data['similarity'],
        'similarity_percentage' => sprintf('%.1f%%', $file_data['similarity'] * 100),
        'score' => $file_data['score'],
      ];
    }

    return $results;
  }

  /**
   * Apply optional file ID range conditions to a query.
   */
  protected function applyFileIdRange(SelectInterface $query, int $start_id, int $end_id): void {
    if ($start_id > 0) {
      $query->condition('fm.fid', $start_id, '>=');
    }
    if ($end_id > 0) {
      $query->condition('fm.fid', $end_id, '<=');
    }
  }

  /**
   * Return the subset of file IDs that already exist in the fingerprints index.
   *
   * @param array $file_ids
   *   Candidate file ids.
   *
   * @return array
   *   Existing fingerprint document ids.
   */
  protected function filterExistingFingerprintIds(array $file_ids): array {
    if (empty($file_ids)) {
      return [];
    }

    try {
      $response = $this->executeFileFingerprintsRequest('POST', '_mget', [
        'json' => [
          'ids' => array_map('strval', $file_ids),
        ],
      ], FALSE);
      if (!$response) {
        return [];
      }
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $existing = [];
      foreach ($data['docs'] ?? [] as $doc) {
        if (!empty($doc['found']) && isset($doc['_id'])) {
          $existing[] = $doc['_id'];
        }
      }
      return $existing;
    }
    catch (RequestException $exception) {
      $this->getLogger()->error('Error checking existing fingerprints: @error', [
        '@error' => $this->formatRequestExceptionMessage($exception),
      ]);
      return [];
    }
  }

  /**
   * Format an exception message including HTTP response body when present.
   */
  protected function formatRequestExceptionMessage(\Throwable $exception): string {
    $message = $exception->getMessage();
    if ($exception instanceof RequestException) {
      $response = $exception->getResponse();
      if ($response) {
        try {
          $body = $response->getBody()->getContents();
          if ($body !== '') {
            $message .= ' | response: ' . $body;
          }
        }
        catch (\Throwable) {
          // Ignore body read failures; keep the original message.
        }
      }
    }
    return $message;
  }

}
