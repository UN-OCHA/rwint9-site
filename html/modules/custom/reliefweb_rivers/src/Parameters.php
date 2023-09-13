<?php

namespace Drupal\reliefweb_rivers;

use Drupal\Component\Utility\Html;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Parse URL parameters for the rivers.
 *
 * Note: this handles various legacy filters.
 */
class Parameters {

  /**
   * Map fields from legacy search system to the new API based one.
   *
   * @var array
   *
   * @todo verify field mapping.
   */
  protected $mapping = [
    'sphinxsearch' => [
      'node_title' => 'title',
      'field_data_body_body_value' => 'body',
      'field_data_field_how_to_apply_field_how_to_apply_value' => 'how_to_apply',
      'title' => 'title',
      'body' => 'body',
      'howtoapply' => 'how_to_apply',
      'source' => 'source',
      'country' => 'country',
      'theme' => 'theme',
      'content_format' => 'format',
      'language' => 'language',
      // Disable vulnerable group field (#kUklB1e4).
      /*'vulnerable_groups' => 'vulnerable_groups',*/
      'disaster' => 'disaster',
      'disaster_type' => 'disaster_type',
      'primary_country' => 'primary_country',
      'ocha_product' => 'ocha_product',
    ],
    'searchlight' => [
      'taxonomy_term_data_field_data_field_primary_country_tid' => 'primary_country',
      'taxonomy_index_tid_country' => 'country',
      'taxonomy_index_tid_source' => 'source',
      'taxonomy_index_tid_theme' => 'theme',
      'taxonomy_index_tid_content_format' => 'format',
      'taxonomy_index_tid_feature' => 'feature',
      'taxonomy_index_tid_disaster_type' => 'disaster_type',
      // Disable vulnerable group field (#kUklB1e4).
      /*'taxonomy_index_tid_vulnerable_groups' => 'vulnerable_groups',*/
      'field_data_field_report_date_field_report_date_value' => 'date',
      'taxonomy_index_tid_language' => 'language',
      'taxonomy_term_data_field_data_field_country_tid' => 'country',
      'taxonomy_term_data_field_data_field_disaster_type_tid' => 'type',
      'field_data_field_disaster_date_field_disaster_date_value' => 'date',
      'taxonomy_index_tid_career_categories' => 'career_categories',
      'taxonomy_index_tid_job_type' => 'type',
      'taxonomy_index_tid_job_experience' => 'experience',
      'field_data_field_job_closing_date_field_job_closing_date_val' => 'date.closing',
      'taxonomy_index_tid_training_type' => 'type',
      'taxonomy_index_tid_training_format' => 'format',
      'field_data_field_registration_deadline_field_registration_de' => 'date.registration',
      'field_data_field_training_date_field_training_date_value' => 'date.start',
      'field_data_field_training_date_field_training_date_value2' => 'date.end',
    ],
    'searchapi' => [
      'field_primary_country' => 'primary_country',
      'field_city' => 'city',
      'field_country' => 'country',
      'field_source' => 'source',
      'field_theme' => 'theme',
      'field_disaster_date' => 'date',
      'field_disaster_type' => 'disaster_type',
      'field_content_format' => 'format',
      // Disable vulnerable group field (#kUklB1e4).
      /*'field_vulnerable_groups' => 'vulnerable_groups',*/
      'field_language' => 'language',
      'field_report_date' => 'date',
      'field_job_type' => 'type',
      'field_job_experience' => 'experience',
      'field_career_categories' => 'career_categories',
      'field_job_closing_date' => 'closing',
      'field_training_date:value' => 'start',
      'field_training_date:value2' => 'end',
      'field_training_format' => 'format',
      'field_training_type' => 'type',
      'field_registration_deadline' => 'registration',
      'field_status' => 'status',
    ],
    'extended_search' => [
      'field_primary_country' => 'PC',
      'field_city' => 'CI',
      'field_country' => 'C',
      'field_source' => 'S',
      'field_theme' => 'T',
      'field_disaster_date' => 'DA',
      'field_disaster_type' => 'DT',
      'field_disaster' => 'FD',
      'field_content_format' => 'F',
      // Disable vulnerable group field (#kUklB1e4).
      /*'field_vulnerable_groups' => 'VG',*/
      'field_language' => 'L',
      'field_report_date' => 'DO',
      'field_job_type' => 'TY',
      'field_job_experience' => 'E',
      'field_career_categories' => 'CC',
      'field_job_closing_date' => 'DC',
      'field_training_date' => 'DS',
      'field_training_format' => 'F',
      'field_training_type' => 'TY',
      'field_registration_deadline' => 'DR',
      'created' => 'DA',
    ],
  ];

