<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Component\Utility\UrlHelper as DrupalUrlHelper;
use Drupal\Core\Url;

/**
 * Helper extending the Drupal URL helper with additional functionalities.
 */
class UrlHelper extends DrupalUrlHelper {

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
    $parts = static::parse($url);
    return Url::fromUserInput($parts['path'], [
      'query' => $parts['query'],
      'fragment' => $parts['fragment'],
      'alias' => $alias,
    ])->toString();
  }

}
