<?php

namespace Drupal\reliefweb_key_figures\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ReliefWeb Key Figures client service class.
 */
class KeyFiguresClient {

  use StringTranslationTrait;

  /**
   * The default cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The default cache backend.
   * @param Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(CacheBackendInterface $cache_backend, ConfigFactoryInterface $config_factory, TimeInterface $time, ClientInterface $http_client, LanguageManagerInterface $language_manager, LoggerChannelFactoryInterface $logger_factory, RequestStack $request_stack, TranslationInterface $string_translation) {
    $this->cache = $cache_backend;
    $this->config = $config_factory->get('reliefweb_key_figures.settings');
    $this->time = $time;
    $this->httpClient = $http_client;
    $this->languageManager = $language_manager;
    $this->requestStack = $request_stack;
    $this->stringTranslation = $string_translation;
    $this->logger = $logger_factory->get('reliefweb_key_figures');
  }

  /**
   * Get the render array with the ReliefWeb key figures for the country.
   *
   * @param string $iso3
   *   ISO3 code of the country for which to retrieve the key figures.
   * @param string $country
   *   Country name.
   *
   * @return array
   *   Return the render array for the key figures for the country
   *   (empty if none was found). This render array has the following keys:
   *   - #theme: reliefweb_key_figures
   *   - #langcode: language iso 2 code
   *   - #country: name of the country the figures are for
   *   - #figures: list of figures. Each figure has the following properties:
   *     - status: standard or recent
   *     - name: figure label
   *     - value: figure value (number)
   *     - trand: if defined, has a message and since properties
   *     - sparkline: if  defined, has a list of points
   *     - date: last update time
   *     - updated: formatted relative update date.
   *     - url: URL to the report the figures came from
   *     - source: short name of the source
   *   - #more: indicates if there are more figures that can be shown.
   *   - #dataset: if defined, has a url and title properties
   */
  public function getKeyFiguresBuild($iso3, $country) {
    $data = $this->getKeyFigures($iso3, $country);

    // Create the render array.
    $build = [];
    if (!empty($data['figures'])) {
      $build['#theme'] = 'reliefweb_key_figures';

      // Limit the number of figures based on the "figures" query parameter.
      $figures_parameter = $this->requestStack->getCurrentRequest()->query->get('figures');
      $count = count($data['figures']);
      $limit = $figures_parameter === 'all' ? $count : 6;
      $data['figures'] = array_slice($data['figures'], 0, $limit);

      foreach ($data as $key => $value) {
        $build['#' . $key] = $value;
      }

      $build['#more'] = $count > $limit;
      $build['#cache']['contexts'][] = 'url.query_args:figures';
    }

    // Cache the render array with the minimum max age. The data retrieved from
    // the API may be stored in cache for a longer duration.
    $build['#cache']['max-age'] = $this->config->get('cache_lifetime', 5 * 60);

    return $build;
  }

