<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Component\Utility\UrlHelper as DrupalUrlHelper;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\Url;
use Symfony\Component\Uid\Uuid;

/**
 * Helper extending the Drupal URL helper with additional functionalities.
 */
class UrlHelper extends DrupalUrlHelper {

  /**
   * Mapping of the legacy image directories to the new ones.
   *
   * @var array
   */
  public static $legacyImageDirectoryMapping = [
    'announcements' => 'images/announcements',
    'attached-images' => 'images/blog-posts',
    'blog-post-images' => 'images/blog-posts',
    'headline-images' => 'images/reports',
    'report-images' => 'images/reports',
  ];

  /**
   * Mapping of the legacy images styles to the new ones.
   *
   * @var array
   */
  public static $legacyStyleMapping = [
    'announcement-homepage' => 'announcement',
    'attachment-large' => 'large',
    'attachment-small' => 'small',
    'report-large' => 'large',
    'report-medium' => 'medium',
    'report-small' => 'small',
    'm' => 'thumbnail',
  ];

  /**
   * Encode a URL.
   *
   * This ensures that urls like  '/updates?search=blabli' are properly
   * encoded by decomposing the url and rebuilding it.
   *
   * @param string $url
   *   Url to encode.
   * @param bool $alias
   *   Whether the passed url is already an alias or not. If it's an alias then
   *   this will prevent another lookup.
   *
   * @return string
   *   Encoded url.
   *
   * @todo handle language.
   * @todo review if still need this function and where to add it.
   */
  public static function encodeUrl($url, $alias = TRUE) {
    if (empty($url)) {
      return '';
    }

    if (preg_match('#^(?:(?:[^?]*://)|/)#', $url) !== 1) {
      $url = '/' . $url;
    }
    if (strpos($url, '/') === 0) {
      $url = 'internal:' . $url;
    }
    elseif (strpos($url, '://') === 0) {
      $url = 'http' . $url;
    }

    $parts = static::parse($url);
    return Url::fromUri($parts['path'], [
      'query' => $parts['query'],
      'fragment' => $parts['fragment'],
      'alias' => $alias,
    ])->toString();
  }

  /**
   * Get a path from its alias.
   *
   * @param string $alias
   *   Path alias.
   *
   * @return string
   *   Path.
   */
  public static function getPathFromAlias($alias) {
    return \Drupal::service('path_alias.manager')->getPathByAlias($alias);
  }

  /**
   * Get an alias from its path.
   *
   * @param string $path
   *   Path.
   *
   * @return string
   *   Path alias.
   */
  public static function getAliasFromPath($path) {
    return \Drupal::service('path_alias.manager')->getAliasByPath($path);
  }

