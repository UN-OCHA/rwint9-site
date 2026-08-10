<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Symfony\Component\Process\Process;

/**
 * Helper to manipulate files.
 */
class FileHelper {

  /**
   * Cached base commands for text extraction by mimetype.
   *
   * @var array|null
   */
  private static $textExtractionCommands = NULL;

  /**
   * Generate a hash for a file.
   *
   * @param \Drupal\file\Entity\File $file
   *   The Drupal File object to hash.
   * @param string $algorithm
   *   Hash algorithm to use (default: 'sha256').
   * @param ?\Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   *
   * @return string|null
   *   The file hash or NULL if the file doesn't exist or hash generation fails.
   */
  public static function generateFileHash(File $file, string $algorithm = 'sha256', ?FileSystemInterface $file_system = NULL): ?string {
    $source_uri = $file->getFileUri();
    if (empty($source_uri)) {
      return NULL;
    }

    $file_system ??= \Drupal::service('file_system');
    $real_path = $file_system->realpath($source_uri);

    if (empty($real_path) || !file_exists($real_path)) {
      return NULL;
    }

    $hash = hash_file($algorithm, $real_path);
    return $hash ?: NULL;
  }

  /**
   * Extract text content from a file.
   *
   * Supports different text extraction commands based on file mimetypes.
   * Currently supports PDF files via mutool and DOC files via pandoc.
   *
   * @param \Drupal\file\Entity\File $file
   *   The Drupal File object from which to extract text.
   * @param ?int $page
   *   Specific page to extract text from (if not provided extracts all pages).
   *   Note: Page parameter is only supported for PDF files.
   * @param ?\Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   *
   * @return string
   *   The extracted text content or empty string in case of failure.
   */
  public static function extractText(File $file, ?int $page = NULL, ?FileSystemInterface $file_system = NULL): string {
    $source_uri = $file->getFileUri();
    $mimetype = $file->getMimeType();
    if (empty($source_uri) || empty($mimetype)) {
      return '';
    }

    // Get the real path of the source file.
    $file_system ??= \Drupal::service('file_system');
    $source_path = $file_system->realpath($source_uri);
    if (empty($source_path)) {
      return '';
    }

    // Get base commands for different mimetypes.
    $base_commands = static::getTextExtractionCommands();

    // Check if we have a command configured for this mimetype.
    if (!isset($base_commands[$mimetype])) {
      return '';
    }

    $config = $base_commands[$mimetype];

    // Build command for this specific mimetype.
    $command = [$config['command']];

    // Add base arguments if configured.
    if (!empty($config['args'])) {
      $command = array_merge($command, explode(' ', $config['args']));
    }

    // Add options if configured.
    if (!empty($config['options'])) {
      $command = array_merge($command, explode(' ', $config['options']));
    }

    // Add file path.
    $command[] = $source_path;

    // Add page parameter if the command supports it.
    if ($config['page'] && $page !== NULL) {
      $command[] = (string) $page;
    }

    // Execute command.
    $process = new Process($command);
    $process->run();

    return static::getProcessResult($process, $config, $file->id());
  }