  /**
   * Get the ReliefWeb key figures for the country.
   *
   * @param string $iso3
   *   ISO3 code of the country for which to retrieve the key figures.
   * @param string $country
   *   Country name.
   *
   * @return array
   *   Return an associative array with the key figures for the country
   *   (empty if none was found). This render array has the following keys:
   *   - langcode: language iso 2 code
   *   - country: name of the country the figures are for
   *   - figures: list of figures. Each figure has the following properties:
   *     - status: standard or recent
   *     - name: figure label
   *     - value: figure value (number)
   *     - trand: if defined, has a message and since properties
   *     - sparkline: if  defined, has a list of points
   *     - date: last update time
   *     - updated: formatted relative update date.
   *     - url: URL to the report the figures came from
   *     - source: short name of the source
   *   - dataset: if defined, has a url and title properties
   */
  public function getKeyFigures($iso3, $country) {
    $api_url = $this->config->get('api_url');
    if (empty($api_url) || empty($iso3)) {
      return NULL;
    }

    // Get the current language or default to English.
    // Currently it's not used but there may be figures in another language
    // at some point.
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    // Cache information.
    $cache_id = 'reliefweb_key_figues:figures:' . $iso3 . ':' . $langcode;
    $cache_lifetime = $this->config->get('cache_lifetime', 5 * 60);
    $request_time = $this->time->getRequestTime();

    // Attempt to get the sitrep from the cache.
    $cache = $this->cache->get($cache_id);
    if (isset($cache->data, $cache->expire) && $cache->expire > $request_time) {
      return $cache->data;
    }

    // Retrieve the key figures data for the country.
    $api_url .= strtoupper($iso3) . '/figures.json';
    $response = $this->httpClient->get($api_url, [
      'timeout' => 2,
    ]);

    // Decode the JSON response.
    $data = NULL;
    if ($response->getStatusCode() === 200) {
      $body = (string) $response->getBody();
      if (!empty($body)) {
        // Decode the data, skip if invalid.
        try {
          $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
        }
        catch (\Exception $exception) {
          $data = NULL;
          $this->logger->notice('Unable to decode Key Figures data for country @iso3', [
            '@iso3' => $iso3,
          ]);
        }
      }
    }
    else {
      $this->logger->notice('Unable to retrieve Key Figures data for country @iso3 - response code: @code', [
        '@iso3' => $iso3,
        '@code' => $response->getStatusCode(),
      ]);
    }

    // Parse the data.
    $figures = [];
    if (!empty($data)) {
      $figures = $this->parseKeyFigures($data);

      // Add the trend and sparkline.
      foreach ($figures as $index => $figure) {
        $figure['trend'] = $this->getKeyFigureTrend($figure['values']);
        $figure['sparkline'] = $this->getKeyFigureSparkline($figure['values']);
        $figures[$index] = $figure;
      }

      $figures = [
        'langcode' => $langcode,
        'country' => $country,
        'figures' => $figures,
      ];

      // HDX dataset information.
      $hdx_url = $this->config->get('hdx_url');
      if (!empty($hdx_url)) {
        $dataset = [
          'url' => $hdx_url,
          'title' => $this->t('View full dataset on HDX'),
        ];
        $figures['dataset'] = $dataset;
      }
    }

    $cache_expiration = $this->time->getRequestTime() + $cache_lifetime;
    $this->cache->set($cache_id, $figures, $cache_expiration);

    return $figures;
  }

  /**
   * Parse the Key figures data, validating and sorting the figures.
   *
   * @param array $figures
   *   Figures data from the API.
   *
   * @return array
   *   Array of figures data prepared for display perserving the order but
   *   putting the "recent" ones at the top. Each figure contains the title,
   *   figure, formatted update date, source with a url to the latest source
   *   document and the value history to build the sparkline and the trend.
   */
  protected function parseKeyFigures(array $figures) {
    // Maximum number of days since the last update to still consider the
    // figure as recent.
    $number_of_days = 7;
    $now = new \DateTime();
    $recent = [];
    $standard = [];
    $options = ['options' => ['min_range' => 0]];

    foreach ($figures as $item) {
      // Validate url.
      if (empty($item['url']) || !filter_var($item['url'], FILTER_VALIDATE_URL)) {
        continue;
      }

      // Validate name.
      if (empty($item['name']) || ctype_space($item['name'])) {
        continue;
      }
      $item['name'] = trim($item['name']);

      // Validate value (integer > 0).
      // Currently, the key figures are for population in need etc. so there is
      // no interest in showing a card with a value of '0' so we skip it.
      if (empty($item['value']) || !filter_var($item['value'], FILTER_VALIDATE_INT, $options)) {
        continue;
      }
      $item['value'] = (int) $item['value'];

      // Validate date.
      $item['date'] = !empty($item['date']) ? date_create($item['date']) : FALSE;
      if ($item['date'] === FALSE) {
        continue;
      }

      // Validate source.
      if (empty($item['source']) || ctype_space($item['source'])) {
        continue;
      }
      $item['source'] = trim($item['source']);

      // Validate list of past figures.
      if (empty($item['values']) || !is_array($item['values'])) {
        continue;
      }

      // Update the url to point to the main site.
      // @todo check if the report exists and skip otherwise ?
      $pattern = '#^https?://(?:m.)?reliefweb.int/(?:node|report)/(\d+)$#';
      if (preg_match($pattern, $item['url'], $matches) === 1) {
        $item['url'] = Url::fromRoute('entity.node.canonical', [
          'node' => $matches[1],
        ], [
          'absolute' => TRUE,
        ])->toString();
      }

      // Sanitize and sort the past figures for the sparkline and trend.
      $values = [];
      foreach ($item['values'] as $value) {
        // A value of '0' is acceptable to construct the sparkline as opposed
        // to the main figure so we don't use 'empty()'.
        if (!isset($value['value'], $value['date']) || !filter_var($value['value'], FILTER_VALIDATE_INT, $options)) {
          continue;
        }
        $date = date_create($value['date']);
        // Skip if the date is invalid.
        if ($date === FALSE) {
          continue;
        }
        $iso = $date->format('c');
        // Skip if there is already a more recent value for the same date.
        if (!isset($values[$iso])) {
          $values[$iso] = [
            'value' => (int) $value['value'],
            'date' => $date,
          ];
        }
      }
      // Sort the past values by newest first.
      krsort($values);
      $item['values'] = $values;

      // Set the figure status and format its date.
      $item['status'] = 'standard';
      $days_ago = $item['date']->diff($now)->days;

      if ($days_ago < $number_of_days) {
        $item['status'] = 'recent';
        if ($days_ago === 0) {
          $item['updated'] = $this->t('Updated today');
        }
        elseif ($days_ago === 1) {
          $item['updated'] = $this->t('Updated yesterday');
        }
        else {
          $item['updated'] = $this->t('Updated @days days ago', [
            '@days' => $days_ago,
          ]);
        }
        $recent[] = $item;
      }
      else {
        $item['updated'] = $this->t('Updated @date', [
          '@date' => $item['date']->format('j M Y'),
        ]);
        $standard[] = $item;
      }
    }

    // Preserve the figures order but put recently updated first.
    return array_merge($recent, $standard);
  }

