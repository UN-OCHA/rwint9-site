<?php

namespace Drupal\reliefweb_api\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Utils;

/**
 * ReliefWeb API client service class.
 */
class ReliefWebApiClient {

  /**
   * The default cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Map API resources to cache tags.
   *
   * @var array
   */
  protected $cacheTags = [
    'reports' => ['node_list:report'],
    'jobs' => ['node_list:job'],
    'training' => ['node_list:training'],
  ];

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The default cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(CacheBackendInterface $cache_backend, ConfigFactoryInterface $config_factory, TimeInterface $time, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->cache = $cache_backend;
    $this->config = $config_factory->get('reliefweb_api.settings');
    $this->time = $time;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('reliefweb_api');
  }

  /**
   * Perform a POST request against the ReliefWeb API.
   *
   * @param string $resource
   *   API resource endpoint (ex: reports).
   * @param array $payload
   *   API request payload (with fields, filters, sort etc.)
   * @param bool $decode
   *   Whether to decode (json) the output or not.
   * @param int $timeout
   *   Request timeout.
   * @param bool $cache_enabled
   *   Whether to cache the queries or not.
   *
   * @return array|null
   *   The data from the API response or NULL in case of error.
   */
  public function request($resource, array $payload, $decode = TRUE, $timeout = 5, $cache_enabled = TRUE) {
    $queries = [
      $resource => [
        'resource' => $resource,
        'payload' => $payload,
      ],
    ];

    $results = $this->requestMultiple($queries, $decode, $timeout, $cache_enabled);
    return $results[$resource];
  }

