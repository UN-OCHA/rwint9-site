<?php

namespace Drupal\reliefweb_docstore\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * ReliefWeb API client service class.
 */
class DocstoreClient {

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

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
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->config = $config_factory->get('reliefweb_docstore.settings');
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('reliefweb_docstore');
  }

  /**
   * Get a file resource in the docstore.
   *
   * @param string $uuid
   *   The file resource UUID.
   * @param int $timeout
   *   Request timeout.
   *
   * @return array|null
   *   The data from the API response or NULL in case of error.
   */
  public function getFile($uuid, $timeout = 5) {
    $response = $this->request('GET', '/api/v1/files/' . $uuid, [], $timeout);

    return $this->decodeResponseBody($response);
  }

  /**
   * Get a revision of a file resource in the docstore.
   *
   * @param string $uuid
   *   The file resource UUID.
   * @param int $revision_id
   *   The ID of the file revision.
   * @param int $timeout
   *   Request timeout.
   *
   * @return array|null
   *   The data from the API response or NULL in case of error.
   */
  public function getFileRevision($uuid, $revision_id, $timeout = 5) {
    $response = $this->request('GET', '/api/v1/files/' . $uuid . '/revisions/' . $revision_id, [], $timeout);

    return $this->decodeResponseBody($response);
  }

  /**
   * Create a file in the docstore.
   *
   * @param array $payload
   *   API request payload (with fields, filters, sort etc.)
   * @param int $timeout
   *   Request timeout.
   *
   * @return array|null
   *   The data from the API response or NULL in case of error.
   */
  public function createFile(array $payload, $timeout = 5) {
    $response = $this->request('POST', '/api/v1/files', [
      'json' => $payload,
    ], $timeout);

    return $this->decodeResponseBody($response);
  }

  /**
   * Update a file's content in the docstore.
   *
   * @param string $uuid
   *   Docstore file resource UUID.
   * @param string $path
   *   File path.
   * @param int $timeout
   *   Request timeout.
   *
   * @return array|null
   *   The data from the API response or NULL in case of error.
   */
  public function updateFileContentFromFilePath($uuid, $path, $timeout = 300) {
    try {
      $resource = Utils::TryFopen($path, 'r');
    }
    catch (\RuntimeExecption $exception) {
      $this->logger->error('Unable to read file @path', [
        '@path' => $path,
      ]);
      return NULL;
    }

    $response = $this->request('POST', '/api/v1/files/' . $uuid . '/content', [
      'body' => $resource,
    ], $timeout);

    return $this->decodeResponseBody($response);
  }

  /**
   * Download a docstore file content to the given file path.
   *
   * @param string $uuid
   *   The docstore file resource UUID.
   * @param string $path
   *   The path of the file in which the content will be saved.
   * @param int $revision_id
   *   The revision of the docstore file resource.
   * @param int $timeout
   *   Request timeout.
   *
   * @return bool
   *   TRUE if the request was successful.
   */
  public function downloadFileContentToFilePath($uuid, $path, $revision_id = 0, $timeout = 300) {
    try {
      $resource = Utils::TryFopen($path, 'w');
    }
    catch (\RuntimeExecption $exception) {
      $this->logger->error('Unable to open file @path for writing', [
        '@path' => $path,
      ]);
      return NULL;
    }

    // Assume we want to get the latest revision in which case we can do
    // a direct call the https://docstore/files/uuid endpoint which is much
    // faster if the file is public.
    // @todo this doesn't take into account the fact that the file may be
    // hidden for the provider in that case we need to use the API endpoint.
    if (empty($revision_id)) {
      $endpoint = '/files/' . $uuid . '/' . basename($path);
    }
    else {
      $endpoint = '/api/v1/files/' . $uuid . '/revisions/' . $revision_id . '/content';
    }

    $response = $this->request('GET', $endpoint, [
      'sink' => $resource,
    ], $timeout);

    return $this->isResponseSuccessful($response);
  }

  /**
   * Update a file status in the docstore.
   *
   * @param string $uuid
   *   The file resource UUID.
   * @param bool $private
   *   TRUE if the file should be made private.
   * @param int $timeout
   *   Request timeout.
   *
   * @return array|null
   *   The data from the API response or NULL in case of error.
   */
  public function updateFileStatus($uuid, $private, $timeout = 5) {
    $response = $this->request('PATCH', '/api/v1/files/' . $uuid, [
      'json' => [
        'private' => $private,
      ],
    ], $timeout);

    return $this->decodeResponseBody($response);
  }

  /**
   * Select the file revision to make active for us.
   *
   * @param string $uuid
   *   The file resource UUID.
   * @param int $revision_id
   *   The file revision id.
   * @param int $timeout
   *   Request timeout.
   *
   * @return bool
   *   TRUE on success.
   */
  public function selectFileRevision($uuid, $revision_id, $timeout = 5) {
    $response = $this->request('PUT', '/api/v1/files/' . $uuid . '/select', [
      'json' => [
        'target' => $revision_id,
      ],
    ], $timeout);

    return $this->isResponseSuccessful($response);
  }

  /**
   * Delete a file.
   *
   * @param string $uuid
   *   The file resource UUID.
   * @param int $timeout
   *   Request timeout.
   *
   * @return bool
   *   TRUE on success.
   */
  public function deleteFile($uuid, $timeout = 30) {
    $response = $this->request('DELETE', '/api/v1/files/' . $uuid, [], $timeout);

    return $this->isResponseSuccessful($response);
  }

  /**
   * Delete a file revision.
   *
   * @param string $uuid
   *   The file resource UUID.
   * @param int $revision_id
   *   The file revision id.
   * @param int $timeout
   *   Request timeout.
   *
   * @return bool
   *   TRUE on success.
   */
  public function deleteFileRevision($uuid, $revision_id, $timeout = 10) {
    $response = $this->request('DELETE', '/api/v1/files/' . $uuid . '/revisions/' . $revision_id, [], $timeout);

    return $this->isResponseSuccessful($response);
  }

  /**
   * Perform a request against the docstore API.
   *
   * @param string $method
   *   Method.
   * @param string $endpoint
   *   API Endpoint. This can also be a full URL for example to retrieve a file.
   * @param array $options
   *   Request options.
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   Response.
   */
  public function request($method, $endpoint, array $options, $timeout = 5) {
    try {
      $url = $this->createDocstoreUrl($endpoint);

      $headers = $this->getHeaders();
      if (isset($options['headers'])) {
        $headers += $options['headers'];
        unset($options['headers']);
      }

      return $this->httpClient->request($method, $url, [
        'timeout' => $timeout,
        'headers' => $headers,
      ] + $options);
    }
    catch (\Exception $exception) {
      $this->logger->error('Unable to perform the @method request to the @endpoint endpoint with the options: @options: @error', [
        '@method' => $method,
        '@endpoint' => $endpoint,
        '@options' => print_r($options, TRUE),
        '@error' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Decode the response body.
   *
   * Note: this assumes the body is JSON encoded data.
   *
   * @param \Psr\Http\Message\ResponseInterface|null $response
   *   The request's response.
   *
   * @return mixed
   *   NULL if the response body couldn't be decoded otherwise whatever the
   *   was in the body.
   */
  public function decodeResponseBody(?ResponseInterface $response) {
    if ($response !== NULL) {
      $body = $response->getBody();
      return !empty($body) ? json_decode($body, TRUE) : NULL;
    }
    return NULL;
  }

  /**
   * Check if a response is sucessful.
   *
   * @param \Psr\Http\Message\ResponseInterface|null $response
   *   The request's response.
   *
   * @return bool
   *   TRUE if the response is successful.
   */
  public function isResponseSuccessful(?ResponseInterface $response) {
    if ($response !== NULL) {
      $code = $response->getStatusCode();
      return $code >= 200 && $code < 300;
    }
    return FALSE;
  }

  /**
   * Create a URL to the docstore for the given endpoint.
   *
   * @param string $endpoint
   *   Docstore endpoint (ex: API endpoint).
   *
   * @return string
   *   Full docstore URL for the endpoint.
   */
  protected function createDocstoreUrl($endpoint = '') {
    $docstore_url = $this->config->get('docstore_url');
    if (empty($docstore_url)) {
      throw  new \Execption('The docstore URL is not defined');
    }
    if (!empty($endpoint)) {
      return rtrim($docstore_url, '/') . '/' . ltrim($endpoint, '/');
    }
    return $docstore_url;
  }

  /**
   * Get the base headers for API requests.
   *
   * @return array
   *   Headers.
   */
  protected function getHeaders() {
    return [
      'API-KEY' => $this->config->get('api_key'),
      'X-Docstore-Provider-Uuid' => $this->config->get('provider_uuid'),
    ];
  }

}
