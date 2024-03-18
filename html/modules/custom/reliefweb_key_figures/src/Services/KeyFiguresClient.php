<?php

namespace Drupal\reliefweb_key_figures\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
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
    return [];
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
    return NULL;
  }

}
