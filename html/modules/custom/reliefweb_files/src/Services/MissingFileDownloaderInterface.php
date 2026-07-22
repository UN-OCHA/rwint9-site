<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Services;

use Drupal\node\NodeInterface;

/**
 * Downloads report attachments referenced in the DB but missing on disk.
 */
interface MissingFileDownloaderInterface {

  /**
   * Whether the source URL host matches the current request host.
   *
   * When hosts match, downloading from the source would hit this same site and
   * cannot recover a missing local file. Empty or unparseable hosts are treated
   * as same-host (skip download safely).
   *
   * @param string $source_url
   *   Base source URL (e.g. https://reliefweb.int).
   *
   * @return bool
   *   TRUE when download should be skipped because hosts match or are unknown.
   */
  public function isSameHostAsSource(string $source_url): bool;

  /**
   * Find report attachment files that are missing on disk.
   *
   * @param int[] $node_ids
   *   Report node IDs to limit the query to. Empty for all reports.
   * @param int $limit
   *   Maximum number of missing files to return. 0 means no limit.
   *
   * @return array
   *   Array of file data with uuid, filename, filemime, uri, extension, and
   *   expected_path.
   */
  public function findMissingFiles(array $node_ids = [], int $limit = 0): array;

  /**
   * Download a single missing file from the source URL.
   *
   * @param array $file_data
   *   File data from findMissingFiles().
   * @param string $source_url
   *   Base source URL (e.g. https://reliefweb.int).
   * @param bool $dry_run
   *   When TRUE, log what would be downloaded without writing files.
   * @param int $timeout
   *   HTTP timeout in seconds.
   *
   * @return string
   *   Result: 'downloaded', 'failed', or 'skipped'.
   */
  public function downloadFile(
    array $file_data,
    string $source_url,
    bool $dry_run = FALSE,
    int $timeout = 60,
  ): string;

  /**
   * Ensure the first report attachment exists on disk for AI title extraction.
   *
   * Skips when the source host matches the current request host, when there is
   * no first attachment, or when the file is already on disk.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Report node.
   * @param string $source_url
   *   Base source URL (e.g. https://reliefweb.int).
   * @param int $timeout
   *   HTTP timeout in seconds.
   *
   * @return string
   *   Result: 'downloaded', 'failed', or 'skipped'.
   */
  public function ensureFirstReportAttachmentOnDisk(
    NodeInterface $node,
    string $source_url,
    int $timeout = 60,
  ): string;

}