  /**
   * Perform parallel queries to the API.
   *
   * This only deals with POST requests.
   *
   * @param array $queries
   *   List of queries to perform in parallel. Each item is an associative
   *   array with the resource and the query payload.
   * @param bool $decode
   *   Whether to decode (json) the output or not.
   * @param int $timeout
   *   Request timeout.
   * @param bool $cache_enabled
   *   Whether to cache the queries or not.
   *
   * @return array
   *   Return array where each item contains the response to the corresponding
   *   query to the API.
   *
   * @see https://docs.guzzlephp.org/en/stable/quickstart.html#concurrent-requests
   */
  public function requestMultiple(array $queries, $decode = TRUE, $timeout = 5, $cache_enabled = TRUE) {
    $results = [];
    $api_url = $this->config->get('api_url');
    $appname = $this->config->get('appname') ?: 'reliefweb.int';
    $cache_enabled = $cache_enabled && ($this->config->get('cache_enabled') ?: TRUE);
    $verify_ssl = $this->config->get('verify_ssl');

    // Initialize the result array and retrieve the data for the cached queries.
    $cache_ids = [];
    foreach ($queries as $index => $query) {
      $payload = $query['payload'] ?? '';

      // Sanitize the query payload.
      if (is_array($payload)) {
        $payload = static::sanitizePayload($payload);
      }

      // Update the query payload.
      $queries[$index]['payload'] = $payload;

      // Attempt to get the data from the cache.
      $results[$index] = NULL;
      if ($cache_enabled) {
        // Retrieve the cache id for the query.
        $cache_id = static::getCacheId($query['resource'], $payload);
        $cache_ids[$index] = $cache_id;
        // Attempt to retrieve the cached data for the query.
        $cache = $this->cache->get($cache_id);
        if (isset($cache->data)) {
          $results[$index] = $cache->data;
        }
      }
    }

    // Prepare the requests.
    $promises = [];
    foreach ($queries as $index => $query) {
      // Skip queries with cached data.
      if (isset($results[$index])) {
        continue;
      }

      $url = $api_url . '/' . $query['resource'] . '?appname=' . $appname;

      // Encode the payload if needed. It may already be an encoded JSON string.
      $payload = $query['payload'] ?? '';
      if (is_array($payload)) {
        $payload = json_encode($payload);

        // Skip the request if something is wrong with the payload.
        if ($payload === FALSE) {
          $results[$index] = NULL;
          $this->logger->error('Could not encode payload when requesting @url: @payload', [
            '@url' => $api_url . '/' . $query['resource'],
            '@payload' => print_r($query['payload'], TRUE),
          ]);
          continue;
        }
      }

      $promises[$index] = $this->httpClient->postAsync($url, [
        'headers' => ['Content-Type: application/json'],
        'body' => $payload,
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'verify' => $verify_ssl,
      ]);
    }

    // Execute the requests in parallel and retrieve and cache the response's
    // data.
    $promise_results = Utils::settle($promises)->wait();
    foreach ($promise_results as $index => $result) {
      $data = NULL;

      // Parse the response in case of success.
      if ($result['state'] === 'fulfilled') {
        $response = $result['value'];

        // Retrieve the raw response's data.
        if ($response->getStatusCode() === 200) {
          $data = (string) $response->getBody();
        }
        else {
          $this->logger->notice('Unable to retrieve API data (code: @code) when requesting @url with payload @payload', [
            '@code' => $response->getStatusCode(),
            '@url' => $api_url . '/' . $queries[$index]['resource'],
            '@payload' => print_r($queries[$index]['payload'], TRUE),
          ]);
          $data = '';
        }
      }
      // Otherwise log the error.
      else {
        $this->logger->notice('Unable to retrieve API data (code: @code) when requesting @url with payload @payload: @reason', [
          '@code' => $result['reason']->getCode(),
          '@url' => $api_url . '/' . $queries[$index]['resource'],
          '@payload' => print_r($queries[$index]['payload'], TRUE),
          '@reason' => $result['reason']->getMessage(),
        ]);
      }

      // Cache the data unless cache is disabled or there was an issue with the
      // request in which case $data is NULL.
      if (isset($cache, $cache_ids[$index])) {
        $tags = static::getCacheTags($query['resource']);
        $this->cache->set($cache_ids[$index], $data, $this->cache::CACHE_PERMANENT, $tags);
      }

      $results[$index] = $data;
    }

    // We don't store the decoded data. This is to ensure that we can use the
    // same cached data regardless of whether to return JSON data or not.
    if ($decode) {
      foreach ($results as $index => $data) {
        if (!empty($data)) {
          // Decode the data, skip if invalid.
          try {
            $data = json_decode($data, TRUE, 512, JSON_THROW_ON_ERROR);
          }
          catch (\Exception $exception) {
            $data = NULL;
            $this->logger->notice('Unable to decode ReliefWeb API data for request @url with payload @payload', [
              '@url' => $api_url . '/' . $queries[$index]['resource'],
              '@payload' => print_r($queries[$index]['payload'], TRUE),
            ]);
          }

          // Ensure the URL aliases of the resources point to the current site.
          if (!empty($data['data'])) {
            static::updateApiUrls($data['data']);
          }

          // Add the resulting data with same index as the query.
          $results[$index] = $data;
        }
      }
    }
    return $results;
  }

  /**
   * Build an API URL.
   *
   * @param string $resource
   *   API resource.
   * @param array $parameters
   *   Query parameters.
   *
   * @return string
   *   API URL.
   */
  public function buildApiUrl($resource, array $parameters = []) {
    // We use a potentially different api url for the facets because it's
    // called from javascript. This is notably useful for dev/stage as the
    // reliefweb_api_url points to an interal url that cannot be used
    // client-side.
    $api_url = $this->config->get('api_url_external') ?: $this->config->get('api_url');
    $appname = $this->config->get('appname') ?: 'reliefweb.int';

    // '%QUERY' is added after the http_build_query so that it's not encoded.
    $api_query = '?' . http_build_query($parameters + [
      'appname' => $appname,
      'preset' => 'suggest',
      'profile' => 'suggest',
      'query[value]' => '',
    ]) . '%QUERY';

    return $api_url . '/' . $resource . $api_query;
  }

