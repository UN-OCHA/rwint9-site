<?php

declare (strict_types=1);

namespace Drupal\reliefweb_files\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\reliefweb_utility\Helpers\FileHelper;
use GuzzleHttp\ClientInterface;
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
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
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
    ?bool $only_published = NULL,
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

    // Get config values for fallback when parameters are NULL.
    $config = $this->getConfigFactory()->get('reliefweb_files.settings');
    $duplicate_config = $config->get('duplicate_detection') ?? [];

    // Use config values as fallback when parameters are NULL.
    $max_documents ??= $duplicate_config['max_documents'] ?? 10;
    $minimum_should_match ??= $duplicate_config['minimum_should_match'] ?? '80%';
    $max_files ??= $duplicate_config['max_files'] ?? 20;
    $only_published ??= $duplicate_config['only_published'] ?? TRUE;

    // 1. Get file IDs to exclude from the search results from the documents
    // to exclude.
    $exclude_file_ids = [];
    if (!empty($exclude_document_ids)) {
      $exclude_file_query = $this->createNodeFieldFileQuery($bundle);
      $exclude_file_query->condition('nff.entity_id', $exclude_document_ids, 'IN');
      $exclude_file_ids = $exclude_file_query->execute()->fetchCol();
    }

    // 2. Find similar file fingerprints using the dedicated file fingerprints
    // index.
    $similar_files = $this->findSimilarFileFingerprints($input_text, $max_files, $minimum_should_match, $exclude_file_ids);
    if (empty($similar_files)) {
      $logger->notice('No similar files found.');
      return [];
    }

    // 3. Extract file IDs from similar files.
    $file_ids = array_column($similar_files, 'id');

    // 4. Find reports that contain these files by querying the database.
    // Start with base query and customize for this specific use case
    $query = $this->createNodeFieldFileQuery($bundle);
    $query->fields('nff', ['entity_id', 'field_file_file_uuid']);
    $query->condition('fm.fid', $file_ids, 'IN');

    // Add filter to exclude specific document IDs if provided.
    if (!empty($exclude_document_ids)) {
      $query->condition('nff.entity_id', $exclude_document_ids, 'NOT IN');
    }

    // Join with node_field_data to get title and status.
    $query->join('node_field_data', 'nfd', 'nff.entity_id = nfd.nid');
    $query->fields('nfd', ['title', 'status']);

    // Only include published nodes.
    if ($only_published) {
      $query->condition('nfd.status', 1);
    }

    $query->range(0, $max_documents);
    $report_data = $query->execute()->fetchAll();

    if (empty($report_data)) {
      $logger->notice('No reports found with similar files.');
      return [];
    }

    // 5. Build results with similarity scores.
    $results = [];
    $report_similarities = [];

    // Group file similarities by report ID for efficient lookup.
    foreach ($report_data as $row) {
      $report_id = $row->entity_id;
      $file_uuid = $row->field_file_file_uuid;

      // Find the similarity score for this file by matching UUID.
      foreach ($similar_files as $similar_file) {
        if ($similar_file['uuid'] === $file_uuid) {
          // Keep the highest similarity score for this report.
          if (!isset($report_similarities[$report_id]) || $similar_file['similarity'] > $report_similarities[$report_id]) {
            $report_similarities[$report_id] = $similar_file['similarity'];
          }
          break;
        }
      }
    }

    // Convert minimum_should_match percentage to decimal for comparison.
    $minimum_similarity_threshold = floatval($minimum_should_match) / 100;

    // Build the final results.
    foreach ($report_data as $row) {
      $report_id = $row->entity_id;

      // Skip if we already processed this report or if it has no similarity.
      if (!isset($report_similarities[$report_id]) || $report_similarities[$report_id] <= 0) {
        continue;
      }

      $similarity = $report_similarities[$report_id];

      // Skip reports below the minimum similarity threshold.
      if ($similarity < $minimum_similarity_threshold) {
        continue;
      }

      // Build the node URL.
      $url = Url::fromUri("internal:/node/$report_id", ['absolute' => TRUE])->toString();

      $results[] = [
        'id' => $report_id,
        'title' => $row->title,
        'url' => $url,
        'similarity' => $similarity,
        'similarity_percentage' => sprintf('%.1f%%', $similarity * 100),
        'score' => $similarity,
      ];

      // Remove from similarities array to avoid duplicates.
      unset($report_similarities[$report_id]);
    }

    // Sort by similarity score (descending).
    usort($results, function ($a, $b) {
      return $b['similarity'] <=> $a['similarity'];
    });

    return array_slice($results, 0, $max_documents);
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
    $index_url = $this->getElasticsearchEndpointUrl();

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
      $response = $this->getHttpClient()->request('PUT', $index_url, [
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
    $index_url = $this->getElasticsearchEndpointUrl();

    try {
      $response = $this->getHttpClient()->request('DELETE', $index_url);

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
      $logger->error('Error analyzing text: ' . $exception->getMessage());
      return [];
    }

    // 2. Search for similar file fingerprints.
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
                  'like' => [
                    '_index' => $this->getFileFingerprintsIndexName(),
                    'doc' => [
                      'minhash' => $input_text,
                    ],
                  ],
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
      $logger->error('Error searching for similar file fingerprints: ' . $exception->getMessage());
      return [];
    }

    $hits = $search_data['hits']['hits'] ?? [];
    if (empty($hits)) {
      return [];
    }

    // 3. Calculate similarity scores.
    $results = [];
    foreach ($hits as $hit) {
      $file_id = $hit['_id'];

      // Use the Elasticsearch explain API with the same MLT query to retrieve
      // the number of matching tokens. The explain result contains the list
      // of terms (the minhash tokens) that contributed to the search query
      // score. In our case, since we have a fixed number of tokens we can
      // calculate the Jaccard similarity which the number of matching tokens.
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

        // Count the number of matching MinHash tokens. We can use a simple
        // substring count.
        $matched_tokens = substr_count($explain_body, 'weight(minhash:');
      }
      catch (\Exception $exception) {
        $logger->error('Error explaining match for file @id: @error', [
          '@id' => $file_id,
          '@error' => $exception->getMessage(),
        ]);
        continue;
      }

      // Calculate the Jaccard similarity score (number of matching tokens /
      // total unique tokens).
      $similarity = $num_query_unique_tokens > 0 ? ($matched_tokens / $num_query_unique_tokens) : 0;
      $source = $hit['_source'] ?? [];

      $results[] = [
        'id' => (int) $source['id'],
        'uuid' => $source['uuid'] ?? '',
        'similarity' => $similarity,
        'similarity_percentage' => sprintf('%.1f%%', $similarity * 100),
        'score' => $hit['_score'] ?? 0,
      ];
    }

    return $results;
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

    // Extract text content for minhash (optional - files without text are still
    // indexed).
    $file_text = FileHelper::extractText($file, file_system: $file_system);

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
      }
      else {
        $results['failed']++;
      }
    }

    if (empty($valid_files)) {
      return $results;
    }

    // Extract text from all files in parallel.
    $extracted_texts = FileHelper::extractTextParallel($valid_files, $processes, file_system: $file_system);

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
              $logger->warning('Failed to index file fingerprint in bulk operation: @error', [
                '@error' => $item['index']['error']['reason'] ?? 'Unknown error',
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
        '@error' => $exception->getMessage(),
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
    $endpoint_url = $this->getElasticsearchEndpointUrl($endpoint);

    try {
      $response = $this->getHttpClient()->request($method, $endpoint_url, $options);
      return $response;
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
                return $this->getHttpClient()->request($method, $endpoint_url, $options);
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
   *
   * @return int
   *   The total number of files to process.
   */
  public function getFileCountForIndexing(int $start_id = 0, int $end_id = 0, int $limit = 0): int {
    $query = $this->createNodeFieldFileQuery();

    // Add ID range conditions if provided.
    if ($start_id > 0 || $end_id > 0) {
      if ($start_id > 0) {
        $query->condition('fm.fid', $start_id, '>=');
      }
      if ($end_id > 0) {
        $query->condition('fm.fid', $end_id, '<=');
      }
    }

    $count = (int) $query->countQuery()->execute()->fetchField();

    // Apply limit if provided and smaller than count.
    return $limit > 0 ? min($count, $limit) : $count;
  }

  /**
   * Optimized file indexing with chunked processing and file ID tracking.
   *
   * @param int $chunk_size
   *   Number of files to process in each chunk.
   * @param int $start_id
   *   Optional start file ID (inclusive).
   * @param int $end_id
   *   Optional end file ID (inclusive).
   * @param int $limit
   *   Maximum number of files to process (0 for no limit).
   * @param int $processes
   *   Number of parallel processes to use for text extraction.
   * @param callable|null $progress_callback
   *   Optional callback function for progress updates.
   *
   * @return array
   *   Array with 'success' and 'failed' counts.
   */
  public function indexFiles(
    int $chunk_size,
    int $start_id = 0,
    int $end_id = 0,
    int $limit = 0,
    int $processes = 4,
    ?callable $progress_callback = NULL,
  ): array {
    $logger = $this->getLogger();
    $file_storage = $this->getEntityTypeManager()->getStorage('file');

    // Get the total number of files to process.
    $total_files = $this->getFileCountForIndexing($start_id, $end_id, $limit);
    if ($total_files === 0) {
      $logger->warning('No files found to index.');
      return ['success' => 0, 'failed' => 0];
    }

    $logger->info("Found $total_files files to index.");

    $indexed = 0;
    $errors = 0;
    $processed = 0;

    // Start with the highest file ID (or start_id if provided).
    $current_max_id = $start_id > 0 ? $start_id : $this->getMaxFileId();

    // Process files in chunks using file ID tracking.
    while ($processed < $total_files && $current_max_id > 0) {
      // Calculate how many files to process in this chunk (respect the limit).
      $remaining_files = $total_files - $processed;
      $chunk_limit = min($chunk_size, $remaining_files);

      $logger->info("Processing chunk starting from file ID $current_max_id (up to $chunk_limit files)");

      // Get file IDs for this chunk (descending order).
      $chunk_file_query = $this->createNodeFieldFileQuery()
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

      // Load all files in this chunk at once for better performance.
      $files = $file_storage->loadMultiple($chunk_file_ids);
      if (empty($files)) {
        $logger->warning("No files found in chunk starting from file ID $current_max_id");
        $errors += count($chunk_file_ids);
        $processed += count($chunk_file_ids);
        // Move to next chunk by setting current_max_id to the smallest ID
        // minus 1.
        $current_max_id = min($chunk_file_ids) - 1;
        continue;
      }

      // Use bulk indexing for better performance.
      $bulk_results = $this->bulkIndexFileFingerprints($files, $processes);
      $indexed += $bulk_results['success'];
      $errors += $bulk_results['failed'];
      $processed += count($chunk_file_ids);

      // Log progress.
      $logger->info("Bulk indexed " . $bulk_results['success'] . " files, " . $bulk_results['failed'] . " failed in this chunk");

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

    return ['success' => $indexed, 'failed' => $errors];
  }

  /**
   * Create a base query for node__field_file with file_managed join.
   *
   * @param string $bundle
   *   The bundle to filter by.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The configured query object returning distinct file IDs by default.
   */
  protected function createNodeFieldFileQuery(
    string $bundle = 'report',
  ): SelectInterface {
    $query = $this->getDatabase()->select('node__field_file', 'nff')
      ->fields('fm', ['fid'])
      ->condition('nff.bundle', $bundle, '=')
      ->distinct();

    // Join with file_managed table to get file IDs from UUIDs.
    $query->join('file_managed', 'fm', 'nff.field_file_file_uuid = fm.uuid');
    return $query;
  }

  /**
   * Get the maximum file ID from the database.
   *
   * @return int
   *   The maximum file ID, or 0 if no files exist.
   */
  protected function getMaxFileId(): int {
    $query = $this->createNodeFieldFileQuery()
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
    $config = $this->getConfigFactory()->get('reliefweb_api.settings');
    $base_index_name = $config->get('base_index_name') ?? 'reliefweb';
    return $base_index_name . '_file_fingerprints';
  }

  /**
   * Get Elasticsearch URL.
   *
   * @return string
   *   The Elasticsearch URL.
   */
  protected function getElasticsearchUrl(): string {
    $config = $this->getConfigFactory()->get('reliefweb_api.settings');
    return rtrim($config->get('elasticsearch') ?? 'http://elasticsearch:9200', '/');
  }

  /**
   * Get Elasticsearch endpoint URL.
   *
   * @return string
   *   The Elasticsearch endpoint URL.
   */
  protected function getElasticsearchEndpointUrl(string $endpoint = ''): string {
    $elasticsearch_url = $this->getElasticsearchUrl();
    $index_name = $this->getFileFingerprintsIndexName();
    return rtrim($elasticsearch_url . '/' . $index_name . '/' . ltrim($endpoint, '/'), '/');
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
   * Get the HTTP client service.
   *
   * @return \GuzzleHttp\ClientInterface
   *   The HTTP client service.
   */
  protected function getHttpClient(): ClientInterface {
    return $this->httpClient;
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

}