  /**
   * Legacy regions => countries mapping.
   *
   * @var array
   */
  protected $regions = [
    12479 => [
      13,
      31,
      38,
      119,
      148,
      168,
      182,
      219,
    ],
    12481 => [
      14,
      15,
      18,
      26,
      33,
      34,
      40,
      45,
      56,
      70,
      72,
      73,
      76,
      86,
      89,
      91,
      92,
      101,
      103,
      104,
      115,
      117,
      118,
      123,
      124,
      126,
      136,
      141,
      142,
      143,
      145,
      150,
      158,
      159,
      161,
      169,
      179,
      190,
      191,
      196,
      197,
      205,
      209,
      213,
      214,
      218,
      222,
      224,
      225,
      229,
      236,
      241,
      243,
    ],
    12469 => [
      16,
      28,
      51,
      82,
      140,
      163,
      235,
      253,
    ],
    12482 => [
      17,
      25,
      62,
      63,
      67,
      80,
      90,
      94,
      108,
      114,
      132,
      151,
      157,
      167,
      171,
      172,
      176,
      177,
      178,
      183,
      185,
      189,
      204,
      215,
      232,
      233,
      239,
      249,
      252,
    ],
    12467 => [
      19,
      47,
      49,
      54,
      55,
      66,
      75,
      84,
      96,
      198,
      206,
      244,
    ],
    12470 => [
      19,
      41,
      65,
      138,
      144,
      146,
      154,
      164,
      166,
      210,
      217,
      223,
      244,
      256,
      257,
    ],
    12473 => [
      20,
      21,
      24,
      29,
      32,
      35,
      37,
      43,
      53,
      71,
      78,
      79,
      93,
      106,
      107,
      112,
      113,
      127,
      152,
      162,
      170,
      192,
      200,
      201,
      203,
      221,
      234,
      238,
      246,
    ],
    12475 => [
      22,
      39,
      42,
      57,
      64,
      81,
      88,
      93,
      97,
      112,
      186,
      187,
      221,
      247,
      250,
    ],
    12476 => [
      23,
      27,
      100,
      130,
      134,
      227,
      237,
      248,
    ],
    12483 => [
      30,
      72,
      121,
      122,
      125,
      129,
      133,
      137,
      180,
      181,
      193,
      207,
      226,
      236,
      242,
      255,
    ],
    12477 => [
      31,
      48,
      58,
      59,
      60,
      61,
      74,
      120,
      128,
      135,
      147,
      160,
      165,
      185,
      194,
      228,
      230,
      251,
    ],
    12478 => [
      31,
      44,
      48,
      120,
      135,
      147,
      165,
      188,
      212,
      228,
      230,
      251,
    ],
    12474 => [
      35,
      68,
      83,
      109,
      116,
      156,
      173,
      184,
    ],
    12471 => [
      36,
      46,
      49,
      52,
      54,
      55,
      66,
      69,
      75,
      84,
      96,
      98,
      102,
      110,
      111,
      139,
      149,
      153,
      174,
      175,
      199,
      206,
      208,
      211,
      231,
    ],
    12472 => [
      37,
      50,
      105,
      192,
      202,
      245,
    ],
    12468 => [
      47,
      55,
      65,
      77,
      85,
      87,
      131,
      144,
      146,
      154,
      155,
      164,
      195,
      198,
      210,
      216,
      220,
      240,
      244,
      256,
      257,
      8657,
    ],
  ];

  /**
   * Query parameters.
   *
   * @var array
   */
  protected $parameters = [];

  /**
   * Create the Parameters parser and handle legacy parameters.
   *
   * @param array $query
   *   Query from which to extract the parameters.
   * @param array $exclude
   *   Parameters to exclude from the returned parameters.
   */
  public function __construct(
    array $query = NULL,
    array $exclude = ['q', 'page']
  ) {
    $this->parameters = static::getParameters($query, $exclude);

    // Handle legacy.
    $this->parseSphinxsearch();
    $this->parseSearchlight();
    $this->parseSearchapi();
    $this->parseExtendedSearch();
    $this->parseRegions();
  }

