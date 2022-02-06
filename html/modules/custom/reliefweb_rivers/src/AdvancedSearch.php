<?php

namespace Drupal\reliefweb_rivers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Advanced search handler.
 */
class AdvancedSearch {


  use StringTranslationTrait;

  /**
   * Maximum number of values for a filter.
   */
  const MAX_FILTER_VALUES = 500;

  /**
   * The entity bundle associated with the river.
   *
   * @var string
   */
  protected $bundle;

  /**
   * River name.
   *
   * @var string
   */
  protected $river;

  /**
   * River parameters.
   *
   * @var \Drupal\reliefweb_rivers\Parameters
   */
  protected $parameters;

  /**
   * List of filters for the river.
   *
   * @var array
   */
  protected $filters;

  /**
   * Filter sample.
   *
   * @var string
   */
  protected $filterSample;

  /**
   * Advanced search operator mapping.
   *
   * @var array
   */
  protected $advancedSearchOperators = [
    'with' => '(',
    'without' => '!(',
    'and-with' => ')_(',
    'and-without' => ')_!(',
    'or-with' => ').(',
    'or-without' => ').!(',
    'or' => '.',
    'and' => '_',
  ];

  /**
   * Computed data.
   *
   * @var array
   */
  protected $data;

  /**
   * Constructor.
   *
   * @param string $bundle
   *   Entity bundle associated with the river.
   * @param string $river
   *   River name.
   * @param \Drupal\reliefweb_rivers\Parameters $parameters
   *   River parameter handler.
   * @param array $filters
   *   River filters.
   * @param string $filter_sample
   *   Text indicating the type of filters available for the river. This is
   *   used on mobile to make the filters more compact.
   */
  public function __construct($bundle, $river, Parameters $parameters, array $filters, $filter_sample) {
    $this->bundle = $bundle;
    $this->river = $river;
    $this->parameters = $parameters;
    $this->filters = $filters;
    $this->filterSample = $filter_sample;

    // Compute the advanced search data.
    $this->computeAdvancedSearchData();
  }

  /**
   * Get the advanced search data.
   *
   * @return array
   *   Advanced search data.
   */
  public function getData() {
    return $this->data;
  }

  /**
   * Get the sanitized advanced search parameter.
   *
   * @return array
   *   Advanced search parameter.
   */
  public function getParameter() {
    return $this->data['parameter'];
  }

  /**
   * Get the selected filters.
   *
   * @return array
   *   Advanced search selected filters.
   */
  public function getSelection() {
    return $this->data['selection'];
  }

  /**
   * Get the advanced search filter settings.
   *
   * @return array
   *   Advanced search filter settings.
   */
  public function getSettings() {
    return $this->data['settings'];
  }

  /**
   * Get the advanced search URL to clear the selection.
   *
   * @return array
   *   Advanced search sURL to clear the selection.
   */
  public function getClearUrl() {
    return $this->data['remove'];
  }

