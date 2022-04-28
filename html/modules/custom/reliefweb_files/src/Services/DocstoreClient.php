<?php

namespace Drupal\reliefweb_files\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\StreamWrapper;
use Drupal\reliefweb_utility\Response\JsonResponse;

/**
 * ReliefWeb Docstore client service class.
 */
class DocstoreClient {

  /**
   * ReliefWeb Files config.
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
    $this->config = $config_factory->get('reliefweb_files.settings');
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('reliefweb_files');
  }

  /**
   * Get a file resource in the docstore.
   *
   * @param string $uuid
   *   The file resource UUID.
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function getFile($uuid, $timeout = 5) {
    return $this->request('GET', '/api/v1/files/' . $uuid, [], $timeout);
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
   * @return Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function getFileRevision($uuid, $revision_id, $timeout = 5) {
    return $this->request('GET', '/api/v1/files/' . $uuid . '/revisions/' . $revision_id, [], $timeout);
  }

  /**
   * Create a file in the docstore.
   *
   * @param array $payload
   *   API request payload (with fields, filters, sort etc.)
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function createFile(array $payload, $timeout = 5) {
    return $this->request('POST', '/api/v1/files', [
      'json' => $payload,
    ], $timeout);
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
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function updateFileContentFromFilePath($uuid, $path, $timeout = 300) {
    try {
      $resource = Utils::TryFopen($path, 'r');
    }
    catch (\RuntimeException $exception) {
      $this->logger->error('Unable to read file @path', [
        '@path' => $path,
      ]);
      return new JsonResponse();
    }

    $response = $this->request('POST', '/api/v1/files/' . $uuid . '/content', [
      'body' => $resource,
    ], $timeout);

    if (is_resource($resource)) {
      @fclose($resource);
    }

    return $response;
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
    // Assume we want to get the latest revision if revision id is empty.
    if (empty($revision_id)) {
      $endpoint = '/api/v1/files/' . $uuid . '/content';
    }
    else {
      $endpoint = '/api/v1/files/' . $uuid . '/revisions/' . $revision_id . '/content';
    }

    $response = $this->request('GET', $endpoint, [
      'stream' => TRUE,
    ], $timeout);

    if ($response->isSuccessful()) {
      try {
        $output = Utils::TryFopen($path, 'w');
      }
      catch (\RuntimeException $exception) {
        $this->logger->error('Unable to open file @path for writing', [
          '@path' => $path,
        ]);
        return FALSE;
      }

      $input = StreamWrapper::getResource($response->getBody());
      $result = stream_copy_to_stream($input, $output) !== FALSE;

      if (is_resource($input)) {
        @fclose($input);
      }
      if (is_resource($output)) {
        @fclose($output);
      }

      return $result;
    }
    return FALSE;
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
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function updateFileStatus($uuid, $private, $timeout = 5) {
    return $this->request('PATCH', '/api/v1/files/' . $uuid, [
      'json' => [
        'private' => $private,
      ],
    ], $timeout);
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

    return $response->isSuccessful();
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

    return $response->isSuccessful();
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

    return $response->isSuccessful();
  }

  /**
   * Get a document resource in the docstore.
   *
   * @param string $uuid
   *   The document resource UUID.
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function getDocument($uuid, $timeout = 5) {
    return $this->request('GET', $this->getDocumentEndpoint($uuid), [], $timeout);
  }

  /**
   * Create a document in the docstore.
   *
   * @param array $payload
   *   API request payload (with fields, filters, sort etc.)
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function createDocument(array $payload, $timeout = 5) {
    // Check if the document type exists.
    $response = $this->getDocumentType();
    // Or try to create it.
    if ($response->isNotFound()) {
      $response = $this->createDocumentType();
      if (!$response->isSuccessful()) {
        return $response;
      }
    }

    // Create the document.
    return $this->request('POST', $this->getDocumentEndpoint(), [
      'json' => $payload + [
        'author' => 'reliefweb',
      ],
    ], $timeout);
  }

  /**
   * Update a document in the docstore.
   *
   * @param string $uuid
   *   The document resource UUID.
   * @param array $payload
   *   API request payload (with fields, filters, sort etc.)
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function updateDocument($uuid, array $payload, $timeout = 5) {
    return $this->request('PATCH', $this->getDocumentEndpoint($uuid), [
      'json' => $payload,
    ], $timeout);
  }

  /**
   * Delete a document.
   *
   * @param string $uuid
   *   The document resource UUID.
   * @param int $timeout
   *   Request timeout.
   *
   * @return bool
   *   TRUE on success.
   */
  public function deleteDocument($uuid, $timeout = 30) {
    $response = $this->request('DELETE', $this->getDocumentEndpoint($uuid), [], $timeout);

    return $response->isSuccessful();
  }