  /**
   * Create a parameters object from the given URL.
   *
   * @param string $url
   *   URL.
   * @param array $exclude
   *   Parameters to exclude from the returned parameters.
   *
   * @return \Drupal\reliefweb_rivers\Parameters
   *   Parameters object.
   */
  public static function createFromUrl($url, array $exclude = ['q', 'page']) {
    $query = [];
    if (is_string($url)) {
      parse_str(parse_url($url, PHP_URL_QUERY), $query);
    }
    return new static($query, $exclude);
  }

  /**
   * Parse the parameters from the given query or the current one.
   *
   * @param array $query
   *   Query from which to extract the parameters.
   * @param array $exclude
   *   Parameters to exclude from the returned parameters.
   *
   * @return array
   *   List of parameters (key/value pairs).
   */
  public static function getParameters(
    array $query = NULL,
    array $exclude = ['q', 'page'],
  ) {
    if (!isset($query)) {
      $query = \Drupal::request()->query->all();
    }
    return UrlHelper::filterQueryParameters($query, $exclude);
  }

  /**
   * Get all the parameters excluding the given ones.
   *
   * @param array $exclude
   *   Parameters to exclude.
   *
   * @return array
   *   Parameters.
   */
  public function getAll(array $exclude = []) {
    $parameters = [];

    $exclude = array_flip($exclude);
    foreach ($this->parameters as $name => $value) {
      // Skip if there is no value or explicitly indicated to exclude.
      if (!isset($value) || isset($exclude[$name])) {
        continue;
      }

      // Trim string parameters.
      if (is_string($value)) {
        $value = TextHelper::trimText($value);
        if ($value === '') {
          continue;
        }
      }

      $parameters[$name] = $value;
    }

    return $parameters;
  }

  /**
   * Get all the parameters excluding the given ones, sorted.
   *
   * @param array $exclude
   *   Parameters to exclude.
   * @param array $order
   *   Order of the parameters.
   * @param bool $include_others
   *   If FALSE, parameters that are not in the order list will not be included.
   *
   * @return array
   *   Sorted parameters.
   */
  public function getAllSorted(array $exclude = [], array $order = [], $include_others = TRUE) {
    $order = $order ?: [
      'list',
      'view',
      'group',
      'advanced-search',
      'search',
      'page',
    ];
    $unsorted = $this->getAll($exclude);
    $sorted = [];
    foreach ($order as $key) {
      if (isset($unsorted[$key])) {
        $sorted[$key] = $unsorted[$key];
        unset($unsorted[$key]);
      }
    }
    return $include_others ? $sorted + $unsorted : $sorted;
  }

  /**
   * Get the query parameters.
   *
   * @param string $name
   *   Parameter name. NULL returns all parameters.
   * @param mixed $default
   *   Default value in case the parameter is not defined.
   *
   * @return array|mixed
   *   All query parameters if $name is NULL, the specified parameter otherwise.
   */
  public function get($name = NULL, $default = NULL) {
    if (empty($name)) {
      return $this->getAll();
    }
    elseif (isset($this->parameters[$name])) {
      $parameter = $this->parameters[$name];
      return is_string($parameter) ? TextHelper::trimText($parameter) : $parameter;
    }
    return $default;
  }

  /**
   * Get a query parameter as a string.
   *
   * @param string $name
   *   Parameter name. NULL returns all parameters.
   * @param string $default
   *   Default value in case the parameter is not defined.
   * @param bool $trim
   *   Whether to trim the parameter value or not.
   *
   * @return string
   *   The query parameter or an empty string.
   */
  public function getString($name, $default = '', $trim = TRUE) {
    if (isset($this->parameters[$name]) && is_scalar($this->parameters[$name])) {
      $parameter = (string) $this->parameters[$name];
    }
    else {
      $parameter = $default;
    }
    return $trim ? TextHelper::trimText($parameter) : $parameter;
  }

