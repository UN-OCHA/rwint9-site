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
  protected static $cacheTags = [
    'blog' => ['node_list:blog_post'],
    'reports' => ['node_list:report'],
    'jobs' => ['node_list:job'],
    'training' => ['node_list:training'],
  ];

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
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
    $cache_enabled = $cache_enabled && ($this->config->get('cache_enabled') ?? TRUE);
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

      try {
        $promises[$index] = $this->httpClient->postAsync($url, [
          'headers' => ['Content-Type: application/json'],
          'body' => $payload,
          'timeout' => $timeout,
          'connect_timeout' => $timeout,
          'verify' => $verify_ssl,
        ]);
      }
      catch (\Exception $exception) {
        $this->logger->error('Exception while querying @url: @exception', [
          '@url' => $api_url . '/' . $query['resource'],
          '@exception' => $exception->getMessage(),
        ]);
      }
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
      if (isset($cache, $cache_ids[$index], $queries[$index]['resource'])) {
        $tags = static::getCacheTags($queries[$index]['resource']);
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
   * This is mostly used to build a suggestion API URL.
   *
   * @param string $resource
   *   API resource.
   * @param array $parameters
   *   Query parameters.
   * @param bool $suggest_url
   *   TRUE to create a suggestion URL (for example to use in the UI filters).
   *
   * @return string
   *   API URL.
   */
  public function buildApiUrl($resource, array $parameters = [], $suggest_url = TRUE) {
    // We use a potentially different api url for the facets because it's
    // called from javascript. This is notably useful for dev/stage as the
    // reliefweb_api_url points to an interal url that cannot be used
    // client-side.
    $api_url = $this->config->get('api_url_external') ?: $this->config->get('api_url');

    // Defaults.
    if (empty($parameters['appname'])) {
      $parameters['appname'] = $this->config->get('appname') ?: 'reliefweb.int';
    }
    if (empty($parameters['preset'])) {
      $parameters['preset'] = $suggest_url ? 'suggest' : 'latest';
    }
    if (empty($parameters['profile'])) {
      $parameters['profile'] = $suggest_url ? 'suggest' : 'list';
    }

    // Remove the search query. We will add it back with `%QUERY` as value
    // later so that is can be replaced with the appropriate query string
    // in the river filter UI for example.
    if ($suggest_url) {
      unset($parameters['query']['value']);
    }

    // No need to return extra data.
    $parameters['slim'] = 1;

    // Generate the API URL parameters.
    $api_query = '?' . http_build_query($parameters);

    // '%QUERY' is added after the http_build_query so that it's not encoded
    // and appears as the query string value.
    if ($suggest_url) {
      $api_query .= '&' . rawurlencode('query[value]') . '=%QUERY';
    }

    return $api_url . '/' . $resource . $api_query;
  }

  /**
   * Sanitize and simplify an API query payload.
   *
   * @param array $payload
   *   API query payload.
   * @param bool $combine
   *   TRUE to optimize the filters by combining their values when possible.
   *
   * @return array
   *   Sanitized payload.
   */
  public static function sanitizePayload(array $payload, $combine = FALSE) {
    // Remove search value and fields if the value is empty.
    if (empty($payload['query']['value'])) {
      unset($payload['query']);
    }
    // Optimize the filter if any.
    if (isset($payload['filter'])) {
      $filter = static::optimizeFilter($payload['filter'], $combine);

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
          $filter = static::optimizeFilter($facet['filter'], $combine);
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
   * @param bool $combine
   *   TRUE to optimize even more the filter by combining values when possible.
   *
   * @return array|null
   *   Optimized filter.
   */
  public static function optimizeFilter(array $filter, $combine = FALSE) {
    if (isset($filter['conditions'])) {
      if (isset($filter['operator'])) {
        $filter['operator'] = strtoupper($filter['operator']);
      }

      foreach ($filter['conditions'] as $key => $condition) {
        $condition = static::optimizeFilter($condition, $combine);
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
        if ($combine) {
          $filter['conditions'] = static::combineConditions($filter['conditions'], $filter['operator'] ?? NULL);
        }
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
   * Combine simple filter conditions to shorten the filters.
   *
   * @param array $conditions
   *   Filter conditions.
   * @param string $operator
   *   Operator to join the conditions.
   *
   * @return array
   *   Combined and simplied filter conditions.
   */
  public static function combineConditions(array $conditions, $operator = 'AND') {
    $operator = $operator ?: 'AND';
    $filters = [];
    $result = [];

    foreach ($conditions as $condition) {
      $field = $condition['field'] ?? NULL;
      $value = $condition['value'] ?? NULL;
      $condition_operator = $condition['operator'] ?? NULL;

      // Nested conditions - flatten the condition's conditions.
      if (!empty($condition['conditions'])) {
        $condition['conditions'] = static::combineConditions($condition['conditions'], $condition_operator);
        $result[] = $condition;
      }
      // Existence filter - keep as is.
      elseif (is_null($value)) {
        $result[] = $condition;
      }
      // Range filter - keep as is.
      elseif (is_array($value) && (isset($value['to']) || isset($value['to']))) {
        $result[] = $condition;
      }
      // Different operator or negated condition -  keep as is.
      elseif ((isset($condition_operator) && $condition_operator !== $operator) || !empty($condition['negate'])) {
        $result[] = $condition;
      }
      elseif (is_array($value)) {
        foreach ($value as $item) {
          $filters[$field][] = $item;
        }
      }
      else {
        $filters[$field][] = $value;
      }
    }

    foreach ($filters as $field => $values) {
      $filter = [
        'field' => $field,
      ];

      $value = array_unique($values);
      if (count($value) === 1) {
        $filter['value'] = reset($value);
      }
      else {
        $filter['value'] = $value;
        $filter['operator'] = $operator;
      }
      $result[] = $filter;
    }
    return $result;
  }

  /**
   * Determine the cache id of an API query.
   *
   * @param string $resource
   *   API resource.
   * @param array|string|null $payload
   *   API payload.
   *
   * @return string
   *   Cache id.
   */
  public static function getCacheId($resource, $payload) {
    $hash = hash('sha256', serialize($payload ?? ''));
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
