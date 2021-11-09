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

  /**
   * Format a date.
   *
   * @param mixed $date
   *   Date field value.
   * @param string $type
   *   The format to use, one of:
   *   - A built-in format: 'short', 'medium', 'long', 'html_datetime',
   *    'html_date', 'html_time', 'html_yearless_date', 'html_week',
   *    'html_month' or 'html_year'.
   *   - The name of a date type defined by a date format config entity.
   *   - The machine name of an administrator-defined date format.
   *   - 'custom', to use $format.
   *   Defaults to 'medium'.
   * @param string $format
   *   If $type is 'custom', a PHP date format string suitable for input to
   *   date(). Use a backslash to escape ordinary text, so it does not get
   *   interpreted as date format characters.
   * @param string|null $timezone
   *   Time zone as described at https://php.net/manual/timezones.php
   *   Defaults to the time zone used to display the page.
   * @param string|null $langcode
   *   Language code to translate to. NULL (default) means to use the user
   *   interface language for the page.
   *
   * @return string
   *   A translated date string in the requested format. Since the format may
   *   contain user input, this value should be escaped when output.
   *   An empty string is returned if the date couldn't be formatted.
   */
  public static function format($date, $type = 'medium', $format = '', $timezone = NULL, $langcode = NULL) {
    $timestamp = static::getDateTimeStamp($date);
    if (empty($timestamp)) {
      return '';
    }
    return \Drupal::service('date.formatter')
      ->format($timestamp, $type, $format, $timezone, $langcode);
  }

}