  /**
   * Get the advanced search data.
   */
  protected function computeAdvancedSearchData() {
    $components = [];

    // Update the advanced search parameter from the legacy facet and river
    // parameters, so that the advanced search looks like:
    // "WITH legacy-facets AND WITH legacy-river AND with advanced-search".
    $components[] = $this->parseLegacyFacetParameters();
    $components[] = $this->parseLegacyRiverParameter();
    $components[] = $this->parameters->get('advanced-search');

    // Update the advanced search parameter.
    $this->parameters->set('advanced-search', implode('_', array_filter($components)));

    // Get the filter section from the advanced search parameter.
    $selection = $this->getAdvancedSearchFilterSelection();

    // Prepare the filters.
    $filters = [];
    foreach ($this->filters as $code => $filter) {
      $filter['code'] = $code;

      // Prepare the widget data.
      $widget = $filter['widget'];
      switch ($widget['type']) {
        case 'autocomplete':
          $filter['widget']['label'] = $widget['label'];
          $filter['widget']['url'] = $this->getApiSuggestUrl($widget['resource'], $widget['parameters'] ?? []);
          break;

        case 'options':
          $options = [];
          switch ($filter['type']) {
            case 'reference':
              $options = $this->loadReferenceValues($filter, [], $filter['sort'] ?? 'name');
              // We only keep the values so that the options can be transformed
              // into a simple array in javascript so that we can perserve the
              // order.
              $options = array_values($options);
              break;

            case 'fixed':
              foreach ($filter['values'] as $id => $name) {
                $options[] = [
                  'id' => $id,
                  'name' => $name,
                ];
              }
              break;
          }
          $filter['widget']['options'] = $options;
          break;
      }

      $filters[$code] = $filter;
    }

    // Generate a link to clear the filter selection when javascript is not
    // availble.
    $remove = RiverServiceBase::getRiverUrl(
      $this->bundle,
      $this->parameters->getAll(['advanced-search'])
    );

    // Sanitize the advanced search parameter for the entire selection.
    $parameter = $this->getSanitiziedAdvancedSearchParameter($selection);
    $this->parameters->set('advanced-search', $parameter);

    $this->data = [
      'parameter' => $parameter,
      'selection' => $selection,
      'remove' => $remove,
      // Settings used by the javascript.
      'settings' => [
        'labels' => [
          'add' => $this->t('Add'),
          'apply' => $this->t('Apply filters'),
          'cancel' => $this->t('Cancel'),
          'clear' => $this->t('Clear all'),
          'remove' => $this->t('Remove filter'),
          'filterSelector' => $this->t('Add filter'),
          'fieldSelector' => $this->t('Select field'),
          'operatorSelector' => $this->t('Select operator'),
          'emptyOption' => $this->t('- Select -'),
          'dateFrom' => $this->t('From (YYYY/MM/DD)'),
          'dateTo' => $this->t('To (YYYY/MM/DD)'),
          'addFilter' => $this->t('Add filter'),
          // Translate the filter sample.
          'addFilterSuffix' => $this->filterSample,
          'filter' => $this->t('_filter_ filter'),
          'switchOperator' => $this->t('Change operator. Selected operator is _operator_.'),
          'simplifiedFilter' => $this->t('Add _filter_'),
          'on' => $this->t('On'),
          'off' => $this->t('Off'),
          'advancedMode' => $this->t('Advanced mode'),
          'changeMode' => $this->t('Disabling the advanced mode will clear your selection. Please confirm.'),
          'dates' => [
            'on' => $this->t('on _start_'),
            'before' => $this->t('before _end_'),
            'after' => $this->t('after _start_'),
            'range' => $this->t('_start_ to _end_'),
          ],
          'operators' => [
            'all' => $this->t('ALL OF'),
            'any' => $this->t('ANY OF'),
            'with' => $this->t('WITH'),
            'without' => $this->t('WITHOUT'),
            'and-with' => $this->t('AND WITH'),
            'and-without' => $this->t('AND WITHOUT'),
            'or-with' => $this->t('OR WITH'),
            'or-without' => $this->t('OR WITHOUT'),
            'and' => $this->t('AND'),
            'or' => $this->t('OR'),
          ],
        ],
        'placeholders' => [
          'autocomplete' => $this->t('Type and select...'),
          'keyword' => $this->t('Enter a keyword'),
          'dateFrom' => $this->t('e.g. 2019/10/03'),
          'dateTo' => $this->t('e.g. 2019/11/07'),
        ],
        'announcements' => [
          'changeFilter' => $this->t('Filter changed to _name_.'),
          'addFilter' => $this->t('Added _field_ _label_. Your are now looking for documents _selection_. Press apply filters to update the list.'),
          'removeFilter' => $this->t('Removed _field_ _label_. Your are now looking for documents _selection_. Press apply filters to update the list.'),
          'removeFilterEmpty' => $this->t('Removed _field_ _label_. Your selection is now empty. Press apply filters to update the list.'),
        ],
        'operators' => [
          [
            'label' => $this->t('To start the query'),
            'options' => [
              'with',
              'without',
            ],
          ],
          [
            'label' => $this->t('To use inside a group'),
            'options' => [
              'and',
              'or',
            ],
          ],
          [
            'label' => $this->t('To start a new group'),
            'options' => [
              'and-with',
              'and-without',
              'or-with',
              'or-without',
            ],
          ],
        ],
        // Convert to simple array so that we can preserve the order when
        // iterating over it in javascript.
        'filters' => array_values($filters),
      ],
    ];
  }

