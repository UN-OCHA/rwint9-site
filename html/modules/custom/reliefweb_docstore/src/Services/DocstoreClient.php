<?php

namespace Drupal\reliefweb_docstore\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;

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
    $response = $this->request('POST', '/api/v1/files/' . $uuid . '/content', [
      'json' => $payload,
    ], $timeout);

    if ($response !== NULL) {
      $body = $response->getBody();
      return !empty($body) ? json_decode($body, TRUE) : NULL;
    }

    return NULL;
  }

  /**
   * Create a file in the docstore.
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
  public function createFileContentFromFilePath($uuid, $path, $timeout = 300) {
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

    if ($response !== NULL) {
      $body = $response->getBody();
      return !empty($body) ? json_decode($body, TRUE) : NULL;
    }

    return NULL;
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
      $endpoint = '/files/' . $uuid;
    }
    else {
      $endpoint = '/api/v1/files/' . $uuid . '/revisions/' . $revision_id . '/content';
    }

    $response = $this->request('GET', $endpoint, [
      'sink' => $resource,
    ], $timeout);

    return !empty($response) && $response->isSuccessful();
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
  protected function request($method, $endpoint, array $options, $timeout = 5) {
    try {
      $url = $this->createDocstoreUrl($entpoint);

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
      'Accept' => 'application/json',
      'API-KEY' => $this->config->get('api_key'),
    ];
  }

}
