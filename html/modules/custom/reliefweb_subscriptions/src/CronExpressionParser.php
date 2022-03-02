<?php

namespace Drupal\reliefweb_subscriptions;

/**
 * @file
 * Handle cron expression parsing.
 */

/**
 * Cron expression parser.
 *
 * @todo handle `?` and L and X#Y non standard syntax for months/days/weekdays?
 */
class CronExpressionParser {

  /**
   * Parse a cron expression.
   *
   * @param string $expression
   *   Cron expression.
   *
   * @return array
   *   Allowed values for each component of the cron expression.
   */
  public static function parse($expression) {
    static $types = [
      'minute',
      'hour',
      'day',
      'month',
      'weekday',
    ];

    $components = explode(' ', $expression);
    if (count($components) !== 5) {
      throw new \Exception();
    }

    $values = [];
    foreach ($types as $index => $type) {
      $values[$type] = self::parseComponent($type, $components[$index]);
    }
    return $values;
  }

  /**
   * Get the next time the cron was supposed to run.
   *
   * @param string $expression
   *   Cron expression.
   * @param string|int $time
   *   Time from which to calculate the next run time. Defaults to now.
   * @param string $timezone
   *   Timezone for the timestamp to date conversion. Defaults to UTC.
   *
   * @return \DateTime
   *   Date object representng the next run date.
   */
  public static function getNextRunDate($expression, $time = 'now', $timezone = 'UTC') {
    static $parts = [
      'month' => 'n',
      'weekday' => 'w',
      'day' => 'j',
      'hour' => 'G',
      'minute' => 'i',
    ];

    $values = self::parse($expression);

    $date = date_create(is_int($time) ? '@' . $time : $time, timezone_open($timezone));

    $iterator = new \ArrayIterator($parts);

    // @todo add 1 minute to ensure we skip the current date?
    $max_iteration = 1000;
    foreach ($iterator as $type => $format) {
      if (--$max_iteration === 0) {
        throw new Exception();
      }

      $value = intval($date->format($format));
      $component_values = self::adjustValues($date, $type, $values[$type]);
      $next = self::getNext($component_values, $value);
      $diff = $next - $value;

      if ($diff === 0) {
        continue;
      }

      switch ($type) {
        case 'month':
          if ($diff > 0) {
            $date->modify('+' . $diff . ' months');
          }
          else {
            $date->modify('+1 year')->modify('-' . abs($diff) . ' months');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            reset($values['day'])
          )->setTime(
            reset($values['hour']),
            reset($values['minute'])
          );
          $iterator->rewind();
          break;

        case 'weekday':
          if ($diff > 0) {
            $date->modify('+' . $diff . ' days');
          }
          else {
            $date->modify('+1 week')->modify('-' . abs($diff) . ' days');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            reset($values['hour']),
            reset($values['minute'])
          );
          $iterator->rewind();
          break;

        case 'day':
          if ($diff > 0) {
            $date->modify('+' . $diff . ' days');
          }
          else {
            $date->modify('+1 month')->modify('-' . abs($diff) . ' days');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            reset($values['hour']),
            reset($values['minute'])
          );
          $iterator->rewind();
          break;

        case 'hour':
          if ($diff > 0) {
            $date->modify('+' . $diff . ' hours');
          }
          else {
            $date->modify('+1 day')->modify('-' . abs($diff) . ' hours');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            intval($date->format('G')),
            reset($values['minute'])
          );
          $iterator->rewind();
          break;

        case 'minute':
          if ($diff > 0) {
            $date->modify('+' . $diff . ' minutes');
          }
          else {
            $date->modify('+1 hour')->modify('-' . abs($diff) . ' minutes');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            intval($date->format('G')),
            intval($date->format('i'))
          );
          $iterator->rewind();
          break;
      }
    }

    return $date;
  }