  /**
   * Parse legacy facet parameters.
   *
   * Convert the legacy parameters into the new "filter" parameter.
   *
   * @return string
   *   Legacy facets as an advanced search compatible query string.
   */
  public function parseLegacyFacetParameters() {
    $legacy_filters = [
      'blog' => [
        'tags' => [
          'operator' => 'OR',
          'shortcut' => 'T',
        ],
      ],
      'countries' => [
        'status' => [
          'operator' => 'OR',
          'shortcut' => 'SS',
        ],
      ],
      'disasters' => [
        'country' => [
          'shortcut' => 'C',
        ],
        'type' => [
          'operator' => 'OR',
          'shortcut' => 'DT',
        ],
        'status' => [
          'operator' => 'OR',
          'shortcut' => 'SS',
        ],
        'date' => [
          'shortcut' => 'DA',
          'widget' => 'date',
        ],
      ],
      'jobs' => [
        'type' => [
          'operator' => 'OR',
          'shortcut' => 'TY',
        ],
        'career_categories' => [
          'operator' => 'OR',
          'shortcut' => 'CC',
        ],
        'experience' => [
          'operator' => 'OR',
          'shortcut' => 'E',
        ],
        'theme' => [
          'operator' => 'OR',
          'shortcut' => 'T',
        ],
        'country' => [
          'operator' => 'OR',
          'shortcut' => 'C',
        ],
        'source' => [
          'operator' => 'OR',
          'shortcut' => 'S',
        ],
        'source_type' => [
          'operator' => 'OR',
          'shortcut' => 'ST',
        ],
        'closing' => [
          'shortcut' => 'DC',
        ],
        'created' => [
          'shortcut' => 'DA',
        ],
      ],
      'training' => [
        'type' => [
          'operator' => 'OR',
          'shortcut' => 'TY',
        ],
        'career_categories' => [
          'operator' => 'OR',
          'shortcut' => 'CC',
        ],
        'format' => [
          'shortcut' => 'F',
        ],
        'cost' => [
          'operator' => 'OR',
          'shortcut' => 'CO',
        ],
        'theme' => [
          'operator' => 'OR',
          'shortcut' => 'T',
        ],
        'country' => [
          'shortcut' => 'C',
          'widget' => 'autocomplete',
        ],
        'source' => [
          'operator' => 'OR',
          'shortcut' => 'S',
        ],
        'training_language' => [
          'operator' => 'OR',
          'shortcut' => 'TL',
        ],
        'created' => [
          'shortcut' => 'DA',
        ],
        'start' => [
          'shortcut' => 'DS',
        ],
        'end' => [
          'shortcut' => 'DE',
        ],
        'registration' => [
          'shortcut' => 'DR',
        ],
        'language' => [
          'operator' => 'OR',
          'shortcut' => 'L',
        ],
        'source_type' => [
          'operator' => 'OR',
          'shortcut' => 'ST',
        ],
      ],
      'updates' => [
        'primary_country' => [
          'shortcut' => 'PC',
        ],
        'country' => [
          'shortcut' => 'C',
        ],
        'source' => [
          'shortcut' => 'S',
        ],
        'source_type' => [
          'operator' => 'OR',
          'shortcut' => 'ST',
        ],
        'theme' => [
          'shortcut' => 'T',
        ],
        'format' => [
          'operator' => 'OR',
          'shortcut' => 'F',
        ],
        'disaster' => [
          'operator' => 'OR',
          'shortcut' => 'D',
        ],
        'disaster_type' => [
          'shortcut' => 'DT',
        ],
        // Disable vulnerable group field (#kUklB1e4).
        /*'vulnerable_groups' => [
          'operator' => 'OR',
          'shortcut' => 'VG',
        ],*/
        'language' => [
          'operator' => 'OR',
          'shortcut' => 'L',
        ],
        'date' => [
          'shortcut' => 'DO',
        ],
        'created' => [
          'shortcut' => 'DA',
        ],
        'feature' => [
          'operator' => 'OR',
          'shortcut' => 'FE',
        ],
      ],
    ];

    if (!isset($legacy_filters[$this->river])) {
      return '';
    }

    $filters = [];
    foreach ($legacy_filters[$this->river] as $field => $info) {
      if (isset($info['shortcut'], $this->filters[$info['shortcut']])) {
        $code = $info['shortcut'];
        $operator = isset($info['operator']) && $info['operator'] === 'OR' ? '.' : '_';
        $values = $this->parameters->get($field);
        if (!empty($values)) {
          $filters[] = '(' . $code . str_replace('.', $operator . $code, $values) . ')';
        }
        $this->parameters->remove($field);
      }
    }

    return implode('_', $filters);
  }