  /**
   * Create the ReliefWeb document type in the docstore.
   *
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function getDocumentType($timeout = 5) {
    $endpoint = '/api/v1/types/' . $this->getDocstoreDocumentType();
    return $this->request('GET', $endpoint, [], $timeout);
  }

  /**
   * Create the ReliefWeb document type in the docstore.
   *
   * @param int $timeout
   *   Request timeout.
   *
   * @return \Drupal\reliefweb_utility\Response\JsonResponse
   *   Response.
   */
  public function createDocumentType($timeout = 5) {
    $document_type = $this->getDocstoreDocumentType();

    $payload = [
      'machine_name' => $document_type,
      'endpoint' => $this->getDocstoreDocumentTypeEndpoint(),
      'label' => $document_type,
      'shared' => FALSE,
      'content_allowed' => FALSE,
      'fields_allowed' => FALSE,
      'author' => 'reliefweb',
      'allow_duplicates' => TRUE,
      'use_revisions' => FALSE,
    ];

    return $this->request('POST', '/api/v1/types', [
      'json' => $payload,
    ], $timeout);
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
   * @return \Symfony\Component\HttpFoundation\Response
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

      $response = $this->httpClient->request($method, $url, [
        'timeout' => $timeout,
        'headers' => $headers,
      ] + $options);
      return new JsonResponse($response);
    }
    catch (\Exception $exception) {
      if ($exception->getCode() == 404) {
        $this->logger->notice('@endpoint not found: @message', [
          '@endpoint' => $endpoint,
          '@message' => $exception->getMessage(),
        ]);
      }
      else {
        $this->logger->error('Unable to perform the @method request to the @endpoint endpoint with the options: @options: @error', [
          '@method' => $method,
          '@endpoint' => $endpoint,
          '@options' => print_r($options, TRUE),
          '@code' => $exception->getCode(),
          '@error' => $exception->getMessage(),
        ]);
      }
      $response = NULL;
      if ($exception instanceof RequestException) {
        $response = $exception->getResponse();
      }
      return new JsonResponse($response);
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
      throw  new \Exception('The docstore URL is not defined');
    }
    if (!empty($endpoint)) {
      return rtrim($docstore_url, '/') . '/' . ltrim($endpoint, '/');
    }
    return $docstore_url;
  }

  /**
   * Get the docstore document type of the reliefweb document resources.
   *
   * @return string
   *   Document type.
   */
  protected function getDocstoreDocumentType() {
    return $this->config->get('docstore_document_type') ?? 'reliefweb_document';
  }

  /**
   * Get the docstore document type endpoint.
   *
   * @return string
   *   Document type endpoint.
   */
  protected function getDocstoreDocumentTypeEndpoint() {
    return str_replace('_', '-', $this->getDocstoreDocumentType());
  }

  /**
   * Get the document endpoint.
   *
   * @param string $uuid
   *   Optional document UUID.
   *
   * @return string
   *   Document endpoint.
   */
  protected function getDocumentEndpoint($uuid = '') {
    $base = '/api/v1/documents/' . $this->getDocstoreDocumentTypeEndpoint();
    return !empty($uuid) ? $base . '/' . $uuid : $base;
  }

  /**
   * Get the base headers for API requests.
   *
   * @return array
   *   Headers.
   */
  protected function getHeaders() {
    return [
      'API-KEY' => $this->config->get('docstore_api_key'),
      'X-Docstore-Provider-Uuid' => $this->config->get('docstore_provider_uuid'),
    ];
  }

}