  /**
   * Get the previous time the cron was supposed to run.
   *
   * @param string $expression
   *   Cron expression.
   * @param string|int $time
   *   Time from which to calculate the next run time. Defaults to now.
   * @param string $timezone
   *   Timezone for the timestamp to date conversion. Defaults to UTC.
   *
   * @return \DateTime
   *   Date object representng the previous run date.
   */
  public static function getPreviousRunDate($expression, $time = 'now', $timezone = 'UTC') {
    static $parts = [
      'minute' => 'i',
      'hour' => 'G',
      'day' => 'j',
      'weekday' => 'w',
      'month' => 'n',
    ];

    $values = self::parse($expression);
    // Reverse all the values to work with getPrevious.
    foreach ($values as $type => $list) {
      $values[$type] = array_reverse($list);
    }

    $date = date_create(is_int($time) ? '@' . $time : $time, timezone_open($timezone));

    $iterator = new \ArrayIterator($parts);

    // @todo remove 1 minute to ensure we skip the current date?
    $max_iteration = 1000;
    foreach ($iterator as $type => $format) {
      if (--$max_iteration === 0) {
        throw new Exception();
      }

      $value = intval($date->format($format));
      $component_values = self::adjustValues($date, $type, $values[$type]);
      $previous = self::getPrevious($component_values, $value);
      $diff = $value - $previous;

      if ($diff === 0) {
        continue;
      }

      switch ($type) {
        case 'minute':
          if ($diff > 0) {
            $date->modify('-' . $diff . ' minutes');
          }
          else {
            $date->modify('-1 hour')->modify('+' . abs($diff) . ' minutes');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            intval($date->format('G')),
            intval($date->format('i'))
          );
          $iterator->rewind();
          break;

        case 'hour':
          if ($diff > 0) {
            $date->modify('-' . $diff . ' hours');
          }
          else {
            $date->modify('-1 day')->modify('+' . abs($diff) . ' hours');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            intval($date->format('G')),
            self::adjustValues($date, 'minute', $values['minute'], TRUE)
          );
          $iterator->rewind();
          break;

        case 'day':
          if ($diff > 0) {
            $date->modify('-' . $diff . ' days');
          }
          else {
            $date->modify('-1 month')->modify('+' . abs($diff) . ' days');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            self::adjustValues($date, 'hour', $values['hour'], TRUE),
            self::adjustValues($date, 'minute', $values['minute'], TRUE)
          );
          $iterator->rewind();
          break;

        case 'weekday':
          if ($diff > 0) {
            $date->modify('-' . $diff . ' days');
          }
          else {
            $date->modify('-1 week')->modify('+' . abs($diff) . ' days');
          }
          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            intval($date->format('j'))
          )->setTime(
            self::adjustValues($date, 'hour', $values['hour'], TRUE),
            self::adjustValues($date, 'minute', $values['minute'], TRUE)
          );
          $iterator->rewind();
          break;

        case 'month':
          if ($diff > 0) {
            $date->modify('-' . $diff . ' months');
          }
          else {
            $date->modify('-1 year')->modify('+' . abs($diff) . ' months');
          }

          $date->setDate(
            intval($date->format('Y')),
            intval($date->format('n')),
            self::adjustValues($date, 'day', $values['day'], TRUE)
          )->setTime(
            self::adjustValues($date, 'hour', $values['hour'], TRUE),
            self::adjustValues($date, 'minute', $values['minute'], TRUE)
          );
          $iterator->rewind();
          break;
      }
    }