  /**
   * Extract text content from multiple files in parallel.
   *
   * Supports different text extraction commands based on file mimetypes.
   * Currently supports PDF files via mutool and DOC files via pandoc.
   *
   * @param \Drupal\file\Entity\File[] $files
   *   Array of Drupal File objects to extract text from.
   * @param int $processes
   *   Number of parallel processes to use for text extraction.
   * @param int $timeout
   *   Timeout in seconds for each process (default: 60).
   * @param ?\Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   *
   * @return array
   *   Array with file IDs as keys and extracted text as values.
   *   Failed extractions or unsupported file types will have empty string
   *   values.
   */
  public static function extractTextParallel(
    array $files,
    int $processes = 4,
    int $timeout = 60,
    ?FileSystemInterface $file_system = NULL,
  ): array {
    if (empty($files)) {
      return [];
    }

    // Validate processes parameter.
    if ($processes < 1) {
      $processes = 1;
    }

    $results = [];
    $processes_array = [];
    $file_system ??= \Drupal::service('file_system');

    // Get base commands for different mimetypes.
    $base_commands = static::getTextExtractionCommands();
    if (empty($base_commands)) {
      return [];
    }

    // Prepare processes for each file.
    foreach ($files as $file) {
      if (!$file instanceof File) {
        continue;
      }

      $file_id = $file->id();
      $mimetype = $file->getMimeType();

      // Check if we have a command configured for this mimetype.
      if (!isset($base_commands[$mimetype])) {
        $results[$file_id] = '';
        continue;
      }

      $source_uri = $file->getFileUri();
      if (empty($source_uri)) {
        $results[$file_id] = '';
        continue;
      }

      $source_path = $file_system->realpath($source_uri);
      if (empty($source_path) || !file_exists($source_path)) {
        $results[$file_id] = '';
        continue;
      }

      // Build command for this specific mimetype.
      $config = $base_commands[$mimetype];
      $command = [$config['command']];

      // Add base arguments if configured.
      if (!empty($config['args'])) {
        $command = array_merge($command, explode(' ', $config['args']));
      }

      // Add options if configured.
      if (!empty($config['options'])) {
        $command = array_merge($command, explode(' ', $config['options']));
      }

      // Add file path as the last argument.
      $command[] = $source_path;

      // Create a new process to run the text extraction command.
      $process = new Process($command);
      $process->setTimeout($timeout);
      $processes_array[$file_id] = [
        'process' => $process,
        'config' => $config,
      ];
    }

    // Execute processes in parallel with the specified number of concurrent
    // processes.
    $running_processes = [];
    $completed_count = 0;
    $total_processes = count($processes_array);

    // Start initial batch of processes (up to $processes limit).
    foreach ($processes_array as $file_id => $process_data) {
      if (count($running_processes) < $processes) {
        $process_data['process']->start();
        $running_processes[$file_id] = $process_data;
      }
    }

    // Keep processing until all files are completed
    // This maintains a constant pool of running processes.
    while ($completed_count < $total_processes) {
      foreach ($running_processes as $file_id => $process_data) {
        $process = $process_data['process'];
        $config = $process_data['config'];

        if (!$process->isRunning()) {
          // Process completed - collect result.
          $results[$file_id] = static::getProcessResult($process, $config, $file_id);

          // Remove completed process from running pool.
          unset($running_processes[$file_id]);
          $completed_count++;

          // Immediately start next waiting process to maintain concurrency.
          foreach ($processes_array as $next_file_id => $next_process_data) {
            if (!isset($results[$next_file_id]) && !isset($running_processes[$next_file_id])) {
              $next_process_data['process']->start();
              $running_processes[$next_file_id] = $next_process_data;
              // Only start one process per completion.
              break;
            }
          }
        }
      }

      // Small delay (10ms) to prevent busy waiting (CPU optimization).
      usleep(10000);
    }

    return $results;
  }

  /**
   * Get base commands configuration for text extraction by mimetype.
   *
   * @return array
   *   Array of mimetype => command configuration mappings.
   */
  private static function getTextExtractionCommands(): array {
    // Return cached commands if available.
    if (static::$textExtractionCommands !== NULL) {
      return static::$textExtractionCommands;
    }

    $commands = \Drupal::config('reliefweb_utility.settings')
      ->get('text_extraction.commands') ?: [];

    // Validate that required commands are available.
    $mapped_commands = [];
    foreach ($commands as $command) {
      if (empty($command['command']) || empty($command['mimetype'])) {
        continue;
      }
      if (!is_executable($command['command'])) {
        \Drupal::logger('reliefweb_utility')->warning('Text extraction command is not executable for @mimetype at @path', [
          '@mimetype' => $command['mimetype'],
          '@path' => $command['command'],
        ]);
      }
      else {
        $mapped_commands[$command['mimetype']] = $command;
      }
    }

    // Cache the validated commands.
    static::$textExtractionCommands = $mapped_commands;

    return $mapped_commands;
  }

