<?php

declare (strict_types=1);

namespace Drupal\reliefweb_files\Services;

use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;

/**
 * Interface for ReliefWeb file duplication detection service.
 */
interface ReliefWebFileDuplicationInterface {

  /**
   * Find documents with similar content to the input text.
   *
   * @param string $input_text
   *   The input text to find similar documents for.
   * @param string $bundle
   *   The content type bundle (e.g., 'report', 'job', etc.).
   * @param array $exclude_document_ids
   *   Array of document IDs to exclude from the search results.
   * @param int $max_documents
   *   Maximum number of documents to return.
   * @param string $minimum_should_match
   *   Minimum should match percentage for Elasticsearch query.
   * @param int $max_files
   *   Maximum number of files to search for similarity.
   * @param bool $only_published
   *   Whether to only include published documents.
   *
   * @return array
   *   Array of matching documents with similarity scores.
   *   Each document contains:
   *   - id: Document ID
   *   - title: Document title
   *   - url: Document URL
   *   - similarity: Raw similarity score (0.0 to 1.0)
   *   - similarity_percentage: Formatted percentage string
   *   - score: Elasticsearch relevance score
   */
  public function findSimilarDocuments(
    string $input_text,
    string $bundle = 'report',
    array $exclude_document_ids = [],
    ?int $max_documents = NULL,
    ?string $minimum_should_match = NULL,
    ?int $max_files = NULL,
    ?bool $only_published = NULL,
  ): array;

  /**
   * Find similar file fingerprints.
   *
   * @param string $input_text
   *   The input text to find similar files for.
   * @param int $max_files
   *   Maximum number of files to return.
   * @param string $minimum_should_match
   *   Minimum should match percentage for Elasticsearch query.
   * @param array $exclude_file_ids
   *   Array of file IDs to exclude from the search results.
   *
   * @return array
   *   Array of matching file fingerprints with similarity scores.
   */
  public function findSimilarFileFingerprints(
    string $input_text,
    int $max_files = 50,
    string $minimum_should_match = '80%',
    array $exclude_file_ids = [],
  ): array;

  /**
   * Find files with exact hash match.
   *
   * @param string $file_hash
   *   The file hash to search for.
   * @param array $exclude_file_ids
   *   Array of file IDs to exclude from the search results.
   * @param int $max_files
   *   Maximum number of files to return.
   *
   * @return array
   *   Array of matching file fingerprints with exact hash matches.
   */
  public function findFilesByHash(
    string $file_hash,
    array $exclude_file_ids = [],
    int $max_files = 10,
  ): array;

  /**
   * Create the file fingerprints index.
   *
   * @param int|null $shards
   *   Number of shards, defaults to config value.
   * @param int|null $replicas
   *   Number of replicas, defaults to config value.
   *
   * @return bool
   *   TRUE if the index was created successfully, FALSE otherwise.
   */
  public function createFileFingerprintsIndex(
    ?int $shards = NULL,
    ?int $replicas = NULL,
  ): bool;

  /**
   * Delete the file fingerprints index.
   *
   * @return bool
   *   TRUE if the index was deleted successfully, FALSE otherwise.
   */
  public function deleteFileFingerprintsIndex(): bool;

  /**
   * Index a file fingerprint from a file entity.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file entity to index.
   *
   * @return bool
   *   TRUE if the fingerprint was indexed successfully, FALSE otherwise.
   */
  public function indexFileFingerprint(File $file): bool;

  /**
   * Bulk index file fingerprints from an array of file entities.
   *
   * @param \Drupal\file\Entity\File[] $files
   *   Array of file entities to index.
   * @param int $processes
   *   Number of parallel processes to use for text extraction.
   *
   * @return array
   *   Array with 'success' count and 'failed' count of indexed files.
   */
  public function bulkIndexFileFingerprints(array $files, int $processes = 4): array;

  /**
   * Delete a file fingerprint.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file entity.
   *
   * @return bool
   *   TRUE if the fingerprint was deleted successfully, FALSE otherwise.
   */
  public function deleteFileFingerprint(File $file): bool;

  /**
   * Get the total number of files to index with optional ID range conditions.
   *
   * @param int $start_id
   *   Optional start file ID (inclusive).
   * @param int $end_id
   *   Optional end file ID (inclusive).
   * @param int $limit
   *   Optional limit on the count.
   *
   * @return int
   *   The total number of files to process.
   */
  public function getFileCountForIndexing(int $start_id = 0, int $end_id = 0, int $limit = 0): int;

  /**
   * Optimized file indexing with chunked processing and file ID tracking.
   *
   * @param int $chunk_size
   *   Number of files to process in each chunk.
   * @param int $start_id
   *   Optional start file ID (inclusive).
   * @param int $end_id
   *   Optional end file ID (inclusive).
   * @param int $limit
   *   Maximum number of files to process (0 for no limit).
   * @param int $processes
   *   Number of parallel processes to use for text extraction.
   * @param callable|null $progress_callback
   *   Optional callback function for progress updates.
   *
   * @return array
   *   Array with 'success' and 'failed' counts.
   */
  public function indexFiles(
    int $chunk_size,
    int $start_id = 0,
    int $end_id = 0,
    int $limit = 0,
    int $processes = 4,
    ?callable $progress_callback = NULL,
  ): array;

  /**
   * Index a single file fingerprint by ID.
   *
   * @param int $file_id
   *   The file ID to index.
   * @param bool $remove
   *   Whether to remove the fingerprint instead of indexing.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function indexSingleFileById(
    int $file_id,
    bool $remove,
  ): bool;

  /**
   * Set the logger for this service.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function setLogger(LoggerInterface $logger): void;

}