  /**
   * Parse legacy river parameters.
   *
   * Add a filter based on the legacy river path (country or disaster). This
   * applies only to the updates river.
   *
   * Note: previously, there was a full river of updates on the country and
   * disaster pages but those have been removed to have a single river
   * (/updates). This function handles those legacy country/disaster rivers.
   *
   * @return string
   *   Legacy river as an advanced search compatible query string.
   */
  public function parseLegacyRiverParameter() {
    if ($this->river !== 'updates' || !$this->parameters->has('legacy-river')) {
      return '';
    }
    // Get and remove the parameter.
    $path = $this->parameters->get('legacy-river');
    $this->parameters->remove('legacy-river');

    // Check if the path is using the alias form and, if so, find the term path.
    if (preg_match('#(country|disaster)/([a-z0-9-]+)#', $path, $matches) === 1) {
      $path = UrlHelper::getPathFromAlias('/' . $path);
    }

    // Validate and extract the id from the path.
    if (preg_match('#taxonomy/term/([0-9]+)#', $path, $matches) !== 1) {
      return '';
    }
    $id = intval($matches[1], 10);

    // Check if the term exists and get its vocabulary.
    $vocabulary = static::getVocabularyFromTermId($id);
    if ($vocabulary !== 'country' && $vocabulary !== 'disaster') {
      return '';
    }

    // Filter on the primary country or disaster.
    return '(' . ($vocabulary === 'country' ? 'PC' : 'D') . $id . ')';
  }

  /**
   * Get the filter selection for the advanced search.
   *
   * @return array
   *   Selected filters with valid values. We are relatively permissive in the
   *   the sense that we just ignore invalid values.
   */
  public function getAdvancedSearchFilterSelection() {
    return $this->parseAdvancedSearchParameter($this->parameters->get('advanced-search'));
  }

