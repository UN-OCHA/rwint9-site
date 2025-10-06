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

}