  /**
   * Sanitize and simplify an API query payload.
   *
   * @param array $payload
   *   API query payload.
   *
   * @return array
   *   Sanitized payload.
   */
  public static function sanitizePayload(array $payload) {
    // Remove search value and fields if the value is empty.
    if (empty($payload['query']['value'])) {
      unset($payload['query']);
    }
    // Optimize the filter if any.
    if (isset($payload['filter'])) {
      $filter = static::optimizeFilter($payload['filter']);
      if (!empty($filter)) {
        $payload['filter'] = $filter;
      }
      else {
        unset($payload['filter']);
      }
    }
    // Optimize the facet filters if any.
    if (isset($payload['facets'])) {
      foreach ($payload['facets'] as $key => $facet) {
        if (isset($facet['filter'])) {
          $filter = static::optimizeFilter($facet['filter']);
          if (!empty($filter)) {
            $payload['facets'][$key]['filter'] = $filter;
          }
          else {
            unset($payload['facets'][$key]['filter']);
          }
        }
      }
    }
    return $payload;
  }

  /**
   * Optimize a filter, removing uncessary nested conditions.
   *
   * @param array $filter
   *   Filter following the API syntax.
   *
   * @return array
   *   Optimized filter.
   */
  public static function optimizeFilter(array $filter) {
    if (isset($filter['conditions'])) {
      foreach ($filter['conditions'] as $key => $condition) {
        $condition = static::optimizeFilter($condition);
        if (isset($condition)) {
          $filter['conditions'][$key] = $condition;
        }
        else {
          unset($filter['conditions'][$key]);
        }
      }
      // @todo eventually check if it's worthy to optimize by combining
      // filters with same field and same negation inside a conditional filter.
      if (!empty($filter['conditions'])) {
        if (count($filter['conditions']) === 1) {
          $condition = reset($filter['conditions']);
          if (!empty($filter['negate'])) {
            $condition['negate'] = TRUE;
          }
          $filter = $condition;
        }
      }
      else {
        $filter = NULL;
      }
    }
    return !empty($filter) ? $filter : NULL;
  }

  /**
   * Determine the cache id of an API query.
   *
   * @param string $resource
   *   API resource.
   * @param array|string $payload
   *   API payload.
   *
   * @return string
   *   Cache id.
   */
  public static function getCacheId($resource, $payload) {
    $hash = hash('md5', serialize($payload ?? ''));
    return 'reliefweb_api:queries:' . $resource . ':' . $hash;
  }

  /**
   * Determine the cache tags of an API query's resource.
   *
   * @param string $resource
   *   API resource.
   *
   * @return array
   *   Cache tags.
   */
  public static function getCacheTags($resource) {
    $tags = static::$cacheTags[$resource] ?? [];
    // We also always invalidate the cached queries when a term is changed
    // because many different entities may reference the updated term.
    //
    // @todo we could try to go through the list of entities, see which
    // vocabularies they reference and add tags for them only but it's probably
    // not worthy.
    $tags[] = 'taxonomy_term_list';
    return $tags;
  }

  /**
   * Update the host of API URLs.
   *
   * Note: this mostly for development to convert the URLs from the API used
   * for dev (ex: stage) to URLs with the current host and scheme.
   *
   * @param array $data
   *   API data.
   */
  public static function updateApiUrls(array &$data) {
    $request = \Drupal::request();
    $host = $request->getHost();
    $scheme = $request->getScheme();

    if ($host !== 'reliefweb.int') {
      $pattern = '#https?://[^/]+/#';
      $replacement = $scheme . '://' . $host . '/';

      foreach ($data as $key => $item) {
        if (is_string($item) && strpos($key, 'url') === 0) {
          $data[$key] = preg_replace($pattern, $replacement, $item);
        }
        elseif (is_array($item)) {
          static::updateApiUrls($data[$key]);
        }
      }
    }
  }

}
