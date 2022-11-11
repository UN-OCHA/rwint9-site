<?php

namespace Drupal\reliefweb_files\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drush\Commands\DrushCommands;
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
   * {@inheritdoc}
   */
  public function __construct(
    FileSystemInterface $file_system,
  ) {
    $this->fileSystem = $file_system;
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

}