  /**
   * Get text extraction result from a process.
   *
   * @param \Symfony\Component\Process\Process $process
   *   The process that was executed.
   * @param array $config
   *   The command configuration array.
   * @param int|null $file_id
   *   Optional file ID for logging purposes.
   *
   * @return string
   *   The extracted text content or empty string in case of failure.
   */
  private static function getProcessResult(Process $process, array $config, ?int $file_id = NULL): string {
    // If the process is successful, return the output.
    if ($process->isSuccessful()) {
      return $process->getOutput();
    }
    // If errors are ignored and there is output, return the output.
    elseif (!empty($config['ignore_errors_if_output']) && !empty($process->getOutput())) {
      return $process->getOutput();
    }
    // Otherwise, log the error and return an empty string.
    else {
      \Drupal::logger('reliefweb_utility')->warning('Text extraction failed for file @file_id: @error', [
        '@file_id' => $file_id ?? 'unknown',
        '@error' => $process->getErrorOutput(),
      ]);
      return '';
    }
  }

  /**
   * Clear the cached text extraction commands.
   *
   * This is useful for testing or when configuration changes during runtime.
   */
  public static function clearTextExtractionCommandsCache(): void {
    static::$textExtractionCommands = NULL;
  }

  /**
   * Extract structured text spans from PDF page(s) via mutool stext XML.
   *
   * Each span is a reading-order line with bounding box and font size for
   * layout-aware title matching. Returns an empty array on failure or for
   * non-PDF files. When $end_page is set, extracts an inclusive page range;
   * spans are grouped per page (coordinates reset per page, not remapped).
   *
   * @param \Drupal\file\Entity\File $file
   *   The Drupal File object to extract from.
   * @param int $page
   *   1-based start page number (default: 1).
   * @param ?int $end_page
   *   Inclusive 1-based end page, or NULL for a single page.
   * @param ?\Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   *
   * @return list<list<array{text: string, x: float, y: float, w: float, h: float, size: float}>>
   *   Per-page span lists suitable for ocha_ai_helper /text/match/series-title,
   *   or an empty list on failure.
   */
  public static function extractStructuredTextSpans(
    File $file,
    int $page = 1,
    ?int $end_page = NULL,
    ?FileSystemInterface $file_system = NULL,
  ): array {
    if (
      $page < 1
      || ($end_page !== NULL && $end_page < $page)
      || $file->getMimeType() !== 'application/pdf'
    ) {
      return [];
    }

    $source_uri = $file->getFileUri();
    if (empty($source_uri)) {
      return [];
    }

    $file_system ??= \Drupal::service('file_system');
    $source_path = $file_system->realpath($source_uri);
    if (empty($source_path) || !file_exists($source_path)) {
      return [];
    }

    $mutool = static::getMutoolCommandPath();
    if ($mutool === NULL) {
      return [];
    }

    $pages = $end_page === NULL || $end_page === $page
      ? (string) $page
      : $page . '-' . $end_page;

    $process = new Process([
      $mutool,
      'draw',
      '-F',
      'stext',
      $source_path,
      $pages,
    ]);
    $process->run();

    $xml = '';
    if ($process->isSuccessful()) {
      $xml = $process->getOutput();
    }
    elseif (!empty($process->getOutput())) {
      // mutool may write useful stext even when exit status is non-zero.
      $xml = $process->getOutput();
    }
    else {
      \Drupal::logger('reliefweb_utility')->warning('Structured text extraction failed for file @file_id: @error', [
        '@file_id' => $file->id() ?? 'unknown',
        '@error' => $process->getErrorOutput(),
      ]);
      return [];
    }

    return static::parseStextXmlPages($xml);
  }

