<?php

namespace Drupal\reliefweb_rivers;

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
        $value = trim($value);
        if ($value === '') {
          continue;
        }
      }

      $parameters[$name] = $value;
    }

    return $parameters;
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
      return is_string($parameter) ? trim($parameter) : $parameter;
    }
    return $default;
  }

  /**
   * Set a parameter value.
   *
   * @param string $name
   *   Parameter name.
   * @param mixed $value
   *   Parameter value.
   */
  public function set($name, $value = '') {
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
    $parameter = $this->get('search');

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
    $parameter = $this->get('sl');

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
          list($field, $values) = explode('-', $parameter, 2);
          if (isset($mapping[$field])) {
            $field = $mapping[$field];
            $values = explode(':', $values);

            // Only keep last date.
            if (strpos($field, 'date') === 0) {
              $field = str_replace('date.', '', $field);
              $date = RWRiversDateHandler::createDate('now')->setTimestamp(end($values));
              $value = RWRiversDateHandler::formatDateInterval('month', $date);
            }
            else {
              $value = RWRiversFacetsHandler::implodeValues($values);
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
          list($field, $value) = explode(':', $parameter, 2);
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
                $dates = RWRiversDateHandler::parseDateInterval(substr($value, 1, -1), ' TO ', 'Y-m-d\T00:00:00\Z');
                if (!empty($dates)) {
                  $interval = RWRiversDateHandler::getDateInterval($dates);
                  $value = RWRiversDateHandler::formatDateInterval($interval, $dates[0]);
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
        $this->parameters[$field] = RWRiversFacetsHandler::implodeValues($values);
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
      module_load_include('inc', 'reliefweb_taxonomy', 'includes/regions');
      $countries_by_regions = reliefweb_taxonomy_regions_get_countries_by_regions();
      $values = [];
      foreach ($parameters as $id) {
        if (isset($countries_by_regions[$id])) {
          $values += $countries_by_regions[$id];
        }
      }
      if (!empty($values)) {
        $this->set('region', RWRiversFacetsHandler::implodeValues(array_keys($values)));
      }
    }
  }

  /**
   * Convert the extended search parameter to the new advanced search one.
   */
  protected function parseExtendedSearch() {
    $parameter = $this->get('extended_search');

    // Remove extended search parameter.
    $this->remove('extended_search');

    if (!empty($parameter) && ($parameters = json_decode($parameter, TRUE)) !== NULL) {
      // Full text search.
      if (!empty($parameters['text'])) {
        $search_query = [$this->get('search')];
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
        $filters = [$this->get('advanced-search')];

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
                list(, $operator) = explode('|', strtoupper($item[0]), 2);
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
                  $dates = RWRiversDateHandler::parseDateInterval($value, '|', 'U');
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
      $output[] = '<input type="hidden" name="' . check_plain($key) . '" value="' . check_plain($value) . '"/>';
    }
    else {
      foreach ($value as $subkey => $subvalue) {
        $subkey = $key ? $key . '[' . $subkey . ']' : $subkey;
        static::arrayToHidden($output, $subvalue, $subkey);
      }
    }
  }

}
