<?php

declare(strict_types=1);

namespace Drupal\reliefweb_api\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for the ReliefWeb Elasticsearch cluster.
 */
class ReliefWebElasticsearchClient {

  /**
   * Constructor.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Database\Connection $database
   *   The default database connection.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
  ) {
  }

  /**
   * Send a request to the Elasticsearch cluster.
   *
   * Authentication and TLS options from reliefweb_api.settings are always
   * applied. Callers pass Guzzle options such as `json` or extra headers.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path relative to the cluster URL, for example
   *   `reliefweb_file_fingerprints/_search`.
   * @param array $options
   *   Guzzle request options.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   */
  public function request(string $method, string $path, array $options = []): ResponseInterface {
    return $this->httpClient->request($method, $this->buildUrl($path), $this->mergeHttpOptions($options));
  }

  /**
   * Get the Elasticsearch cluster base URL.
   *
   * @return string
   *   Base URL without a trailing slash.
   */
  public function getBaseUrl(): string {
    return rtrim((string) ($this->config()->get('elasticsearch') ?? 'http://elasticsearch:9200'), '/');
  }

  /**
   * Get the Elasticsearch base index name.
   *
   * @return string
   *   Base index name from config, or the default database name when unset.
   */
  public function getBaseIndexName(): string {
    $connection_options = $this->database->getConnectionOptions();
    return (string) ($this->config()->get('base_index_name') ?? ($connection_options['database'] ?? ''));
  }

  /**
   * Get indexer connection options (kebab-case keys).
   *
   * @return array<string, mixed>
   *   Options for RWAPIIndexer\Options / Manager.
   */
  public function getIndexerOptions(): array {
    return [
      'elasticsearch' => $this->getBaseUrl(),
      'elasticsearch-auth-type' => $this->getAuthType(),
      'elasticsearch-username' => (string) ($this->config()->get('elasticsearch_username') ?? ''),
      'elasticsearch-password' => (string) ($this->config()->get('elasticsearch_password') ?? ''),
      'elasticsearch-api-key' => (string) ($this->config()->get('elasticsearch_api_key') ?? ''),
      'elasticsearch-verify-tls' => $this->verifyTls(),
      'elasticsearch-ca-file' => (string) ($this->config()->get('elasticsearch_ca_file') ?? ''),
      'base-index-name' => $this->getBaseIndexName(),
    ];
  }

  /**
   * Build an absolute URL for a cluster path.
   *
   * @param string $path
   *   Path relative to the cluster URL.
   *
   * @return string
   *   Absolute URL.
   */
  protected function buildUrl(string $path): string {
    $path = ltrim($path, '/');
    if ($path === '') {
      return $this->getBaseUrl();
    }
    return $this->getBaseUrl() . '/' . $path;
  }

  /**
   * Merge caller Guzzle options with authentication and TLS settings.
   *
   * @param array $options
   *   Caller Guzzle options.
   *
   * @return array
   *   Options with auth and TLS applied.
   */
  protected function mergeHttpOptions(array $options): array {
    $http_options = $this->getHttpOptions();
    $headers = ($http_options['headers'] ?? []) + ($options['headers'] ?? []);
    unset($http_options['headers']);
    $options = array_replace($options, $http_options);
    if ($headers !== []) {
      $options['headers'] = $headers;
    }
    return $options;
  }

  /**
   * Build Guzzle authentication and TLS options from config.
   *
   * @return array
   *   Guzzle options fragment.
   */
  protected function getHttpOptions(): array {
    $options = [
      'verify' => $this->getVerifyOption(),
    ];

    switch ($this->getAuthType()) {
      case 'none':
      case '':
        break;

      case 'basic':
        $username = (string) ($this->config()->get('elasticsearch_username') ?? '');
        $password = (string) ($this->config()->get('elasticsearch_password') ?? '');
        if ($username === '' || $password === '') {
          throw new \InvalidArgumentException('Missing Elasticsearch basic authentication credentials.');
        }
        $options['auth'] = [$username, $password];
        break;

      case 'apikey':
        $api_key = (string) ($this->config()->get('elasticsearch_api_key') ?? '');
        if ($api_key === '') {
          throw new \InvalidArgumentException('Missing Elasticsearch api key authentication credentials.');
        }
        $options['headers']['Authorization'] = 'ApiKey ' . $api_key;
        break;

      default:
        throw new \InvalidArgumentException('Invalid Elasticsearch authentication type. Allowed: none, basic, apikey.');
    }

    return $options;
  }

  /**
   * Get the Guzzle TLS verify option.
   *
   * @return bool|string
   *   FALSE to skip verification, TRUE to use the default CA store, or a CA
   *   file path.
   */
  protected function getVerifyOption(): bool|string {
    if (!$this->verifyTls()) {
      return FALSE;
    }
    $ca_file = (string) ($this->config()->get('elasticsearch_ca_file') ?? '');
    return $ca_file !== '' ? $ca_file : TRUE;
  }

  /**
   * Whether TLS certificates should be verified.
   *
   * @return bool
   *   TRUE to verify TLS certificates.
   */
  protected function verifyTls(): bool {
    return $this->config()->get('elasticsearch_verify_tls') ?? TRUE;
  }

  /**
   * Get the normalized Elasticsearch authentication type.
   *
   * @return string
   *   Authentication type: none, basic or apikey.
   */
  protected function getAuthType(): string {
    return strtolower((string) ($this->config()->get('elasticsearch_auth_type') ?? 'none'));
  }

  /**
   * Get the ReliefWeb API settings.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Module configuration.
   */
  protected function config(): ImmutableConfig {
    return $this->configFactory->get('reliefweb_api.settings');
  }

}
