<?php

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper to manipulate date and date fields.
 */
class DateHelper {

  /**
   * Get the timestamp from a date value extracted from the form state.
   *
   * The type of data returned from a date field is not consistent so we
   * we ensure we get a timestamp to be able to do some comparison.
   *
   * @param mixed $date
   *   Date field value.
   *
   * @return int|null
   *   A UNIX timestamp or NULL if the type of the date couldn't be inferred.
   */
  public static function getDateTimeStamp($date) {
    if (!empty($date)) {
      // Date object. It can be a PHP DateTime or DrupalDateTime...
      if (is_object($date)) {
        return $date->getTimeStamp();
      }
      // Date in the expected format YYYY-MM-DD.
      elseif (is_string($date) && !is_numeric($date)) {
        $date = date_create($date, timezone_open('UTC'));
        if (!$date) {
          return NULL;
        }

        return $date->getTimeStamp();
      }
      // Assume it's a timestamp.
      elseif (is_numeric($date)) {
        return intval($date);
      }
    }
    return NULL;
  }

}