  /**
   * Parse advanced search parameter.
   *
   * Format is (X123.X456)_(Y123_Y456)_(Z20190101-20190202) where X, Y, Z is the
   * code for the filter, 123 is the filter value and '.' is the OR operator
   * (any) and the '_' is the AND operator (all).
   *
   * @param string $parameter
   *   Filter parameter.
   *
   * @return array
   *   Selected filters with valid values. We are relatively permissive in the
   *   the sense that we just ignore invalid values.
   */
  public function parseAdvancedSearchParameter($parameter = '') {
    if (empty($parameter)) {
      return [];
    }
    // Validate.
    $pattern = '/^(((^|[._])!?)\(([A-Z]+(-?\d+|\d+-\d*|[0-9a-z-]+)([._](?!\)))?)+\))+$/';
    if (preg_match($pattern, $parameter) !== 1) {
      return [];
    }

    // Parse parameter.
    $matches = [];
    $pattern = '/(!?\(|\)[._]!?\(|[._])([A-Z]+)(\d+-\d*|-?\d+|[0-9a-z-]+)/';
    if (preg_match_all($pattern, $parameter, $matches, PREG_SET_ORDER) === FALSE) {
      return [];
    }

    // Truncate if it exceeds the maximum allowed.
    if (count($matches) > static::MAX_FILTER_VALUES) {
      $matches = array_slice($matches, 0, static::MAX_FILTER_VALUES);
    }

    // We do 2 passes, one to accumulate the taxonomy references, fixed values
    // and dates. This is to limit the number of queries to validate term
    // references.
    $values = [];
    $conditions = [];
    $operators = array_flip($this->advancedSearchOperators);
    foreach ($matches as $match) {
      $operator = $operators[$match[1]];
      $code = $match[2];
      $value = $match[3];

      if (isset($this->filters[$code])) {
        $filter = $this->filters[$code];
        $type = $filter['type'];

        $condition = [
          'code' => $code,
          'type' => $type,
          'field' => $filter['name'],
          'value' => $value,
          'operator' => $operator,
        ];

        switch ($type) {
          case 'reference':
            // For references, we'll validate later after accumulating them.
            $values[$type][$code][$value] = $value;
            break;

          case 'fixed':
            $values[$type][$code][$value] = $filter['values'][$value] ?? NULL;
            break;

          case 'date':
            $dates = $this->validateDateFilterValues($code, [$value]);
            $values[$type][$code][$value] = $dates;
            // We store the dates for convenience when working with the filter
            // values in other functions like advancedSearchToApiFilter().
            $condition['processed'] = $dates;
            break;
        }

        $conditions[] = $condition;
      }
    }

    // Load and validate the references.
    if (!empty($values['reference'])) {
      foreach ($values['reference'] as $code => $ids) {
        $values['reference'][$code] = $this->validateReferenceFilterValues($code, $ids);
      }
    }

    // Process the conditions, removing invalid ones.
    $previous = '';
    foreach ($conditions as $key => $condition) {
      $code = $condition['code'];
      $type = $condition['type'];

      // Skip invalid values.
      if (empty($values[$type][$code][$condition['value']])) {
        unset($conditions[$key]);
        continue;
      }

      // Prepare filter label.
      switch ($type) {
        case 'reference':
          $label = $values[$type][$code][$condition['value']]['name'];
          break;

        case 'fixed':
          $label = $values[$type][$code][$condition['value']];
          break;

        case 'date':
          $dates = $values[$type][$code][$condition['value']];
          if (!empty($dates['from']) && !empty($dates['to'])) {
            if ($dates['from'] == $dates['to']) {
              $label = $this->t('on @date', [
                '@date' => $dates['from']->format('Y/m/d'),
              ]);
            }
            else {
              $label = $this->t('@start to @end', [
                '@start' => $dates['from']->format('Y/m/d'),
                '@end' => $dates['to']->format('Y/m/d'),
              ]);
            }
          }
          elseif (!empty($dates['from'])) {
            $label = $this->t('after @date', [
              '@date' => $dates['from']->modify('-1 day')->format('Y/m/d'),
            ]);
          }
          elseif (!empty($dates['to'])) {
            $label = $this->t('before @date', [
              '@date' => $dates['to']->modify('+1 day')->format('Y/m/d'),
            ]);
          }
          break;
      }
      $condition['label'] = $label;

      // Fix the operator in case some invalid filters were discarded.
      $operator = $condition['operator'];
      if ($previous === '' && $operator !== 'with' && $operator !== 'without') {
        $operator = strpos($operator, 'without') !== FALSE ? 'without' : 'with';
      }
      elseif ($operator === 'and' && $previous === 'or') {
        $operator = 'and-with';
      }
      elseif ($operator === 'or' && $previous === 'and') {
        $operator = 'or-with';
      }
      $previous = $operator;
      $condition['operator'] = $operator;

      $conditions[$key] = $condition;
    }

    return $conditions;
  }

  /**
   * Validate reference filter values.
   *
   * @param string $code
   *   Filter code.
   * @param array $values
   *   Filter values.
   *
   * @return array
   *   Valid values with their ID, name and optional shortname.
   */
  public function validateReferenceFilterValues($code, array $values) {
    if (!isset($this->filters[$code]) || empty($values)) {
      return [];
    }

    // We are strict here and skip the entire filter if a value is not
    // numeric. That should not happen if the filter was generated from the
    // facets.
    foreach ($values as $value) {
      if (!is_numeric($value)) {
        return [];
      }
    }

    return static::loadReferenceValues($this->filters[$code], $values);
  }

