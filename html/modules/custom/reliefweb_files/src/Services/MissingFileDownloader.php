<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Services;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Downloads report attachments referenced in the DB but missing on disk.
 */
class MissingFileDownloader implements MissingFileDownloaderInterface {

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a MissingFileDownloader.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   The HTTP client factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected Connection $database,
    protected ClientFactory $httpClientFactory,
    protected RequestStack $requestStack,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('reliefweb_files');
  }

  /**
   * {@inheritdoc}
   */
  public function isSameHostAsSource(string $source_url): bool {
    $source_host = parse_url(rtrim($source_url, '/'), \PHP_URL_HOST);
    if (!is_string($source_host) || $source_host === '') {
      return TRUE;
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return TRUE;
    }

    $request_host = $request->getHost();
    if ($request_host === '') {
      return TRUE;
    }

    return strcasecmp($source_host, $request_host) === 0;
  }

  /**
   * {@inheritdoc}
   */
  public function findMissingFiles(array $node_ids = [], int $limit = 0): array {
    $query = $this->database->select('node__field_file', 'nff')
      ->fields('fm', ['uuid', 'filename', 'filemime', 'uri'])
      ->condition('nff.bundle', 'report', '=')
      ->distinct();

    if (!empty($node_ids)) {
      $query->condition('nff.entity_id', $node_ids, 'IN');
    }

    $query->join('file_managed', 'fm', 'nff.field_file_file_uuid = fm.uuid');
    $query->orderBy('fm.fid', 'DESC');

    $results = $query->execute()->fetchAll();
    $missing_files = [];

    foreach ($results as $result) {
      $uuid = $result->uuid;
      $filename = $result->filename;
      $filemime = $result->filemime;
      $uri = $result->uri;

      if (empty($uuid) || empty($filename) || empty($uri)) {
        continue;
      }

      $expected_path = $this->getExpectedPathFromUri($uri);
      if ($expected_path === '' || file_exists($expected_path)) {
        continue;
      }

      $extension = ReliefWebFile::extractFileExtension($filename);
      $missing_files[] = [
        'uuid' => $uuid,
        'filename' => $filename,
        'filemime' => $filemime,
        'uri' => $uri,
        'extension' => $extension,
        'expected_path' => $expected_path,
      ];

      if ($limit > 0 && count($missing_files) >= $limit) {
        break;
      }
    }

    return $missing_files;
  }

  /**
   * {@inheritdoc}
   */
  public function downloadFile(
    array $file_data,
    string $source_url,
    bool $dry_run = FALSE,
    int $timeout = 60,
  ): string {
    $source_url = rtrim($source_url, '/');
    $filename = $file_data['filename'];
    $uri = $file_data['uri'];
    $expected_path = $file_data['expected_path'];
    $source_file_url = $this->convertUriToUrl($uri, $source_url);

    if ($dry_run) {
      $this->logger->notice('Would download: @filename from @url to @path', [
        '@filename' => $filename,
        '@url' => $source_file_url,
        '@path' => $expected_path,
      ]);
      return 'skipped';
    }

    $http_client = $this->httpClientFactory->fromOptions([
      'timeout' => $timeout,
      'headers' => [
        'User-Agent' => 'ReliefWeb-Files-Downloader/1.0',
      ],
    ]);

    try {
      $directory = dirname($expected_path);
      if ($directory === '' || $directory === '.') {
        $this->logger->error('Invalid directory path for file: @filename', [
          '@filename' => $filename,
        ]);
        return 'failed';
      }

      if (!$this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY)) {
        $this->logger->error('Failed to create directory: @directory', [
          '@directory' => $directory,
        ]);
        return 'failed';
      }

      $response = $http_client->get($source_file_url, [
        'sink' => $expected_path,
      ]);

      if ($response->getStatusCode() === 200) {
        if (file_exists($expected_path) && filesize($expected_path) > 0) {
          $this->logger->info('Downloaded: @filename (@bytes bytes)', [
            '@filename' => $filename,
            '@bytes' => filesize($expected_path),
          ]);
          return 'downloaded';
        }
        $this->logger->error('Downloaded file is empty or does not exist: @filename', [
          '@filename' => $filename,
        ]);
        return 'failed';
      }

      if ($response->getStatusCode() === 404) {
        if (file_exists($expected_path)) {
          @unlink($expected_path);
        }
        $this->logger->notice('File not found on server (404): @filename - skipping', [
          '@filename' => $filename,
        ]);
        return 'skipped';
      }

      $this->logger->error('Failed to download @filename: HTTP @status', [
        '@filename' => $filename,
        '@status' => $response->getStatusCode(),
      ]);
      return 'failed';
    }
    catch (RequestException $exception) {
      if ($exception->hasResponse() && $exception->getResponse()->getStatusCode() === 404) {
        if (file_exists($expected_path)) {
          @unlink($expected_path);
        }
        $this->logger->notice('File not found on server (404): @filename - skipping', [
          '@filename' => $filename,
        ]);
        return 'skipped';
      }
      $this->logger->error('Failed to download @filename: @message', [
        '@filename' => $filename,
        '@message' => $exception->getMessage(),
      ]);
      return 'failed';
    }
    catch (\Exception $exception) {
      $this->logger->error('Unexpected error downloading @filename: @message', [
        '@filename' => $filename,
        '@message' => $exception->getMessage(),
      ]);
      return 'failed';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function ensureFirstReportAttachmentOnDisk(
    NodeInterface $node,
    string $source_url,
    int $timeout = 60,
  ): string {
    $source_url = rtrim($source_url, '/');

    if ($this->isSameHostAsSource($source_url)) {
      $this->logger->notice('Skipping missing attachment download: source host matches current host (@host).', [
        '@host' => parse_url($source_url, PHP_URL_HOST) ?: 'unknown',
      ]);
      return 'skipped';
    }

    if (!$node->hasField('field_file') || $node->get('field_file')->isEmpty()) {
      return 'skipped';
    }

    $item = $node->get('field_file')->first();
    if (!$item instanceof ReliefWebFile) {
      return 'skipped';
    }

    $file = $item->loadFile();
    if ($file === NULL) {
      return 'skipped';
    }

    $uri = $file->getFileUri();
    $filename = $file->getFilename();
    $uuid = $file->uuid();
    if ($uri === '' || $filename === '' || $uuid === '') {
      return 'skipped';
    }

    $expected_path = $this->getExpectedPathFromUri($uri);
    if ($expected_path !== '' && file_exists($expected_path)) {
      return 'skipped';
    }

    $file_data = [
      'uuid' => $uuid,
      'filename' => $filename,
      'filemime' => $file->getMimeType(),
      'uri' => $uri,
      'extension' => ReliefWebFile::extractFileExtension($filename),
      'expected_path' => $expected_path,
    ];

    return $this->downloadFile($file_data, $source_url, FALSE, $timeout);
  }

  /**
   * Get the expected file path from a URI.
   *
   * @param string $uri
   *   File URI (e.g. public://attachments/f8/93/….pdf).
   *
   * @return string
   *   Absolute path on disk, or empty string if public:// is invalid.
   */
  protected function getExpectedPathFromUri(string $uri): string {
    $file_path = preg_replace('#^[^:]+://#', '', $uri);
    $base_path = $this->fileSystem->realpath('public://');
    if ($base_path === FALSE || $base_path === '') {
      return '';
    }
    return $base_path . '/' . $file_path;
  }

  /**
   * Convert a file URI to a source URL.
   *
   * @param string $uri
   *   File URI (e.g. public://attachments/f8/93/….pdf).
   * @param string $source_url
   *   Base source URL (e.g. https://reliefweb.int).
   *
   * @return string
   *   Full source URL for the file.
   */
  protected function convertUriToUrl(string $uri, string $source_url): string {
    $file_path = preg_replace('#^[^:]+://#', '', $uri);
    return rtrim($source_url, '/') . '/sites/default/files/' . $file_path;
  }

}
