<?php

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper to manipulate files.
 */
class FileHelper {

  /**
   * Extract text content from a file.
   *
   * Note: only PDF are currently supported.
   *
   * @param ?string $source_uri
   *   URI of the file from which to extract text.
   * @param string $mimetype
   *   The mime type of the file.
   * @param ?int $page
   *   Specific page to extract text from (if not provided extracts all pages).
   *
   * @return string
   *   The extracted text content or empty string in case of failure.
   */
  public static function extractText(string $source_uri, string $mimetype, ?int $page = NULL): string {
    // Currently we only support this features for PDF files.
    if (!$mimetype === 'application/pdf') {
      return '';
    }

    $source_uri ??= $this->loadFile()?->getFileUri();
    if (empty($source_uri)) {
      return '';
    }

    $file_system = \Drupal::service('file_system');

    // Get the real path of the source file.
    $source_path = $file_system->realpath($source_uri);
    if (empty($source_path)) {
      return '';
    }

    $source = escapeshellarg($source_path);

    // Prepare the page parameter if specified.
    $page_param = '';
    if ($page !== NULL) {
      $page_param = ' ' . escapeshellarg($page);
    }

    $mutool = \Drupal::state()->get('mutool', '/usr/bin/mutool');
    if (is_executable($mutool)) {
      $options = \Drupal::state()->get('mutool_text_options', '');
      $command = "{$mutool} draw -F txt {$options} {$source}{$page_param}";

      exec($command, $output, $return_val);

      if (empty($return_val)) {
        // Join the output array into a single string.
        return implode("\n", $output);
      }
    }

    return '';
  }

}