  /**
   * Validate fixed filter values.
   *
   * @param string $code
   *   Filter code.
   * @param array $values
   *   Filter values.
   *
   * @return array
   *   Valid values with their id and name.
   */
  public function validateFixedFilterValues($code, array $values) {
    if (empty($values)) {
      return [];
    }
    return array_intersect_key($this->filters[$code]['values'], array_flip($values));
  }

  /**
   * Validate date filter values.
   *
   * @param string $code
   *   Filter code.
   * @param array $values
   *   Filter values.
   *
   * @return array
   *   Dates with a 'from' or a 'to' key or both.
   */
  public function validateDateFilterValues($code, array $values) {
    if (empty($values)) {
      return [];
    }
    // We only accept one range.
    $values = $values[0];

    $values = array_map(function ($value) {
      if (strlen($value) !== 8 || !ctype_digit($value)) {
        return NULL;
      }
      $date = date_create_immutable_from_format('Ymd|', $value, timezone_open('UTC'));
      return $date;
    }, explode('-', $values, 2));

    $dates = [];
    if (count($values) === 1) {
      if (!empty($values[0])) {
        $dates['from'] = $values[0];
        $dates['to'] = $values[0];
      }
    }
    else {
      // If the to date is before the from date, we inverse the dates.
      if (!empty($values[0]) && !empty($values[1]) && $values[1] < $values[0]) {
        $temp = $values[0];
        $values[0] = $values[1];
        $values[1] = $temp;
      }

      if (!empty($values[0])) {
        $dates['from'] = $values[0];
      }
      if (!empty($values[1])) {
        $dates['to'] = $values[1];
      }
    }

    return $dates;
  }

  /**
   * Get sanitized advanced search filter as string.
   *
   * @param array $selection
   *   Selected filters.
   *
   * @return string
   *   Advanced search filter as string.
   */
  public function getSanitiziedAdvancedSearchParameter(array $selection) {
    if (empty($selection)) {
      return '';
    }

    $result = '';
    $operators = $this->advancedSearchOperators;
    foreach ($selection as $condition) {
      $result .= $operators[$condition['operator']];
      $result .= $condition['code'] . $condition['value'];
    }
    return $result . ')';
  }

  /**
   * Convert advanced search filter to API filter.
   *
   * @return array
   *   API filter.
   */
  public function getApiFilter() {
    if (empty($this->data['selection'])) {
      return [];
    }

    $root = [
      'conditions' => [],
      'operator' => 'AND',
    ];

    // @todo There is a bug with the linter...
    // @see https://github.com/sirbrillig/phpcs-variable-analysis/issues/231
    // @codingStandardsIgnoreLine
    $filter = NULL;

    foreach ($this->data['selection'] as $data) {
      $operator = $data['operator'];
      $field = $this->filters[$data['code']]['field'];

      // Create API filter.
      if (strpos($operator, 'with') !== FALSE) {
        $newfilter = [
          'conditions' => [],
          'operator' => 'AND',
        ];

        if (strpos($operator, 'out') !== FALSE) {
          $newfilter['negate'] = TRUE;
        }

        // New nested conditional filter.
        $operator = strpos($operator, 'or') !== FALSE ? 'OR' : 'AND';
        if ($operator !== $root['operator']) {
          $root = [
            'conditions' => [$root],
            'operator' => $operator,
          ];
        }
        $root['conditions'][] = $newfilter;
        $filter = &$root['conditions'][count($root['conditions']) - 1];
      }

      // Add value.
      if (isset($filter)) {
        $value = $data['value'];
        if ($data['type'] === 'date') {
          // Use the stored processed dates.
          // @see parseAdvancedSearchParameter().
          $value = $this->getApiDateFilterValue($data['processed']);
        }

        $filter['operator'] = $operator;
        $filter['conditions'][] = [
          'field' => $field,
          'value' => $value,
        ];
      }
    }

    return !empty($root['conditions']) ? $root : [];
  }

