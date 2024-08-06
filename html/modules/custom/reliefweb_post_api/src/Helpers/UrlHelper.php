<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Helpers;

/**
 * Helper to manipulate URLs.
 */
class UrlHelper {

  /**
   * URL mapping.
   *
   * @var ?array
   */
  public static ?array $urlMapping;

  /**
   * Replace the base of a URL.
   *
   * This is mostly for development to be able to perform requests to dev
   * sites behind basic auth or using ther container's hostname.
   *
   * @param string $url
   *   URL to change.
   *
   * @return string
   *   Changed URL.
   */
  public static function replaceBaseUrl(string $url): string {
    if (!isset(static::$urlMapping)) {
      static::$urlMapping = \Drupal::state()->get('reliefweb_post_api_base_url_mapping', []);
    }

    $mapping = static::$urlMapping;

    if (empty($mapping)) {
      return $url;
    }

    return preg_replace_callback('#^(https?://[^/]+)#', function ($matches) use ($mapping) {
      return $mapping[$matches[1]] ?? $matches[0];
    }, $url);
  }

}
