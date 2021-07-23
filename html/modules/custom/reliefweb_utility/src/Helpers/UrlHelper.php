<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Component\Utility\UrlHelper as DrupalUrlHelper;
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

    if (preg_match('#^(?:(?:https?://)|/)#', $url) !== 1) {
      $url = '/' . $url;
    }
    if (strpos($url, '/') === 0) {
      $url = 'internal:' . $url;
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
   * @param string $preserve_style
   *   Whether to keep the style in the uri or not.
   *
   * @return string
   *   New URI (starting with the public:// scheme).
   */
  public static function getImageUriFromUrl($uri, $preserve_style = FALSE) {
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
      return str_replace('/sites/default/files/', 'public://', $path);
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
    return $directory . '/' . $uuid . '.' . $info['extension'];
  }

}