  /**
   * Get an API date condition.
   *
   * @param array $values
   *   Values with the `from` and `to` dates.
   *
   * @return array
   *   API date filter.
   */
  public function getApiDateFilterValue(array $values) {
    $filter = [];
    if (!empty($values['from'])) {
      $filter['from'] = $values['from']->format('c');
    }
    if (!empty($values['to'])) {
      // Same day for both from and to dates, we add 1 day to the to date
      // so that we cover the entire day.
      // We are not using a strict equality because they can be 2 different
      // DateTimeImmutable objects representing the same date.
      if (!empty($values['from']) && $values['to'] == $values['from']) {
        $filter['to'] = $values['to']->modify('+1 day')->format('c');
      }
      else {
        $filter['to'] = $values['to']->format('c');
      }
    }
    return $filter;
  }

  /**
   * Get the vocabulary for the given term id.
   *
   * @param int $id
   *   Term id.
   *
   * @return string
   *   Vocabulary.
   */
  public static function getVocabularyFromTermId($id) {
    $result = \Drupal::database()
      ->select('taxonomy_term_data', 'td')
      ->fields('td', ['vid'])
      ->condition('td.tid', $id)
      ->range(0, 1)
      ->execute();

    return !empty($result) ? $result->fetchField() : '';
  }

  /**
   * Load reference values.
   *
   * @param array $filter
   *   Filter information.
   * @param array $values
   *   Filter values.
   * @param string $sort
   *   Property to use for sorting the terms.
   *
   * @return array
   *   Valid values with their ID, name and optional shortname.
   *
   * @todo maybe load the entities so that we can get the translations?
   */
  public static function loadReferenceValues(array $filter, array $values = [], $sort = 'name') {
    if (!isset($filter['vocabulary'])) {
      return [];
    }
    $vocabulary = $filter['vocabulary'];

    // Get the current and default language codes.
    $language_manager = \Drupal::languageManager();
    $current_langcode = $language_manager->getCurrentLanguage()->getId();
    $default_langcode = $language_manager->getDefaultLanguage()->getId();
    $langcodes = array_unique([$current_langcode, $default_langcode]);

    // Query to return the id, name and optionally shortname of the terms
    // for the filter's vocabulary.
    $query = \Drupal::database()
      ->select('taxonomy_term_field_data', 'td')
      ->fields('td', ['tid', 'name', 'langcode'])
      ->condition('td.vid', $vocabulary)
      // Only return publicly accessible terms.
      ->condition('td.status', 1)
      // Return the terms in the current and default languages.
      ->condition('td.langcode', $langcodes, 'IN');

    // Exclude some terms.
    if (!empty($filter['exclude'])) {
      $query->condition('td.tid', $filter['exclude'], 'NOT IN');
      $values = array_diff($values, $filter['exclude']);
    }

    // Filter by the given values if any.
    if (!empty($values)) {
      $query->condition('td.tid', $values, 'IN');
    }

    // Add the shortname if indicated.
    if (!empty($filter['shortname'])) {
      $query->leftJoin('taxonomy_term__field_shortname', 'fs', "fs.entity_id = td.tid AND fs.langcode = td.langcode");
      $query->addField('fs', 'field_shortname_value', 'shortname');
    }

    $records = $query->execute();
    if (empty($records)) {
      return [];
    }

    $terms = [];
    foreach ($records as $record) {
      $id = (int) $record->tid;
      // The record in the current language takes precedence over the default
      // language verion.
      if ($record->langcode === $current_langcode || !isset($terms[$id])) {
        $term = [
          'id' => $id,
          'name' => $record->name,
        ];
        if (!empty($record->shortname)) {
          $term['shortname'] = $record->shortname;
        }
        $terms[$id] = $term;
      }
    }

    // Sort the terms by id or name.
    if ($sort === 'id') {
      ksort($terms);
    }
    else {
      LocalizationHelper::collatedAsort($terms, 'name', $current_langcode);
    }

    return $terms;
  }

  /**
   * Get the API suggest URL for the resource.
   *
   * @param string $resource
   *   API resource.
   * @param array $parameters
   *   Extra parameters to pass to the API.
   *
   * @return string
   *   API suggest URL.
   */
  public static function getApiSuggestUrl($resource, array $parameters = []) {
    return \Drupal::service('reliefweb_api.client')
      ->buildApiUrl($resource, $parameters);
  }

}
