<?php

namespace Drupal\reliefweb_files\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Uid\Uuid;

/**
 * ReliefWeb file Drush commandfile.
 */
class ReliefWebFilesCommands extends DrushCommands {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file duplication service.
   *
   * @var \Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface
   */
  protected $fileDuplication;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $httpClientFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    FileSystemInterface $file_system,
    ReliefWebFileDuplicationInterface $file_duplication,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    ClientFactory $http_client_factory,
  ) {
    $this->fileSystem = $file_system;
    $this->fileDuplication = $file_duplication;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->httpClientFactory = $http_client_factory;
  }

  /**
   * Generate a symlink of a legacy URL.
   *
   * This generates a symlink to handle a file whose filename differs from
   * its URI basename because the nginx redirection logic cannot work in that
   * case as the UUID from the URL will not match the actual UUID of the file.
   * So we generate a symlink using the relevant part from the URL to the
   * given preview or attachment with the given UUID.
   *
   * Ex: public://file1.pdf with filename file1_compressed.pdf.
   *
   * @param string $url
   *   Legacy attachment or preview URL.
   * @param string $uuid
   *   UUID of the file in the new system.
   *
   * @command rw-files:generate-redirection-symlink
   *
   * @usage rw-files:generate-redirection-symlink URL UUID
   *   Generate the symlink for the URL to the file with the given UUID.
   *
   * @validate-module-enabled reliefweb_files
   */
  public function generateRedirectionSymlink($url, $uuid) {
    if (!Uuid::isValid($uuid)) {
      $this->logger()->error(dt('Invalid UUID: @uuid', [
        '@uuid' => $uuid,
      ]));
      return FALSE;
    }

    $base_directory = rtrim($this->fileSystem->realpath('public://'), '/');

    // Preview.
    if (preg_match('#^https?://[^/]+/sites/[^/]+/files/resource-previews/(\d+)-[^/]+$#', $url, $match) === 1) {
      $extension = 'png';
      $directory = $base_directory . '/legacy-previews';
      $target = '../previews/' . substr($uuid, 0, 2) . '/' . substr($uuid, 2, 2) . '/' . $uuid . '.' . $extension;
      $link = $directory . '/' . $match[1] . '.' . $extension;
    }
    // Attachment.
    elseif (preg_match('#^https?://[^/]+/sites/[^/]+/files/resources/([^/]+)$#', $url, $match) === 1) {
      $extension = ReliefWebFile::extractFileExtension($match[1]);
      $directory = $base_directory . '/legacy-attachments';
      $target = '../attachments/' . substr($uuid, 0, 2) . '/' . substr($uuid, 2, 2) . '/' . $uuid . '.' . $extension;
      $link = $directory . '/' . $match[1];
    }
    else {
      $this->logger()->error(dt('Invalid attachment or preview URL: @url', [
        '@url' => $url,
      ]));
      return FALSE;
    }

    // Create the legacy directory.
    if (!$this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY)) {
      $this->logger()->error(dt('Unable to create @directory', [
        '@directory' => $directory,
      ]));
      return FALSE;
    }

    // Remove any previous link.
    if (is_link($link)) {
      @unlink($link);
    }

    // Create the symlink.
    if (!@symlink($target, $link)) {
      $this->logger()->error(dt('Unable to create symlink: @link => @target', [
        '@link' => $link,
        '@target' => $target,
      ]));
      return FALSE;
    }

    $this->logger()->success(dt('Successfully created symlink: @link => @target', [
      '@link' => $link,
      '@target' => $target,
    ]));
    return TRUE;
  }

  /**
   * Remove a symlink of a legacy URL.
   *
   * @param string $url
   *   Legacy attachment or preview URL.
   *
   * @command rw-files:remove-redirection-symlink
   *
   * @usage rw-files:remove-redirection-symlink
   *   Remove a legacy symlink.
   *
   * @validate-module-enabled reliefweb_files
   */
  public function removeRedirectionSymlink($url) {
    $base_directory = rtrim($this->fileSystem->realpath('public://'), '/');

    // Preview.
    if (preg_match('#^https?://[^/]+/sites/[^/]+/files/resource-previews/(\d+)-[^/]+$#', $url, $match) === 1) {
      $extension = '.png';
      $directory = $base_directory . '/legacy-previews';
      $link = $directory . '/' . $match[1] . $extension;
    }
    // Attachment.
    elseif (preg_match('#^https?://[^/]+/sites/[^/]+/files/resources/([^/]+)$#', $url, $match) === 1) {
      $extension = ReliefWebFile::extractFileExtension($match[1]);
      $directory = $base_directory . '/legacy-attachments';
      $link = $directory . '/' . $match[1];
    }
    else {
      $this->logger()->error(dt('Invalid attachment or preview URL: @url', [
        '@url' => $url,
      ]));
      return FALSE;
    }

    // Remove any previous link.
    if (is_link($link)) {
      @unlink($link);

      $this->logger()->success(dt('Successfully removed symlink @link', [
        '@link' => $link,
      ]));
    }
    else {
      $this->logger()->notice(dt('Symlink @link didn\'t exist', [
        '@link' => $link,
      ]));
    }
    return TRUE;
  }

  /**
   * Index existing file fingerprints.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command rw-files:index-file-fingerprints
   *
   * @option limit Maximum number of files to index, defaults to 0 (all).
   * @option start Starting file ID for processing (highest ID, most recent files first).
   * @option end Ending file ID for processing (lowest ID, optional, processes from start down to end).
   * @option chunk-size Number of files to index at one time, defaults to 500.
   * @option id ID of a specific file to index, defaults to 0 (none).
   * @option remove Removes a file fingerprint if id is provided.
   * @option count-only Get the number of indexable files for the options,
   *   defaults to FALSE.
   * @option memory_limit PHP memory limit, defaults to 512M.
   * @option replicas Number of Elasticsearch replicas for the index,
   *   defaults to NULL which means use the reliefweb_api.settings.replicas
   *   default config value.
   * @option shards Number of Elasticsearch shards for the index,
   *   defaults to NULL which means use the reliefweb_api.settings.shards
   *   default config value.
   * @option processes Number of parallel processes to use for text extraction,
   *   defaults to 4.
   *
   * @usage rw-files:index-file-fingerprints --limit=100
   * @usage rw-files:index-file-fingerprints --id=123
   * @usage rw-files:index-file-fingerprints --count-only
   * @usage rw-files:index-file-fingerprints --remove --id=123
   * @usage rw-files:index-file-fingerprints --start=2000 --end=1000
   * @usage rw-files:index-file-fingerprints --start=2000
   * @usage rw-files:index-file-fingerprints --processes=8
   */
  public function indexFileFingerprints(
    array $options = [
      'limit' => 0,
      'start' => 0,
      'end' => 0,
      'chunk-size' => 500,
      'id' => 0,
      'remove' => FALSE,
      'count-only' => FALSE,
      'memory_limit' => '512M',
      'replicas' => NULL,
      'shards' => NULL,
      'processes' => 4,
    ],
  ): void {
    $logger = $this->logger();
    $file_duplication = $this->fileDuplication;

    $limit = $options['limit'] ?? 0;
    $start = $options['start'] ?? 0;
    $end = $options['end'] ?? 0;
    $chunk_size = $options['chunk-size'] ?? 500;
    $file_id = $options['id'] ?? 0;
    $remove = $options['remove'] ?? FALSE;
    $count_only = $options['count-only'] ?? FALSE;
    $shards = $options['shards'] ?? NULL;
    $replicas = $options['replicas'] ?? NULL;
    $processes = $options['processes'] ?? 4;

    // Set memory limit if provided.
    if (!empty($options['memory_limit'])) {
      ini_set('memory_limit', $options['memory_limit']);
    }

    // Use the optimized indexing method for all cases.
    if ($count_only) {
      $total_files = $file_duplication->getFileCountForIndexing($start, $end, $limit);
      $logger->info("Found $total_files files to index" .
        ($start > 0 ? " (starting from ID $start)" : "") .
        ($end > 0 ? " (ending at ID $end)" : "") .
        ($limit > 0 ? " (limited to $limit)" : ""));
      return;
    }

    // Remove the file fingerprints index if requested.
    if ($remove && empty($file_id)) {
      $file_duplication->deleteFileFingerprintsIndex();
      return;
    }

    // Ensure the file fingerprints index exists.
    if (!$file_duplication->createFileFingerprintsIndex($shards, $replicas)) {
      $logger->error('Failed to create file fingerprints index. Aborting indexing process.');
      return;
    }

    // Handle single file operations.
    if ($file_id > 0) {
      $file_duplication->indexSingleFileById($file_id, $remove);
      return;
    }

    // Validate range parameters if provided.
    if ($start > 0 && $end > 0 && $end > $start) {
      $logger->error('End ID must be less than or equal to start ID (files are processed in descending order).');
      return;
    }

    // Use the optimized indexing method.
    $results = $file_duplication->indexFiles(
      $chunk_size,
      $start,
      $end,
      $limit,
      $processes,
      function ($processed, $total, $indexed, $errors) use ($logger) {
        if ($indexed % 100 === 0) {
          $logger->info("Total indexed so far: $indexed files...");
        }
      }
    );

    $logger->success("Indexing completed. Indexed: {$results['success']}, Errors: {$results['failed']}");
  }

  /**
   * Find documents with files similar to the node file text.
   *
   * @param int $node_id
   *   The node ID to use.
   * @param int|null $max_documents
   *   Number of similar documents to retrieve. NULL to use config default.
   * @param string|null $minimum_should_match
   *   Minimum similarity score (number of matching minhash tokens). NULL to
   *   use config default.
   * @param int|null $max_files
   *   Maximum number of files to search for similarity. NULL to use config
   *   default.
   * @param bool|null $skip_access_check
   *   Whether to skip report access checks. NULL to use config default.
   *
   * @command rw-files:find-similar-files
   *
   * @usage rw-files:find-similar-files 12345
   * @usage rw-files:find-similar-files 12345 --max-documents=20 --max-files=50 --skip-access-check=true
   */
  public function findSimilarFiles(
    int $node_id,
    ?int $max_documents = NULL,
    ?string $minimum_should_match = NULL,
    ?int $max_files = NULL,
    ?bool $skip_access_check = NULL,
  ): void {
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);

    if (!$node) {
      $this->logger()->error("Node not found: $node_id");
      return;
    }

    // Check field_file exists and is not empty.
    if ($node->field_file->isEmpty()) {
      $this->logger()->warning("Node $node_id: field_file is empty.");
      return;
    }

    $bundle = $node->bundle();
    $all_similar_documents = [];
    $file_count = 0;

    // Process all files attached to the node.
    foreach ($node->field_file as $field_item) {
      $file_count++;
      $file = $field_item->loadFile();

      if (!$file) {
        $this->logger()->warning("Node $node_id: Could not load file for field item $file_count.");
        continue;
      }

      // Extract text from the file.
      $text = $field_item->extractText();
      if (empty($text)) {
        $this->logger()->warning("Node $node_id: File $file_count ({$file->getFilename()}) has no extractable text.");
        continue;
      }

      // Use the file duplication service to find similar documents.
      $similar_documents = $this->fileDuplication->findSimilarDocuments(
        $text,
        $bundle,
        // Exclude current document.
        [$node_id],
        $max_documents,
        $minimum_should_match,
        $max_files,
        $skip_access_check,
      );

      // Add file information to each similar document.
      foreach ($similar_documents as $document) {
        $document['source_file'] = $file->getFilename();
        $document['source_file_id'] = $file->id();
        $all_similar_documents[] = $document;
      }
    }

    if (empty($all_similar_documents)) {
      $this->logger()->notice("No similar documents found for any of the $file_count files.");
      return;
    }

    // Sort by similarity score (highest first) and remove duplicates.
    usort($all_similar_documents, function ($a, $b) {
      return $b['similarity'] <=> $a['similarity'];
    });

    // Remove duplicates based on document ID, keeping the highest similarity.
    $unique_documents = [];
    foreach ($all_similar_documents as $document) {
      $doc_id = $document['id'];
      if (!isset($unique_documents[$doc_id]) || $document['similarity'] > $unique_documents[$doc_id]['similarity']) {
        $unique_documents[$doc_id] = $document;
      }
    }

    // Limit to max_documents.
    $unique_documents = array_slice($unique_documents, 0, $max_documents);

    // Build the table rows for output.
    $rows = [];
    foreach ($unique_documents as $document) {
      $rows[] = [
        $document['id'],
        $document['title'],
        $document['url'],
        $document['similarity_percentage'],
        $document['source_file'],
      ];
    }

    $table = new Table($this->output());
    $table->setHeaders(['ID', 'Title', 'URL', 'Similarity (%)', 'Source File'])->setRows($rows);
    $table->render();

    $this->logger()->info("Found " . count($unique_documents) . " similar documents from $file_count files.");
  }

  /**
   * Download missing files from production.
   *
   * This command queries the node__field_file table to find files that are
   * referenced in the database but missing from the local file system.
   * It then downloads these files from the specified source URL (defaults to
   * https://reliefweb.int) and places them in the correct directory structure
   * based on the ReliefWeb file UUID organization.
   *
   * The command uses the URI from the file_managed table to construct the
   * source URL. For example, a URI like
   * "public://attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf"
   * becomes "https://reliefweb.int/sites/default/files/attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf".
   *
   * The command is designed for local mode (local: true setting) and does not
   * use the docstore. Files are organized in the public://attachments directory
   * with a structure of: attachments/XX/YY/UUID.extension where XX and YY are
   * the first 4 characters of the UUID.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command rw-files:download-missing-files
   *
   * @option source-url The source URL to download files from, defaults to https://reliefweb.int
   * @option limit Maximum number of files to process, defaults to 0 (all)
   * @option dry-run Show what would be downloaded without actually downloading
   * @option chunk-size Number of files to process at one time, defaults to 50
   * @option timeout HTTP timeout in seconds, defaults to 30
   *
   * @usage rw-files:download-missing-files
   * @usage rw-files:download-missing-files --source-url=https://staging.reliefweb.int
   * @usage rw-files:download-missing-files --limit=100 --dry-run
   * @usage rw-files:download-missing-files --chunk-size=20 --timeout=60
   *
   * @validate-module-enabled reliefweb_files
   */
  public function downloadMissingFiles(
    array $options = [
      'source-url' => 'https://reliefweb.int',
      'limit' => 0,
      'dry-run' => FALSE,
      'chunk-size' => 50,
      'timeout' => 60,
    ],
  ): void {
    $logger = $this->logger();
    $source_url = rtrim($options['source-url'], '/');
    $limit = $options['limit'] ?? 0;
    $dry_run = $options['dry-run'] ?? FALSE;
    $chunk_size = $options['chunk-size'] ?? 50;
    $timeout = $options['timeout'] ?? 30;

    $logger->info("Starting download of missing files from: $source_url");
    if ($dry_run) {
      $logger->notice("DRY RUN MODE - No files will be downloaded");
    }

    // Get missing files from database.
    $missing_files = $this->getMissingFiles($limit);
    $total_files = count($missing_files);

    if ($total_files === 0) {
      $logger->success("No missing files found!");
      return;
    }

    $logger->info("Found $total_files missing files to download");

    // Create HTTP client.
    $http_client = $this->httpClientFactory->fromOptions([
      'timeout' => $timeout,
      'headers' => [
        'User-Agent' => 'ReliefWeb-Files-Downloader/1.0',
      ],
    ]);

    $downloaded = 0;
    $failed = 0;
    $skipped = 0;

    // Process files in chunks.
    $chunks = array_chunk($missing_files, $chunk_size);
    foreach ($chunks as $chunk_index => $chunk) {
      $logger->info("Processing chunk " . ($chunk_index + 1) . "/" . count($chunks) . " (" . count($chunk) . " files)");

      foreach ($chunk as $file_data) {
        $result = $this->downloadFile($http_client, $source_url, $file_data, $dry_run);
        switch ($result) {
          case 'downloaded':
            $downloaded++;
            break;

          case 'failed':
            $failed++;
            break;

          case 'skipped':
            $skipped++;
            break;
        }
      }

      // Progress update.
      $processed = $downloaded + $failed + $skipped;
      $logger->info("Progress: $processed/$total_files files processed (downloaded: $downloaded, failed: $failed, skipped: $skipped)");
    }

    // Final summary.
    $logger->success("Download completed! Downloaded: $downloaded, Failed: $failed, Skipped: $skipped");
  }

  /**
   * Get list of files that are missing on disk.
   *
   * @param int $limit
   *   Maximum number of files to return.
   *
   * @return array
   *   Array of file data with uuid, filename, filemime, uri, and expected path.
   */
  protected function getMissingFiles(int $limit = 0): array {
    $query = $this->database->select('node__field_file', 'nff')
      ->fields('fm', ['uuid', 'filename', 'filemime', 'uri'])
      ->condition('nff.bundle', 'report', '=')
      ->distinct();

    // Join with file_managed table to get file data from UUIDs.
    $query->join('file_managed', 'fm', 'nff.field_file_file_uuid = fm.uuid');

    // Don't apply limit here - we need to check all files first to find missing
    // ones.
    $results = $query->execute()->fetchAll();
    $missing_files = [];

    foreach ($results as $result) {
      $uuid = $result->uuid;
      $filename = $result->filename;
      $filemime = $result->filemime;
      $uri = $result->uri;

      // Skip if we don't have essential data.
      if (empty($uuid) || empty($filename) || empty($uri)) {
        continue;
      }

      // Get expected file path based on the URI from the database.
      $expected_path = $this->getExpectedPathFromUri($uri);

      // Check if file exists on disk.
      if (!file_exists($expected_path)) {
        $extension = ReliefWebFile::extractFileExtension($filename);
        $missing_files[] = [
          'uuid' => $uuid,
          'filename' => $filename,
          'filemime' => $filemime,
          'uri' => $uri,
          'extension' => $extension,
          'expected_path' => $expected_path,
        ];

        // Apply limit after finding missing files.
        if ($limit > 0 && count($missing_files) >= $limit) {
          break;
        }
      }
    }

    return $missing_files;
  }

  /**
   * Get the expected file path from a URI.
   *
   * @param string $uri
   *   File URI.
   *   Ex: public://attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf.
   *
   * @return string
   *   Expected file path on disk.
   */
  protected function getExpectedPathFromUri(string $uri): string {
    // Extract the file path from the URI and construct the full path. The URI
    // public://attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf
    // would become /srv/www/html/sites/default/files/attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf.
    $file_path = preg_replace('#^[^:]+://#', '', $uri);
    $base_path = $this->fileSystem->realpath('public://');
    return $base_path . '/' . $file_path;
  }

  /**
   * Get the expected file path for a file based on its UUID and extension.
   *
   * @param string $uuid
   *   File UUID.
   * @param string $extension
   *   File extension.
   *
   * @return string
   *   Expected file path.
   */
  protected function getExpectedFilePath(string $uuid, string $extension): string {
    $file_directory = ReliefWebFile::getFileDirectory();
    $base_path = $this->fileSystem->realpath('public://');
    $directory = $base_path . '/' . $file_directory . '/' . substr($uuid, 0, 2) . '/' . substr($uuid, 2, 2);
    return $directory . '/' . $uuid . '.' . $extension;
  }

  /**
   * Convert a file URI to a source URL.
   *
   * @param string $uri
   *   File URI.
   *   Ex: public://attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf.
   * @param string $source_url
   *   Base source URL (e.g., https://reliefweb.int).
   *
   * @return string
   *   Full source URL for the file.
   */
  protected function convertUriToUrl(string $uri, string $source_url): string {
    // Extract the file path from the URI. The URI
    // public://attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf
    // would become attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf.
    $file_path = preg_replace('#^[^:]+://#', '', $uri);

    // Construct the full URL.
    // https://reliefweb.int/sites/default/files/attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf
    return $source_url . '/sites/default/files/' . $file_path;
  }

  /**
   * Download a single file from the source URL.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP client.
   * @param string $source_url
   *   Source URL base.
   * @param array $file_data
   *   File data array.
   * @param bool $dry_run
   *   Whether this is a dry run.
   *
   * @return string
   *   Result: 'downloaded', 'failed', or 'skipped'.
   */
  protected function downloadFile(
    ClientInterface $http_client,
    string $source_url,
    array $file_data,
    bool $dry_run = FALSE,
  ): string {
    $logger = $this->logger();
    $filename = $file_data['filename'];
    $uri = $file_data['uri'];
    $expected_path = $file_data['expected_path'];

    // Convert the URI to the source URL. The URI
    // public://attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf
    // would become https://reliefweb.int/sites/default/files/attachments/f8/93/f893a4c6-dbb7-4890-a9ab-a0722d71ef95.pdf.
    $source_file_url = $this->convertUriToUrl($uri, $source_url);

    if ($dry_run) {
      $logger->notice("Would download: $filename from $source_file_url to $expected_path");
      return 'skipped';
    }

    try {
      // Create directory if it doesn't exist.
      $directory = dirname($expected_path);
      if (empty($directory)) {
        $logger->error("Invalid directory path for file: $filename");
        return 'failed';
      }

      if (!$this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY)) {
        $logger->error("Failed to create directory: $directory");
        return 'failed';
      }

      // Download the file.
      $response = $http_client->get($source_file_url, [
        'sink' => $expected_path,
      ]);

      if ($response->getStatusCode() === 200) {
        // Verify the file was actually downloaded and has content.
        if (file_exists($expected_path) && filesize($expected_path) > 0) {
          $logger->info("Downloaded: $filename (" . filesize($expected_path) . " bytes)");
          return 'downloaded';
        }
        else {
          $logger->error("Downloaded file is empty or doesn't exist: $filename");
          return 'failed';
        }
      }
      elseif ($response->getStatusCode() === 404) {
        // Remove any empty file that might have been created by the sink
        // option.
        if (file_exists($expected_path)) {
          @unlink($expected_path);
        }
        $logger->notice("File not found on server (404): $filename - skipping");
        return 'skipped';
      }
      else {
        $logger->error("Failed to download $filename: HTTP " . $response->getStatusCode());
        return 'failed';
      }
    }
    catch (RequestException $exception) {
      // Check if it's a 404 error.
      if ($exception->hasResponse() && $exception->getResponse()->getStatusCode() === 404) {
        // Remove any empty file that might have been created by the sink
        // option.
        if (file_exists($expected_path)) {
          @unlink($expected_path);
        }
        $logger->notice("File not found on server (404): $filename - skipping");
        return 'skipped';
      }
      $logger->error("Failed to download $filename: " . $exception->getMessage());
      return 'failed';
    }
    catch (\Exception $exception) {
      $logger->error("Unexpected error downloading $filename: " . $exception->getMessage());
      return 'failed';
    }
  }

}