  /**
   * Convert a legacy Image URI to a URI using a UUID derived from it.
   *
   * @param string $uri
   *   Legacy URI.
   * @param bool $preserve_style
   *   Whether to keep the style in the uri or not.
   *
   * @return string
   *   New URI (starting with the public:// scheme).
   */
  public static function getImageUriFromUrl($uri, $preserve_style = FALSE) {
    if (empty($uri)) {
      return '';
    }

    // Extract the path.
    $path = parse_url($uri, PHP_URL_PATH);

    // Decode the path.
    $path = urldecode($path);

    // No further processing if the uri is already using the new pattern.
    if (preg_match('#/[a-z0-9]{2}/[a-z0-9]{2}/[a-z0-9-]{36}\.#', $uri)) {
      // Remove any style information if requested so.
      if (!$preserve_style) {
        $path = preg_replace('#/styles/[^/]+/public/#', '/', $path);
      }

      // Return the URI with the public scheme.
      return preg_replace('#/sites/[^/]+/files/#', 'public://', $path);
    }

    // Extract the style if any.
    $style = '';
    if (preg_match('#/styles/(?<style>[^/]+)/public/#', $path, $match) === 1) {
      $style = static::$legacyStyleMapping[$match['style']] ?? $match['style'];
    }

    // Remove any style information to get the original path.
    $path = preg_replace('#/styles/[^/]+/public/#', '/', $path);

    // Make the URL consistent.
    $path = str_replace('/sites/default/files/', '/sites/reliefweb.int/files/', $path);

    // Add the ReliefWeb base URL.
    $uri = 'https://reliefweb.int' . $path;

    // Generate the UUID based on the URI.
    $uuid = Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_URL), $uri)->toRfc4122();

    // Now that we have the UUID derived from the legacy URL, we can convert to
    // a URI with the public scheme.
    $uri = str_replace('/sites/reliefweb.int/files/', 'public://', $path);

    // Note: the locale is assumed to be UTF-8.
    $info = pathinfo($uri);

    // Replace the image directory. We do that after generating the UUID to
    // avoid collisions between blog post images as they will all end up in the
    // same `/images/blog-posts/` directory.
    $dirname = strtr($info['dirname'], static::$legacyImageDirectoryMapping);

    // Restore the style.
    if ($preserve_style && !empty($style)) {
      $dirname = str_replace('public://', 'public://styles/' . $style . '/public/', $dirname);
    }

    // Use the existing directory + the first 4 letters of the uuid.
    $directory = implode('/', [
      $dirname,
      substr($uuid, 0, 2),
      substr($uuid, 2, 2),
    ]);

    // We use the UUID as filename, preserving only the extension so that
    // the URI is short and predictable.
    if (isset($info['extension']) && !empty($info['extension'])) {
      return $directory . '/' . $uuid . '.' . $info['extension'];
    }
    else {
      return $directory . '/' . $uuid;
    }
  }

  /**
   * Get an absolute file URI.
   *
   * @param string $uri
   *   File URI.
   *
   * @return string
   *   Absolute URI for the file or empty if an error occured.
   */
  public static function getAbsoluteFileUri($uri) {
    if (empty($uri)) {
      return '';
    }
    try {
      return \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
    }
    catch (InvalidStreamWrapperException $exception) {
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function isValid($url, $absolute = FALSE) {
    if (empty($url)) {
      return FALSE;
    }
    return parent::isValid($url, $absolute);
  }

  /**
   * Retrieve a URL without its parameters and fragment.
   *
   * @param string $url
   *   The URL.
   *
   * @return string
   *   The URL without its parameters and fragment.
   */
  public static function stripParametersAndFragment(string $url): string {
    if (empty($url)) {
      return '';
    }

    $parts = parse_url($url);

    $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
    $user = $parts['user'] ?? '';
    $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
    $pass = ($user || $pass) ? "$pass@" : '';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '';

    return "$scheme$user$pass$host$port$path";
  }

  /**
   * Extracts the filename from a Content-Disposition header.
   *
   * Handles both filename* (RFC 5987) and filename (quoted or unquoted).
   * Accepts both single and double quotes for robustness.
   *
   * @param string $header
   *   The Content-Disposition header value.
   *
   * @return string
   *   The extracted filename or an empty string if not found.
   */
  public static function getFilenameFromContentDisposition(string $header): string {
    if (empty($header)) {
      return '';
    }

    // Prefer filename* (RFC 5987) if present, allowing single or double quotes.
    if (preg_match(
      '/filename\*\s*=\s*([\'"])?([a-zA-Z0-9\-_]+)\\\?\'(?:[a-zA-Z0-9\-_]*)\\\?\'([^;\'"]+)\1?/i',
      $header,
      $matches
    )) {
      $charset = $matches[2];
      $encoded_filename = $matches[3];
      // Remove any surrounding whitespace and quotes.
      $encoded_filename = trim($encoded_filename, "\"' ");
      // Decode percent-encoding.
      $decoded_filename = rawurldecode($encoded_filename);
      // Convert to UTF-8 if necessary.
      if (strtolower($charset) !== 'utf-8') {
        $converted_filename = @mb_convert_encoding($decoded_filename, 'UTF-8', $charset);
        if ($converted_filename !== FALSE) {
          $decoded_filename = $converted_filename;
        }
      }
      // Return only the base filename to prevent directory traversal.
      return basename($decoded_filename);
    }

    // Fallback to quoted filename (double or single quotes).
    if (preg_match('/filename\s*=\s*([\'"])(.*?)\1/i', $header, $matches)) {
      $quoted_filename = $matches[2];
      // Decode percent-encoding for robustness, as browsers do.
      $quoted_filename = rawurldecode($quoted_filename);
      // Return only the base filename to prevent directory traversal.
      return basename($quoted_filename);
    }

    // Fallback to unquoted filename.
    if (preg_match('/filename\s*=\s*([^;\s]+)/i', $header, $matches)) {
      $unquoted_filename = $matches[1];
      // Remove any surrounding quotes just in case.
      $unquoted_filename = trim($unquoted_filename, "\"' ");
      // Decode percent-encoding for robustness.
      $unquoted_filename = rawurldecode($unquoted_filename);
      // Return only the base filename to prevent directory traversal.
      return basename($unquoted_filename);
    }

    return '';
  }

}
