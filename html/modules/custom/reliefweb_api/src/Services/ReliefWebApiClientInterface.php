<?php

declare(strict_types=1);

namespace Drupal\ocha_reliefweb\Services;

/**
 * Interface for the ReliefWeb API client.
 */
interface ReliefWebApiClientInterface {

  /**
   * Perform a request against the ReliefWeb API.
   *
   * Note: the order of the parameters is to preserve the compatibility with the
   * code calling the previous version of this method.
   *
   * @param string $resource
   *   API resource endpoint (ex: reports).
   * @param ?array $payload
   *   API request payload (with fields, filters, sort etc.)
   * @param bool $decode
   *   Whether to decode (json) the output or not.
   * @param int $timeout
   *   Request timeout.
   * @param bool $cache_enabled
   *   Whether to cache the queries or not.
   * @param string $method
   *   The method (GET, POST, PUT or PATCH) to use for the request.
   * @param array $headers
   *   Extra request headers.
   * @param bool $refresh
   *   If TRUE, skip the cached data and call the API to refresh it.
   *
   * @return array|string|null
   *   The data from the API response or NULL in case of error.
   */
  public function request(
    string $resource,
    ?array $payload = NULL,
    bool $decode = TRUE,
    int $timeout = 5,
    bool $cache_enabled = TRUE,
    string $method = 'POST',
    array $headers = [],
    bool $refresh = FALSE,
  ): array|string|null;

  /**
   * Perform parallel queries to the API.
   *
   * @param array $queries
   *   List of queries to perform in parallel. Each item is an associative
   *   array with the following properties:
   *   - method: request method
   *   - resource: API resource
   *   - payload: optional API payload
   *   - headers: optional headers
   *   - refresh: optional flag to refresh the cached data.
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
  public function requestMultiple(
    array $queries,
    bool $decode = TRUE,
    int $timeout = 5,
    bool $cache_enabled = TRUE,
  ): array;

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
  public function buildApiUrl(
    string $resource,
    array $parameters = [],
    bool $suggest_url = TRUE,
  ): string;

  /**
   * Submit content.
   *
   * @param string $resource
   *   API resource.
   * @param array $payload
   *   Content to submit.
   * @param array $headers
   *   Request headers. This notably must include the X-RW-POST-API-KEY and
   *   X-RW-POST-API-PROVIDER headers.
   * @param int $timeout
   *   Request timeout.
   *
   * @return array
   *   An associative array with the response status code and data.
   *
   * @throws \Exception
   *   An exception if the request was not successful.
   *
   * @todo review the return value.
   */
  public function submitContent(
    string $resource,
    array $payload,
    array $headers,
    int $timeout = 5,
  ): array;

  /**
   * Update the host of API URLs.
   *
   * Note: this mostly for development to convert the URLs from the API used
   * for dev (ex: stage) to URLs with the current host and scheme.
   *
   * @param array $data
   *   API data.
   */
  public static function updateApiUrls(array &$data): void;

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
  public function sanitizePayload(array $payload, bool $combine = FALSE): array;

  /**
   * Get the ReliefWeb UUID namespace.
   *
   * @return string
   *   UUID to use as namespace to generate V5 UUIDs.
   */
  public function getNamespaceUuid(): string;

}
