<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Helpers;

/**
 * Provides helper methods to generate a hash from an associative array.
 *
 * This class normalizes the data by recursively converting floats to strings
 * with fixed precision, sorting arrays (associative and list), and excluding
 * specific properties provided as dot-delimited strings.
 */
class HashHelper {

  /**
   * Generates a SHA-256 hash of the normalized data.
   *
   * The normalization process:
   * - Converts floats to a fixed 6-decimal string format.
   * - Recursively sorts associative arrays by keys and list arrays by value.
   * - Excludes keys specified via dot-delimited property names.
   *
   * @param array $data
   *   The associative array of data to hash.
   * @param array $exclusions
   *   (Optional) An array of dot-delimited property paths to exclude.
   *
   * @return string
   *   A hexadecimal hash string.
   */
  public static function generateHash(
    array $data,
    array $exclusions = [
      'provider',
      'user',
    ],
  ): string {
    // Remove the excluded properties.
    self::removeExclusions($data, $exclusions);

    // Normalize the data.
    $normalized_data = self::normalizeData($data);

    // Use JSON encoding to get a consistent string representation.
    // The flag JSON_PRESERVE_ZERO_FRACTION is added as an extra safeguard.
    $json = json_encode($normalized_data, \JSON_PRESERVE_ZERO_FRACTION);

    return hash('sha256', $json);
  }

  /**
   * Recursively normalizes data for consistent hashing.
   *
   * - Floats are converted to strings with exactly 6 decimal places.
   * - Associative arrays are sorted by keys.
   * - List arrays (numeric-indexed arrays) are normalized and then sorted.
   *
   * @param mixed $data
   *   The data to normalize.
   *
   * @return mixed
   *   The normalized data.
   */
  protected static function normalizeData(mixed $data): mixed {
    if (is_float($data)) {
      // Convert float to a string with fixed 6 decimal places.
      return number_format($data, 6, '.', '');
    }

    if (is_array($data)) {
      // Normalize each element.
      $normalized = array_map([self::class, 'normalizeData'], $data);

      // Check if the array is a list (sequential numeric keys).
      if (array_is_list($data)) {
        sort($normalized);
        return $normalized;
      }

      // For associative arrays, sort by keys.
      ksort($normalized);
      return $normalized;
    }

    // For any other type (e.g., integer, string, boolean), return as-is.
    return $data;
  }

  /**
   * Removes properties from the data array based on exclusion paths.
   *
   * Each property to exclude is defined as a dot-delimited string.
   *
   * @param array &$data
   *   The data array to modify.
   * @param array $exclusions
   *   An array of dot-delimited strings representing property paths.
   */
  protected static function removeExclusions(array &$data, array $exclusions): void {
    foreach ($exclusions as $exclusion) {
      $path = explode('.', $exclusion);
      self::removePropertyPath($data, $path);
    }
  }

  /**
   * Recursively removes a property specified by its path from the data.
   *
   * This method works recursively in both associative arrays and lists.
   *
   * @param mixed &$data
   *   The data (or sub-data) from which to remove the property.
   * @param array $path
   *   An array of keys defining the path to remove.
   */
  protected static function removePropertyPath(mixed &$data, array $path): void {
    if (!is_array($data)) {
      return;
    }

    // If the array is a list, iterate over each item.
    if (array_is_list($data)) {
      foreach ($data as &$item) {
        self::removePropertyPath($item, $path);
      }
      return;
    }

    // For associative arrays, work on the first element of the path.
    $key = array_shift($path);
    if (!array_key_exists($key, $data)) {
      return;
    }

    if (empty($path)) {
      // If there are no more keys in the path, remove this key.
      unset($data[$key]);
    }
    else {
      // Recursively remove in the sub-array.
      self::removePropertyPath($data[$key], $path);
    }
  }

}