  /**
   * Set a parameter value.
   *
   * @param string $name
   *   Parameter name.
   * @param mixed $value
   *   Parameter value.
   * @param bool $trim
   *   If TRUE and $value is a string, then it will be trimmed.
   */
  public function set($name, $value = '', $trim = TRUE) {
    if ($trim && is_string($value)) {
      $value = TextHelper::trimText($value);
    }
    $this->parameters[$name] = $value;
  }

  /**
   * Unset a parameter.
   *
   * @param string $name
   *   Parameter name.
   */
  public function remove($name) {
    unset($this->parameters[$name]);
  }

  /**
   * Check if a parameter exists.
   *
   * @param string $name
   *   Parameter name.
   *
   * @return bool
   *   Parameter exists or not.
   */
  public function has($name) {
    return isset($this->parameters[$name]);
  }

  /**
   * Parse and convert the Sphinx search parameter.
   */
  protected function parseSphinxsearch() {
    $parameter = $this->getString('search');

    if (!empty($parameter)) {
      $mapping = $this->mapping['sphinxsearch'];
      $pattern = '/(^|\s+)@(' . implode('|', array_keys($mapping)) . ')\s+/';

      $this->parameters['search'] = preg_replace_callback($pattern, function ($match) use ($mapping) {
        return $match[1] . $mapping[$match[2]] . ':';
      }, $parameter);

      // Fix queries starting with AND or OR.
      $this->parameters['search'] = preg_replace('/^\s*(AND|OR)\s+/', '', $this->parameters['search']);
    }
  }

  /**
   * Parse and convert the Searchlight parameters.
   */
  protected function parseSearchlight() {
    $parameter = $this->getString('sl');

    // Remove Searchlight parameters.
    $this->remove('sl');

    if (!empty($parameter)) {
      $parameters = explode(',', urldecode($parameter));
      $mapping = $this->mapping['searchlight'];

      foreach ($parameters as $parameter) {
        // Skip the environment parameter.
        if (strpos($parameter, 'environment') === 0) {
          continue;
        }
        elseif (preg_match('/^[a-z0-9_]+-[0-9:]+$/', $parameter) === 1) {
          [$field, $values] = explode('-', $parameter, 2);
          if (isset($mapping[$field])) {
            $field = $mapping[$field];
            $values = explode(':', $values);

            // Only keep last date.
            if (strpos($field, 'date') === 0) {
              $field = str_replace('date.', '', $field);
              $date = static::createDate('now')->setTimestamp(end($values));
              $value = static::formatDateInterval('month', $date);
            }
            else {
              $value = implode('.', $values);
            }
            $this->parameters[$field] = $value;
          }
        }
      }
    }
  }

  /**
   * Parse and convert the Searchapi parameters.
   */
  protected function parseSearchapi() {
    $parameters = $this->get('f', FALSE);

    // Remove Search API parameters.
    $this->remove('f');

    if (is_array($parameters) && !empty($parameters)) {
      $mapping = $this->mapping['searchapi'];
      $fields = ['term' => [], 'date' => []];
      foreach ($parameters as $parameter) {
        if (is_string($parameter) && strpos($parameter, ':') > 0) {
          [$field, $value] = explode(':', $parameter, 2);
          $field = urldecode($field);

          if (isset($mapping[$field])) {
            $field = $mapping[$field];

            // Date field.
            if (strpos($value, ' TO ') > 0) {
              // Special handling of "Ongoing course" from
              // training date start facet.
              if ($value === '[ TO ]') {
                $fields['date'][$field]['_'] = '_';
              }
              else {
                $dates = static::parseDateInterval(substr($value, 1, -1), ' TO ', 'Y-m-d\T00:00:00\Z');
                if (!empty($dates)) {
                  $interval = static::getDateInterval($dates);
                  $value = static::formatDateInterval($interval, $dates[0]);
                  $fields['date'][$field][$interval] = $value;
                }
              }
            }
            elseif (ctype_digit($value) && ($value = (int) $value) !== 0) {
              $fields['term'][$field][] = $value;
            }
          }
        }
      }

      foreach ($fields['term'] as $field => $values) {
        $this->parameters[$field] = implode('.', $values);
      }

      foreach ($fields['date'] as $field => $values) {
        $this->parameters[$field] = $values[min(array_keys($values))];
      }
    }
  }

