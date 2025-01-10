<?php

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper to sort or format content in proper localized way.
 */
class LocalizationHelper {

  /**
   * Store the instantiated collators per language.
   *
   * @var array
   */
  protected static $collators;

  /**
   * Store the instantiated formatters per language.
   *
   * @var array
   */
  protected static $formatters;

  /**
   * Store the default language.
   *
   * @var string
   */
  protected static $defaultLanguage;

  /**
   * Sort an array using a collator for the given language, perserving the keys.
   *
   * @param array $items
   *   Items to sort.
   * @param string|null $property
   *   Property of the items used to sort them.
   * @param string $language
   *   Language for the collator (ISO2 code).
   *
   * @return bool
   *   TRUE on success or FALSE on failure.
   */
  public static function collatedAsort(array &$items, $property = NULL, $language = NULL) {
    if (empty($items)) {
      return TRUE;
    }

    $collator = static::getCollator($language);

    if ($collator !== FALSE) {
      if (isset($property)) {
        return uasort($items, function ($a, $b) use ($collator, $property) {
          return $collator->compare($a[$property], $b[$property]);
        });
      }
      return collator_asort($collator, $items);
    }
    else {
      if (isset($property)) {
        return uasort($items, function ($a, $b) use ($property) {
          return $a[$property] <=> $b[$property];
        });
      }
      return asort($items);
    }
  }

  /**
   * Sort an array using a collator for the given language.
   *
   * @param array $items
   *   Items to sort.
   * @param string|null $property
   *   Property of the items used to sort them.
   * @param string $language
   *   Language for the collator (ISO2 code).
   *
   * @return bool
   *   TRUE on success or FALSE on failure.
   */
  public static function collatedSort(array &$items, $property = NULL, $language = NULL) {
    if (empty($items)) {
      return TRUE;
    }

    $collator = static::getCollator($language);

    if ($collator !== FALSE) {
      if (isset($property)) {
        return usort($items, function ($a, $b) use ($collator, $property) {
          return $collator->compare($a[$property], $b[$property]);
        });
      }
      return collator_sort($collator, $items);
    }
    else {
      if (isset($property)) {
        return usort($items, function ($a, $b) use ($property) {
          return $a[$property] <=> $b[$property];
        });
      }
      return sort($items);
    }
  }

  /**
   * Sort an array by keys using a collator for the given language.
   *
   * @param array $items
   *   Items to sort.
   * @param string $language
   *   Language for the collator (ISO2 code).
   *
   * @return bool
   *   TRUE on success or FALSE on failure.
   */
  public static function collatedKsort(array &$items, $language = NULL) {
    if (empty($items)) {
      return TRUE;
    }

    $collator = static::getCollator($language);

    if ($collator !== FALSE) {
      // There is no Collator::ksort function, so we extract the keys, re-order
      // them and then repopulate the items with the proper order.
      $keys = array_keys($items);
      if (collator_sort($collator, $keys)) {
        $reordered = [];
        foreach ($keys as $key) {
          $reordered[$key] = $items[$key];
        }
        $items = $reordered;
        return TRUE;
      }

      return FALSE;
    }

    return ksort($items);
  }

  /**
   * Format a number with grouped thousands.
   *
   * @param float|int $number
   *   Number to format.
   * @param string $language
   *   Language (ISO2 code).
   *
   * @return string
   *   Formatted number.
   */
  public static function formatNumber($number, $language = NULL) {
    $formatter = static::getNumberFormatter($language);

    if (!empty($formatter)) {
      $formatted = $formatter->format($number);
      if (intl_is_failure($formatter->getErrorCode())) {
        return $number;
      }
      return $formatted;
    }

    return number_format($number);
  }

  /**
   * Get the collator for the given language.
   *
   * @param string $language
   *   Language for which to return a Collator. Defaults to the current
   *   language.
   *
   * @return \Collator|false
   *   Collator or FALSE if there is no collator for the language.
   */
  public static function getCollator($language = NULL) {
    $language = static::getLanguage($language);

    if (!isset(static::$collators[$language])) {
      $collator = static::createCollator($language);

      if ($collator) {
        switch (static::intlGetErrorCode()) {
          case U_ZERO_ERROR:
            // No errors.
            break;

          case U_USING_DEFAULT_WARNING:
            // For some reason, the French locale for the collation defaults
            // to English and doesn't enable the French Collation. This is not
            // an issue per se, as we can have the correct behavior by enabling
            // the French collation so that accents are handled properly.
            //
            // @see https://www.php.net/manual/en/class.collator.php
            if ($language === 'fr') {
              $collator->setAttribute(\Collator::FRENCH_COLLATION, \Collator::ON);
            }
            break;

          default:
            // Some other error happened, we mark the collator as FALSE so that
            // the collated_(k)sort functions can default to the basic (k)sort.
            $collator = FALSE;
        }
      }

      static::$collators[$language] = $collator;
    }

    return static::$collators[$language];
  }

  /**
   * Get the number formatter for the given language.
   *
   * @param string $language
   *   Language for which to return a NumberFormatter. Defaults to the current
   *   language.
   *
   * @return \NumberFormatter|false
   *   Number formatter or FALSE if there is no formatter for the language.
   */
  protected static function getNumberFormatter($language = NULL) {
    $language = static::getLanguage($language);

    if (!isset(static::$formatters[$language])) {
      static::$formatters[$language] = static::createNumberFormatter($language);
    }

    return static::$formatters[$language];
  }

  /**
   * Create the collator for the given language.
   *
   * @param string $language
   *   Language for which to return a Collator. Defaults to the current
   *   language.
   *
   * @return \Collator|false
   *   Collator or FALSE if the collator couldn't be created.
   */
  protected static function createCollator($language) {
    if (function_exists('collator_create')) {
      return collator_create($language) ?: FALSE;
    }
    return FALSE;
  }

  /**
   * Create the number formatter for the given language.
   *
   * @param string $language
   *   Language for which to return a NumberFormatter. Defaults to the current
   *   language.
   *
   * @return \NumberFormatter|false
   *   Number formatter or FALSE if the formatter couldn't be created.
   */
  protected static function createNumberFormatter($language) {
    if (function_exists('numfmt_create')) {
      return numfmt_create($language, \NumberFormatter::DECIMAL) ?: FALSE;
    }
    return FALSE;
  }

  /**
   * Get the last error code.
   *
   * @return int
   *   Error code.
   */
  protected static function intlGetErrorCode() {
    return intl_get_error_code();
  }

  /**
   * Get the ISO2 language code, defaulting to English if undefined.
   *
   * @param string $language
   *   Language.
   *
   * @return string
   *   ISO2 language code.
   */
  public static function getLanguage($language = NULL) {
    if (empty($language)) {
      if (!isset(static::$defaultLanguage)) {
        static::$defaultLanguage = \Drupal::languageManager()->getCurrentLanguage()->getId();
      }
      $language = static::$defaultLanguage;
    }
    return $language;
  }

}
