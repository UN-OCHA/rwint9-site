<?php

declare (strict_types=1);

namespace Drupal\reliefweb_api\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * ReliefWeb API file duplication service class.
 *
 * This service handles file duplication detection.
 */
class ReliefWebApiFileDuplication implements ReliefWebApiFileDuplicationInterface {

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
   */
  public function __construct(
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function findSimilarDocuments(
    string $input_text,
    string $bundle = 'report',
    int $max_documents = 10,
    string $minimum_should_match = '80%',
    array $exclude_ids = [],
  ): array {
    if (empty($input_text)) {
      $this->getLogger()->warning('Input text is empty.');
      return [];
    }

    if (empty($bundle)) {
      $this->getLogger()->warning('Bundle is empty.');
      return [];
    }

    // Get elasticsearch settings from config.
    $config = $this->getConfigFactory()->get('reliefweb_api.settings');
    $elasticsearch_url = rtrim($config->get('elasticsearch') ?? 'http://elasticsearch:9200', '/');
    $base_index_name = $config->get('base_index_name') ?? 'reliefweb';
    $index_bundle_tag = $this->getState()->get('reliefweb_api_index_tag_' . $bundle, '');
    $index = $base_index_name . '_reports_index_' . $index_bundle_tag;
    $index_url = $elasticsearch_url . '/' . $index;

    // 1. Retrieve and count the number of MinHash tokens for the input text.
    try {
      $analyze_response = $this->getHttpClient()->request('POST', $index_url . '/_analyze', [
        'json' => [
          'analyzer' => 'minhash_analyzer',
          'text' => $input_text,
        ],
      ]);
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
        $this->getLogger()->warning('No minhash tokens returned for input text.');
        return [];
      }
    }
    catch (\Exception $exception) {
      $this->getLogger()->error('Error analyzing text: ' . $exception->getMessage());
      return [];
    }

    // 2. Retrieve documents with files with a similar fingerprint.
    try {
      $query = [
        'size' => $max_documents,
        '_source' => ['id', 'title', 'url', 'file.minhash_content'],
        'query' => [
          'bool' => [
            'must' => [
              [
                'more_like_this' => [
                  'fields' => ['file.minhash_content'],
                  'like' => [
                    '_index' => $index,
                    // Artificial document to compare.
                    'doc' => [
                      'file.minhash_content' => $input_text,
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

      // Add filter to exclude specific document IDs if provided.
      if (!empty($exclude_ids)) {
        $query['query']['bool']['filter'] = [
          [
            'bool' => [
              'must_not' => [
                ['terms' => ['_id' => $exclude_ids]],
              ],
            ],
          ],
        ];
      }
      $search_response = $this->getHttpClient()->request('POST', $index_url . '/_search', [
        'json' => $query,
      ]);
      $search_body = $search_response->getBody()->getContents();
      $search_data = json_decode($search_body, TRUE);
    }
    catch (RequestException $exception) {
      $this->getLogger()->error('Error searching for similar files: ' . $exception->getMessage());
      return [];
    }

    $hits = $search_data['hits']['hits'] ?? [];
    if (empty($hits)) {
      $this->getLogger()->notice('No similar documents found.');
      return [];
    }

    // 3. Calculate the Jaccard similarity score (number of matching tokens) and
    // populate the results.
    $results = [];
    foreach ($hits as $hit) {
      $doc_id = $hit['_id'];

      // Use the Elasticsearch explain API with the same MLT query to retrieve
      // the number of matching tokens. The explain result contains the list
      // of terms (the minhash tokens) that contributed to the search query
      // score. In our case, since we have a fixed number of tokens we can
      // calculate the Jaccard similarity which the number of matching tokens.
      try {
        $explain_url = $index_url . '/_explain/' . $doc_id;
        $explain_response = $this->getHttpClient()->request('POST', $explain_url, [
          'json' => [
            'query' => $query['query'],
          ],
        ]);
        $explain_body = $explain_response->getBody()->getContents();

        // Count the number of matching MinHash tokens. We can use a simple
        // substring count.
        $matched_tokens = substr_count($explain_body, 'weight(file.minhash_content:');
      }
      catch (\Exception $exception) {
        $this->getLogger()->error('Error explaining match for doc ' . $doc_id . ': ' . $exception->getMessage());
        $matched_tokens = 0;
        $similarity = 0;
        continue;
      }

      $similarity = $num_query_unique_tokens > 0 ? ($matched_tokens / $num_query_unique_tokens) : 0;
      $source = $hit['_source'] ?? [];

      $results[] = [
        'id' => $source['id'] ?? $doc_id,
        'title' => $source['title'] ?? '',
        'url' => $source['url'] ?? '',
        'similarity' => $similarity,
        'similarity_percentage' => sprintf('%.1f%%', $similarity * 100),
        'score' => $hit['_score'] ?? 0,
      ];
    }

    return $results;
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
   * Get the logger for this service.
   *
   * @param string $channel
   *   The logger channel name.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger service.
   */
  protected function getLogger(string $channel = 'reliefweb_api'): LoggerInterface {
    return $this->loggerFactory->get($channel);
  }

}