  /**
   * Parse and convert regions parameter.
   */
  protected function parseRegions() {
    $parameters = $this->get('regions', FALSE);

    // Remove the old regions parameter.
    $this->remove('regions');

    if (!empty($parameters) && is_array($parameters)) {
      $values = [];
      foreach ($parameters as $id) {
        if (isset($this->regions[$id])) {
          $values = array_merge($values, $this->regions[$id]);
        }
      }
      if (!empty($values)) {
        $this->set('region', implode('.', array_unique($values)));
      }
    }
  }

  /**
   * Convert the extended search parameter to the new advanced search one.
   */
  protected function parseExtendedSearch() {
    $parameter = $this->getString('extended_search');

    // Remove extended search parameter.
    $this->remove('extended_search');

    if (!empty($parameter) && ($parameters = json_decode($parameter, TRUE)) !== NULL) {
      // Full text search.
      if (!empty($parameters['text'])) {
        $search_query = [$this->getString('search')];
        if (!empty($parameters['text']['all'])) {
          $search_query[] = '"' . implode('" AND "', $parameters['text']['all']) . '"';
        }
        if (!empty($parameters['text']['exact'])) {
          $search_query[] = '"' . str_replace('"', '\"', $parameters['text']['exact']) . '"';
        }
        if (!empty($parameters['text']['any'])) {
          $search_query[] = '"' . implode('" OR "', $parameters['text']['any']) . '"';
        }
        if (!empty($parameters['text']['exclude'])) {
          $search_query[] = 'NOT "' . implode('" NOT "', $parameters['text']['exclude']) . '"';
        }

        $search_query = array_filter($search_query);
        if (count($search_query) > 1) {
          $this->parameters['search'] = '(' . implode(') (', $search_query) . ')';
        }
        elseif (!empty($search_query)) {
          $this->parameters['search'] = reset($search_query);
        }
      }

      // Filters.
      if (!empty($parameters['filters'])) {
        $filters = [$this->getString('advanced-search')];

        $operators = [
          '|' => '(',
          '|NOT' => '!(',
          'OR|' => '.',
          'OR|NOT' => ').!(',
          'AND|' => '_',
          'AND|NOT' => ')_!(',
        ];

        foreach ($parameters['filters'] as $name => $items) {
          if (isset($this->mapping['extended_search'][$name]) && !empty($items) && is_array($items)) {
            $field = $this->mapping['extended_search'][$name];
            $result = '';
            $previous = '';

            foreach ($items as $key => $item) {
              if (is_array($item) && count($item) === 2 && is_string($item[0]) && is_string($item[1])) {
                [, $operator] = explode('|', strtoupper($item[0]), 2);
                $operator = $key === 0 ? '|' : $operator;

                // Skip if unrecognized operator.
                if (!isset($operators[$operator])) {
                  break;
                }

                $operator = $operators[$operator];
                $value = $item[1];

                // Check group inner operator change.
                if (($operator === '_' || $operator === '.') && $key > 0) {
                  if ($previous !== $operator && $previous !== '(' && $previous !== ')_(' && $previous !== ').(') {
                    $operator = ')' . $operator . '(';
                  }
                }

                // Check and prepare value.
                if (strpos($value, '|') > 0) {
                  $dates = static::parseDateInterval($value, '|', 'U');
                  if (!empty($dates)) {
                    $value = $dates[0]->format('Ymd') . '-' . $dates[1]->modify('-1 day +1 second')->format('Ymd');
                    $result .= $operator . $field . $value;
                  }
                }
                elseif (($value = (int) $value) !== 0) {
                  $result .= $operator . $field . $value;
                }

                $previous = $operator;
              }
            }
            if (!empty($result)) {
              $filters[] = $result . ')';
            }
          }
        }

        $filters = array_filter($filters);
        if (!empty($filters)) {
          $this->parameters['advanced-search'] = implode('_', $filters);
        }
      }
    }
  }

  /**
   * Convert URL parameters to hidden inputs.
   *
   * @param array $exclude
   *   Parameters to exlcude from the converion.
   *
   * @return string
   *   Hidden inputs.
   */
  public function toHidden(array $exclude = []) {
    return static::parametersToHidden(array_diff($this->parameters, $exclude));
  }

