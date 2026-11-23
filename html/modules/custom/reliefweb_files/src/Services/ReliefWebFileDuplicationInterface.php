<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Services;

use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;

/**
 * Interface for ReliefWeb file duplication detection service.
 */
interface ReliefWebFileDuplicationInterface {

  /**
   * MinHash analyzer bucket_count (unique tokens per fingerprint).
   */
  public const MINHASH_BUCKET_COUNT = 512;

  /**
   * Find documents with similar content to the input text.
   *
   * @param string $input_text
   *   The input text to find similar documents for.
   * @param string $bundle
   *   The content type bundle (e.g., 'report', 'job', etc.).
   * @param array $exclude_document_ids
   *   Array of document IDs to exclude from the search results.
   * @param int|null $max_documents
   *   Maximum number of documents to return.
   * @param string|null $minimum_should_match
   *   Minimum should match percentage for Elasticsearch query.
   * @param int|null $max_files
   *   Maximum number of files to search for similarity.
   * @param bool|null $skip_access_check
   *   Whether to skip access checks.
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
    ?bool $skip_access_check = FALSE,
  ): array;

  /**
   * Find documents similar to a file via its fingerprint.
   *
   * Uses an existing Elasticsearch fingerprint when present (MLT by _id).
   * When missing, extracts capped text, indexes the fingerprint, then searches.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file entity.
   * @param string $bundle
   *   The content type bundle (e.g., 'report').
   * @param array $exclude_document_ids
   *   Document IDs to exclude from results.
   * @param int|null $max_documents
   *   Maximum number of documents to return.
   * @param string|null $minimum_should_match
   *   Minimum should match percentage.
   * @param int|null $max_files
   *   Maximum number of similar files to search.
   * @param bool|null $skip_access_check
   *   Whether to skip access checks.
   *
   * @return array
   *   Matching documents (same shape as findSimilarDocuments()).
   */
  public function findSimilarDocumentsForFile(
    File $file,
    string $bundle = 'report',
    array $exclude_document_ids = [],
    ?int $max_documents = NULL,
    ?string $minimum_should_match = NULL,
    ?int $max_files = NULL,
    ?bool $skip_access_check = NULL,
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
   * Find similar file fingerprints using an indexed document id.
   *
   * @param int|string $file_id
   *   Fingerprint document id (file id).
   * @param int $max_files
   *   Maximum number of files to return.
   * @param string $minimum_should_match
   *   Minimum should match percentage.
   * @param array $exclude_file_ids
   *   File IDs to exclude (the source file id is always excluded).
   *
   * @return array
   *   Matching file fingerprints with similarity scores.
   */
  public function findSimilarFileFingerprintsById(
    int|string $file_id,
    int $max_files = 50,
    string $minimum_should_match = '80%',
    array $exclude_file_ids = [],
  ): array;

  /**
   * Whether a file fingerprint document exists in Elasticsearch.
   *
   * @param int|string $file_id
   *   File id / fingerprint document id.
   *
   * @return bool
   *   TRUE if the document exists.
   */
  public function hasFileFingerprint(int|string $file_id): bool;

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
   * Get the total number of files to index with optional filters.
   *
   * @param int $start_id
   *   Optional start file ID (inclusive).
   * @param int $end_id
   *   Optional end file ID (inclusive).
   * @param int $limit
   *   Optional limit on the count.
   * @param int $min_pages
   *   Only include files with page_count greater than this (0 = off).
   *
   * @return int
   *   The total number of files to process.
   */
  public function getFileCountForIndexing(
    int $start_id = 0,
    int $end_id = 0,
    int $limit = 0,
    int $min_pages = 0,
  ): int;

  /**
   * Count fingerprint index coverage for candidate files.
   *
   * @param int $start_id
   *   Optional start file ID (inclusive).
   * @param int $end_id
   *   Optional end file ID (inclusive).
   * @param int $limit
   *   Optional limit on candidates.
   * @param int $min_pages
   *   Only include files with page_count greater than this (0 = off).
   * @param int $chunk_size
   *   Chunk size for Elasticsearch _mget checks.
   *
   * @return array{total: int, indexed: int, missing: int}
   *   Coverage counts.
   */
  public function countFingerprintIndexStatus(
    int $start_id = 0,
    int $end_id = 0,
    int $limit = 0,
    int $min_pages = 0,
    int $chunk_size = 500,
  ): array;

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
   * @param int $min_pages
   *   Only index files with page_count greater than this (0 = off).
   * @param bool $skip_existing
   *   Skip files that already have a fingerprint document.
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
    int $min_pages = 0,
    bool $skip_existing = FALSE,
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