  /**
   * Get the sparkline data for the given key figure history values.
   *
   * @param array $values
   *   Key figure history values.
   */
  protected function getKeyFigureSparkline(array $values) {
    if (empty($values)) {
      return NULL;
    }

    // Find max and min values.
    $numbers = array_column($values, 'value');
    $max = max($numbers);
    $min = min($numbers);

    // Skip if there was no change.
    if ($max === $min) {
      return NULL;
    }

    // The values are ordered by newest first. We retrieve the number of
    // days between the newest and oldest days for the x axis.
    $last = reset($values)['date'];
    $oldest = end($values)['date'];
    $span = $last->diff($oldest)->days;
    if ($span == 0) {
      return NULL;
    }

    // View box dimensions for the sparkline.
    $height = 40;
    $width = 120;

    // Calculate the coordinates of each value.
    $points = [];
    foreach ($values as $value) {
      $diff = $oldest->diff($value['date'])->days;
      $x = ($width / $span) * $diff;
      $y = $height - ((($value['value'] - $min) / ($max - $min)) * $height);
      $points[] = round($x, 2) . ',' . round($y, 2);
    }

    $sparkline = [
      'points' => $points,
    ];

    return $sparkline;
  }

  /**
   * Get the trend data for the given key figure history values.
   *
   * @param array $values
   *   Key figure history values.
   */
  protected function getKeyFigureTrend(array $values) {
    if (count($values) < 2) {
      return NULL;
    }

    // The values are ordered by newest first. We want the 2 most recent values
    // to compute the trend.
    $first = reset($values);
    $second = next($values);

    if ($second['value'] === 0) {
      $percentage = 100;
    }
    else {
      $percentage = (int) round((1 - $first['value'] / $second['value']) * 100);
    }

    if ($percentage === 0) {
      $message = $this->t('No change');
    }
    elseif ($percentage < 0) {
      $message = $this->t('@percentage% increase', [
        '@percentage' => abs($percentage),
      ]);
    }
    else {
      $message = $this->t('@percentage% decrease', [
        '@percentage' => abs($percentage),
      ]);
    }

    $trend = [
      'message' => $message,
      'since' => $this->t('since @date', [
        '@date' => $second['date']->format('j M Y'),
      ]),
    ];

    return $trend;
  }

}