  /**
   * Convert URL parameters to hidden inputs.
   *
   * @param array $parameters
   *   Parameters to convert.
   *
   * @return string
   *   Hidden inputs.
   */
  public static function parametersToHidden(array $parameters) {
    $output = [];
    static::arrayToHidden($output, $parameters);
    return implode($output);
  }

  /**
   * Convert an array to hidden inputs.
   *
   * @param array $output
   *   Array to which to add the hidden inputs.
   * @param array|scalar $value
   *   Parameter value.
   * @param string $key
   *   Parameter name.
   */
  public static function arrayToHidden(array &$output, $value, $key = '') {
    if (!is_array($value) && !empty($key)) {
      $output[] = '<input type="hidden" name="' . Html::escape($key) . '" value="' . Html::escape($value) . '"/>';
    }
    else {
      foreach ($value as $subkey => $subvalue) {
        $subkey = $key ? $key . '[' . $subkey . ']' : $subkey;
        static::arrayToHidden($output, $subvalue, $subkey);
      }
    }
  }

  /**
   * Create a DateTime object from the given date.
   *
   * @param string $string
   *   Date string.
   *
   * @return \DateTimeImmutable
   *   DateTime object with timezone set to UTC.
   */
  public static function createDate($string) {
    return new \DateTimeImmutable($string, new \DateTimeZone('UTC'));
  }

  /**
   * Validate a date with a specific format.
   *
   * @param string $string
   *   Date string.
   * @param string $format
   *   Date format.
   *
   * @return \DateTimeImmutable|null
   *   DateTime object if valid or NULL otherwise.
   */
  public static function validateDate($string, $format = 'Ymd') {
    if (empty($string)) {
      return NULL;
    }
    else {
      $date = \DateTimeImmutable::createFromFormat('!' . $format, $string, new \DateTimeZone('UTC'));
      return $date && $date->format($format) === $string ? $date : NULL;
    }
  }

  /**
   * Parse a date interval string and return DateTime objects if valid.
   *
   * @param string $value
   *   Date interval string.
   * @param string $separator
   *   Date separator.
   * @param string $format
   *   Format of the dates.
   * @param bool $validateBoth
   *   Indicate if both dates should be valid.
   *
   * @return array
   *   Array of \DateTime objects.
   */
  public static function parseDateInterval($value, $separator = '-', $format = 'Ymd', $validateBoth = TRUE) {
    if (!empty($value) && strpos($value, $separator) >= 0) {
      $dates = explode($separator, $value, 2);
      $dates[0] = static::validateDate($dates[0], $format);
      if (isset($dates[1])) {
        $dates[1] = static::validateDate($dates[1], $format);
      }
      if ($validateBoth) {
        return !empty($dates[0]) && !empty($dates[1]) ? $dates : [];
      }
      return $dates;
    }
    return [];
  }

  /**
   * Calculate interval type between 2 dates.
   *
   * @param array $dates
   *   Array of 2 DateTime objects.
   * @param bool $next
   *   Indicates whether to return the current interval or the next one.
   *
   * @return string
   *   Date interval (year, month or day).
   */
  public static function getDateInterval(array $dates, $next = FALSE) {
    if (!empty($dates)) {
      $diff = $dates[1]->diff($dates[0]);
      if ($diff->y >= 1) {
        return $next ? 'month' : 'year';
      }
      elseif ($diff->m >= 1) {
        return $next ? 'day' : 'month';
      }
      return 'day';
    }
    return 'year';
  }

  /**
   * Format a date interval.
   *
   * @param string $interval
   *   Interval (year, month or day).
   * @param \DateTimeInterface|null $date
   *   DateTime object.
   * @param string $separator
   *   Date separator.
   * @param string $format
   *   Format of the dates.
   *
   * @return string
   *   Date interval.
   */
  public static function formatDateInterval($interval, ?\DateTimeInterface $date, $separator = '-', $format = 'Ymd') {
    if (!empty($date)) {
      switch ($interval) {
        case 'year':
          $date = $date->setDate($date->format('Y'), 1, 1);
          break;

        case 'month':
          $date = $date->setDate($date->format('Y'), $date->format('n'), 1);
          break;
      }
      return $date->format($format) . $separator . $date->modify('+1 ' . $interval)->format($format);
    }
    return '';
  }

}
