<?php

namespace Drupal\reliefweb_utility;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Custom twig functions.
 */
class TwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      new TwigFilter('taglist', [$this, 'getTagList']),
    ];
  }

  /**
   * Get a sorted list of tags.
   *
   * @param array $list
   *   List of tags.
   * @param int $count
   *   Number of items to return, NULL to return all the items.
   * @param string $sort
   *   Porperty to use for sorting.
   *
   * @return array
   *   Sorted and sliced list of tags.
   */
  public static function getTagList(array $list, $count = NULL, $sort = 'name') {
    if (empty($list) || !is_array($list)) {
      return [];
    }
    // Sort the tags if requested.
    if (!empty($sort)) {
      foreach ($list as $key => $item) {
        $sort_value = $item[$sort] ?? $item['name'] ?? $key;
        $list[$key] = [
          // Prefix with a space for the main item (ex: primary country),
          // to ensure it's the first.
          'sort' => (!empty($item['main']) ? ' ' : '') . $sort_value,
          'item' => $item,
        ];
      }
      static::collatedSort($list, 'sort');
      foreach ($list as $key => $item) {
        $list[$key] = $item['item'];
      }
    }
    // Get the number of items before slicing, this is used to mark the real
    // last item as being last. This way we can also simply check if 'last'
    // is set in the resulting tag list to know if there are more items.
    $last = count($list) - 1;
    // Get a subet of the data if requested.
    if (isset($count)) {
      $list = array_slice($list, 0, $count);
    }
    // Prepare the list of tags, marking the last item.
    $tags = [];
    $index = 0;
    foreach ($list as &$item) {
      $key = $index === $last ? 'last' : $index++;
      $tags[$key] = &$item;
    }
    return $tags;
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
   *
   * @todo Move that to some utils?
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
   *
   * @todo Move that to some utils?
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
   * Get the collator for the given language.
   *
   * @param string $language
   *   Language for which to return a Collator. Defaults to the current
   *   language.
   *
   * @return Collator
   *   Collator.
   *
   * @todo Move that to some utils?
   */
  public static function getCollator($language = NULL) {
    static $collators = [];

    $language = static::getLanguage($language);

    if (!isset($collators[$language])) {
      if (function_exists('collator_create')) {
        $collator = collator_create($language);

        switch (intl_get_error_code()) {
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
              $collator->setAttribute(Collator::FRENCH_COLLATION, Collator::ON);
            }
            break;

          default:
            // Some other error happened, we mark the collator as FALSE so that
            // the collated_(k)sort functions can default to the basic (k)sort.
            $collator = FALSE;
        }

        $collators[$language] = $collator;
      }
      else {
        $collators[$language] = FALSE;
      }
    }

    return $collators[$language];
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
    static $default;
    if (empty($language)) {
      if (!isset($default)) {
        $default = \Drupal::languageManager()->getCurrentLanguage()->getId();
      }
      $language = $default;
    }
    return $language;
  }

}
