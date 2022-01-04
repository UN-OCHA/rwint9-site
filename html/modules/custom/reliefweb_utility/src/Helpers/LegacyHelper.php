<?php

namespace Drupal\reliefweb_utility\Helpers;

use Symfony\Component\Uid\Uuid;

/**
 * Helper class for the migration of files.
 */
class LegacyHelper {

  /**
   * Generate a file's UUID from it's old URI on reliefweb.int.
   *
   * @param string $uri
   *   File URI (ex: public://directory/filename.ext).
   *
   * @return string
   *   The file UUID.
   */
  public static function generateFileUuid($uri) {
    // Replace the public scheme with the actual reliefweb.int base public file
    // URI so that it's unique.
    $uuid_uri = str_replace('public://', 'https://reliefweb.int/sites/reliefweb.int/files/', $uri);

    // Generate the UUID based on the URI.
    return Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_URL), $uuid_uri)->toRfc4122();
  }

  /**
   * Generate an image's UUID from it's old URI on reliefweb.int.
   *
   * @param string $uri
   *   File URI (ex: public://image-dir/my.jpg).
   *
   * @return string
   *   The image UUID.
   */
  public static function generateImageUuid($uri) {
    return static::generateFileUuid($uri);
  }

  /**
   * Generate an attachment's UUID from it's old URI on reliefweb.int.
   *
   * @param string $uri
   *   File URI (ex: public://resources/my.pdf).
   *
   * @return string
   *   The attachment UUID.
   */
  public static function generateAttachmentUuid($uri) {
    // Strip the '%' characters for compatibility with the preview URLs.
    return static::generateFileUuid(str_replace('%', '', $uri));
  }

  /**
   * Generate a UUID from the attachment UUID and the file ID.
   *
   * @param string $uuid
   *   The attachment UUID.
   * @param string $fid
   *   The file ID.
   */
  public static function generateAttachmentFileUuid($uuid, $fid) {
    return Uuid::v3(Uuid::fromString($uuid), $fid)->toRfc4122();
  }

  /**
   * Generate a UUID from the attachment UUID and the file UUID.
   *
   * @param string $uuid
   *   The attachment UUID.
   * @param string $file_uuid
   *   The file UUID.
   */
  public static function generateAttachmentPreviewUuid($uuid, $file_uuid) {
    return Uuid::v3(Uuid::fromString($uuid), $file_uuid)->toRfc4122();
  }

  /**
   * Generate the UUID of a document resource from a node id.
   *
   * @param int $nid
   *   Node ID.
   *
   * @return string
   *   Document UUID.
   */
  public static function generateDocumentUuid($nid) {
    // Permanent URI for the node. This will be used to create a document
    // resource in the docstore with the same UUID.
    $uuid_uri = 'https://reliefweb.int/node/' . $nid;

    // Generate the UUID based on the URI.
    return Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_URL), $uuid_uri)->toRfc4122();
  }

  /**
   * Get a file legacy URL from it's legacy URI.
   *
   * @param string $uri
   *   File URI.
   * @param string $base_url
   *   The base url of the legacy URL in the form http(s)://example.test.
   *
   * @return string
   *   File URL.
   */
  public static function getFileLegacyUrl($uri, $base_url = 'https://reliefweb.int') {
    $filename = UrlHelper::encodePath(basename($uri));
    return $base_url . '/sites/reliefweb.int/files/resources/' . $filename;
  }

  /**
   * Generate an attachment's UUID from it's old URL on reliefweb.int.
   *
   * @param string $url
   *   File URL.
   *
   * @return string
   *   The attachment UUID.
   */
  public static function generateAttachmentUuidFromLegacyUrl($url) {
    $pattern = '#^https?://([^/]+)/sites/(reliefweb\.int|default)/files/resources/(?<filename>.+)$#';

    // Convert to its URI.
    $uri = preg_replace_callback($pattern, function ($matches) {
      return 'public://resources/' . rawurldecode($matches['filename']);
    }, $url);

    // Generate the UUID based on the URI.
    return static::generateAttachmentUuid($uri);
  }

}