  /**
   * Parse MuPDF stext XML into per-page normalized text spans.
   *
   * Prefers line-level elements (text + bbox + nested font size). Falls back to
   * concatenating char elements when a line has no text attribute. Empty page
   * slots are kept so order matches PDF page order. If there are no `<page>`
   * nodes but lines exist, they are wrapped as a single page.
   *
   * @param string $xml
   *   Raw mutool stext XML output.
   *
   * @return list<list<array{text: string, x: float, y: float, w: float, h: float, size: float}>>
   *   Per-page span lists, or an empty list when no usable spans exist.
   */
  public static function parseStextXmlPages(string $xml): array {
    $xml = trim($xml);
    if ($xml === '') {
      return [];
    }

    $previous = libxml_use_internal_errors(TRUE);
    try {
      $document = new \SimpleXMLElement($xml);
    }
    catch (\Exception) {
      return [];
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }

    $page_nodes = $document->xpath('//page') ?: [];
    if ($page_nodes === []) {
      $spans = static::spansFromStextLines($document->xpath('//line') ?: []);
      return $spans === [] ? [] : [$spans];
    }

    $pages = [];
    $has_spans = FALSE;
    foreach ($page_nodes as $page_node) {
      $spans = static::spansFromStextLines($page_node->xpath('.//line') ?: []);
      if ($spans !== []) {
        $has_spans = TRUE;
      }
      $pages[] = $spans;
    }

    return $has_spans ? $pages : [];
  }

  /**
   * Parse MuPDF stext XML into a flat list of normalized text spans.
   *
   * @param string $xml
   *   Raw mutool stext XML output.
   *
   * @return list<array{text: string, x: float, y: float, w: float, h: float, size: float}>
   *   Flattened span list across all pages.
   */
  public static function parseStextXmlSpans(string $xml): array {
    $pages = static::parseStextXmlPages($xml);
    if ($pages === []) {
      return [];
    }
    return array_merge(...$pages);
  }

  /**
   * Builds span arrays from MuPDF stext line elements.
   *
   * @param array<\SimpleXMLElement> $lines
   *   Line elements from stext XML.
   *
   * @return list<array{text: string, x: float, y: float, w: float, h: float, size: float}>
   *   Normalized spans for the given lines.
   */
  private static function spansFromStextLines(array $lines): array {
    $spans = [];
    foreach ($lines as $line) {
      $span = static::spanFromStextLine($line);
      if ($span !== NULL) {
        $spans[] = $span;
      }
    }
    return $spans;
  }

  /**
   * Builds one span array from a MuPDF stext line element.
   *
   * @param \SimpleXMLElement $line
   *   A line element from stext XML.
   *
   * @return array{text: string, x: float, y: float, w: float, h: float, size: float}|null
   *   Normalized span, or NULL when the line has no usable text.
   */
  private static function spanFromStextLine(\SimpleXMLElement $line): ?array {
    $attributes = $line->attributes();
    $text = trim((string) ($attributes['text'] ?? ''));
    if ($text === '') {
      $chars = [];
      foreach ($line->xpath('.//char') ?: [] as $char) {
        $chars[] = (string) ($char->attributes()['c'] ?? '');
      }
      $text = trim(implode('', $chars));
    }
    if ($text === '') {
      return NULL;
    }

    $bbox = preg_split('/\s+/', trim((string) ($attributes['bbox'] ?? ''))) ?: [];
    if (count($bbox) < 4) {
      return NULL;
    }

    $x0 = (float) $bbox[0];
    $y0 = (float) $bbox[1];
    $x1 = (float) $bbox[2];
    $y1 = (float) $bbox[3];

    $size = 0.0;
    foreach ($line->xpath('.//font') ?: [] as $font) {
      $font_size = (float) ($font->attributes()['size'] ?? 0);
      if ($font_size > $size) {
        $size = $font_size;
      }
    }
    if ($size <= 0.0) {
      $size = max(0.0, $y1 - $y0);
    }

    return [
      'text' => $text,
      'x' => $x0,
      'y' => $y0,
      'w' => max(0.0, $x1 - $x0),
      'h' => max(0.0, $y1 - $y0),
      'size' => $size,
    ];
  }

  /**
   * Resolves the mutool binary path from text extraction config.
   *
   * @return string|null
   *   Executable path, or NULL when unavailable.
   */
  private static function getMutoolCommandPath(): ?string {
    $commands = static::getTextExtractionCommands();
    $pdf = $commands['application/pdf'] ?? NULL;
    if ($pdf === NULL || empty($pdf['command'])) {
      return NULL;
    }
    $path = (string) $pdf['command'];
    return is_executable($path) ? $path : NULL;
  }

}