    return $date;
  }

  /**
   * Adjust the values for the given component type (minute, hour, day etc.).
   *
   * This currently adjusts the days to ensure we don't exceed the maximum
   * number of days in the month of the year fo provided date.
   *
   * @param \DateTime $date
   *   Date being worked with.
   * @param string $type
   *   Component type: minute, hour, day, month, year.
   * @param array $values
   *   Componenent allowed values.
   * @param bool $reset
   *   Whether to get the first value or the entire array.
   *
   * @return array|int
   *   Full adjusted allowed values or the first one if reset was TRUE.
   */
  public static function adjustValues(\DateTime $date, $type, array $values, $reset = FALSE) {
    if ($type === 'day') {
      $max = intval($date->format('t'));

      $days = [];
      foreach ($values as $value) {
        if ($value <= $max) {
          $days[] = $value;
        }
      }
      $values = $days;
    }

    return $reset ? reset($values) : $values;
  }

  /**
   * Get the next allowed value.
   *
   * @param array $values
   *   Allowed value.
   * @param int $reference
   *   Current reference value.
   *
   * @return int
   *   Next value or last value.
   */
  public static function getNext(array $values, $reference) {
    foreach ($values as $value) {
      if ($value >= $reference) {
        return $value;
      }
    }
    return reset($values);
  }

  /**
   * Get the previous allowed value.
   *
   * Note: this works on previously reversed values. It's the responsibility
   * of the caller to prepare the values.
   *
   * @param array $values
   *   Allowed value.
   * @param int $reference
   *   Current reference value.
   *
   * @return int
   *   Previous value or last value.
   */
  public static function getPrevious(array $values, $reference) {
    foreach ($values as $value) {
      if ($value <= $reference) {
        return $value;
      }
    }
    return reset($values);
  }

  /**
   * Parse a component of the a cron expression.
   *
   * @param string $type
   *   Type of the component.
   * @param string $component
   *   Component value.
   *
   * @return array
   *   Allowed values for the component.
   */
  public static function parseComponent($type, $component) {
    static $minmax = [
      'minute' => [0, 59],
      'hour' => [0, 23],
      'day' => [1, 31],
      'month' => [1, 12],
      'weekday' => [0, 6],
    ];

    [$min, $max] = $minmax[$type];

    switch ($type) {
      case 'month':
        $component = self::translateMonth($component);
        break;

      case 'weekday':
        $component = self::translateWeekday($component);
        break;
    }

    $values = [];
    foreach (explode(',', $component) as $value) {
      $values = array_merge($values, self::getAllowedValues($value, $min, $max));
    }

    $values = array_unique($values);
    sort($values);

    return $values;
  }

  /**
   * Translate a spelled-out months into their numeric values.
   *
   * @param string $component
   *   Month component.
   *
   * @return string
   *   Translated month component.
   */
  public static function translateMonth($component) {
    static $replacements = [
      'january' => 1,
      'february' => 2,
      'march' => 3,
      'april' => 4,
      'may' => 5,
      'june' => 6,
      'july' => 7,
      'august' => 8,
      'september' => 9,
      'october' => 10,
      'november' => 11,
      'december' => 12,
      'jan' => 1,
      'feb' => 2,
      'mar' => 3,
      'apr' => 4,
      'may' => 5,
      'jun' => 6,
      'jul' => 7,
      'aug' => 8,
      'sep' => 9,
      'oct' => 10,
      'nov' => 11,
      'dec' => 12,
    ];
    return strtr(strtolower($component), $replacements);
  }

  /**
   * Translate a spelled-out weekdays into their numeric values.
   *
   * @param string $component
   *   Weekday component.
   *
   * @return string
   *   Translated weekday component.
   */
  public static function translateWeekday($component) {
    static $replacements = [
      // Sunday is 7 when at the end of a range.
      '-sunday' => -7,
      '-sun' => -7,
      // "Normal" order with Sunday as start of the week.
      'sunday' => 0,
      'monday' => 1,
      'tuesday' => 2,
      'wednesday' => 3,
      'thursday' => 4,
      'friday' => 5,
      'saturday' => 6,
      'sun' => 0,
      'mon' => 1,
      'tue' => 2,
      'wed' => 3,
      'thu' => 4,
      'fri' => 5,
      'sat' => 6,
    ];
    return strtr(strtolower($component), $replacements);
  }

  /**
   * Get the allowed values for a component value.
   *
   * @param string $value
   *   Component value.
   * @param int $min
   *   Minimum allowed value for the component.
   * @param int $max
   *   Maximum allowed value for the component.
   *
   * @return array
   *   Allowed values.
   */
  public static function getAllowedValues($value, $min, $max) {
    // Step for the range.
    if (strpos($value, '/') !== FALSE) {
      if (substr_count($value, '/') > 1) {
        throw new Exception();
      }
      [$value, $step] = explode('/', $value);
      $step = static::checkInt($step, 1, $max);
    }

    // Full range.
    if ($value === '*') {
      $start = $min;
      $end = $max;
    }
    // Range.
    elseif (strpos($value, '-') !== FALSE) {
      if (substr_count($value, '-') > 1) {
        throw new Exception();
      }
      [$start, $end] = explode('-', $value);
      $start = self::checkInt($start, 0, $max);
      $end = self::checkInt($end, 1, $max);
      if ($start >= $end) {
        throw new Exception();
      }
    }
    // Single value.
    else {
      $start = self::checkInt($value, 0, $max);
    }

    if (isset($step)) {
      // Equivalent of having a single value: start.
      //
      // Note: there are different interpretation of a step with a single value.
      // - https://crontab.guru considers it's a range from the single value to
      //   the max value: 1/5 * * * * means the cron runs at minute 1, 6, 11, 16
      //   and so on.
      // - http://cron.schlitt.info ignores the step for single values:
      //   1/5 * * * * means the cron runs at minute 1 of every hour.
      if (!isset($end) || $step >= $end - $start) {
        return [$start];
      }
      return range($start, $end, $step);
    }
    return isset($end) ? range($start, $end) : [$start];
  }

  /**
   * Check if a value is a valid integer between min and max.
   *
   * @param string $value
   *   Numeric value.
   * @param int $min
   *   Minimum allowed value.
   * @param int $max
   *   Maximum allowed value.
   *
   * @return int|false
   *   FALSE if invalid, filtered value otherwise.
   */
  public static function checkInt($value, $min, $max) {
    $result = filter_var($value, FILTER_VALIDATE_INT, [
      'min_range' => $min,
      'max_range' => $max,
    ]);
    if ($result === FALSE) {
      throw new Exception();
    }
    return $result;
  }

}
