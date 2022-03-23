<?php

namespace Drupal\reliefweb_dsr\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Digital Situation Report client service class.
 */
class DSRClient {

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
   * Constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The default cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(CacheBackendInterface $cache_backend, ConfigFactoryInterface $config_factory, TimeInterface $time, ClientInterface $http_client, LanguageManagerInterface $language_manager, LoggerChannelFactoryInterface $logger_factory, TranslationInterface $string_translation) {
    $this->cache = $cache_backend;
    $this->config = $config_factory->get('reliefweb_dsr.settings');
    $this->time = $time;
    $this->httpClient = $http_client;
    $this->languageManager = $language_manager;
    $this->stringTranslation = $string_translation;
    $this->logger = $logger_factory->get('reliefweb_dsr');
  }

  /**
   * Get the render array for a Digital Situation Report (DSR) highlights.
   *
   * @param string $iso3
   *   ISO3 code of the country for which to retrieve the DSR.
   * @param bool $ongoing
   *   Whether there is an ongoing humanitarian situation in the country or not.
   *   This is used to determine the duration of the cached data. When TRUE,
   *   the data is refreshed more often.
   *
   * @return array
   *   Return the render array for the digital situation report for the country
   *   (empty if none was found). This render array has the following keys:
   *   - #theme: reliefweb_digital_situation_report
   *   - #langcode: language iso 2 code
   *   - #title: title
   *   - #date: date of the latest update (\DateTime object)
   *   - #illustration: if defined, array with an image url, alt and description
   *   - #highlights: if defined, list of key messages
   *   - #links: list of links to the DSR site, keyed by language.
   */
  public function getDigitalSitrepBuild($iso3, $ongoing = FALSE) {
    $sitrep = $this->getDigitalSitrep($iso3, $ongoing);
    if (empty($sitrep)) {
      return [];
    }

    // Create the render array.
    $build = ['#theme' => 'reliefweb_digital_situation_report'];
    foreach ($sitrep as $key => $value) {
      $build['#' . $key] = $value;
    }

    // Cache the render array with the minimum max age. The data retrieved from
    // the API may be stored in cache for a longer duration.
    $build['#cache']['max-age'] = $this->config->get('cache_lifetime', 5 * 60);

    return $build;
  }

  /**
   * Get the OCHA Digital Situation Report (DSR) highlights.
   *
   * @param string $iso3
   *   ISO3 code of the country for which to retrieve the DSR.
   * @param bool $ongoing
   *   Whether there is an ongoing humanitarian situation in the country or not.
   *   This is used to determine the duration of the cached data. When TRUE,
   *   the data is refreshed more often.
   *
   * @return array
   *   Return the render array for the digital situation report for the country
   *   (empty if none was found). This render array has the following keys:
   *   - #theme: reliefweb_digital_situation_report
   *   - #langcode: language iso 2 code
   *   - #title: title
   *   - #date: date of the latest update (\DateTime object)
   *   - #illustration: if defined, array with an image url, alt and description
   *   - #highlights: if defined, list of key messages
   *   - #links: list of links to the DSR site, keyed by language.
   */
  public function getDigitalSitrep($iso3, $ongoing = FALSE) {
    // Contentful API information to get the Digital Situation reports.
    $token = $this->config->get('ctf_cda_access_token');
    $dsr_url = $this->config->get('ctf_dsr_url');
    $last_update_skip = $this->config->get('last_update_skip', 30);

    if (empty($token) || empty($dsr_url) || empty($iso3)) {
      return [];
    }

    // Get the current language or default to English.
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    // Cache information.
    $cache_id = 'reliefweb_dsr:dsr:' . $iso3 . ':' . $langcode;
    $cache_lifetime = $this->config->get('cache_lifetime', 5 * 60);
    $request_time = $this->time->getRequestTime();

    // Attempt to get the sitrep from the cache.
    $cache = $this->cache->get($cache_id);
    if (isset($cache->data, $cache->expire) && $cache->expire > $request_time) {
      return $cache->data;
    }

    $query = [
      'access_token' => $token,
      'fields.countryCode' => $iso3,
      'content_type' => 'sitrep',
      'select' => implode(',', [
        'fields.dateUpdated',
        'fields.title',
        'fields.language',
        'fields.slug',
        'fields.keyMessages',
        'fields.keyMessagesImage',
      ]),
    ];

    try {
      $response = $this->httpClient->get($dsr_url, [
        'timeout' => 2,
        'query' => $query,
      ]);
    }
    catch (GuzzleException $exception) {
      return [];
    }

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
          $this->logger->notice('Unable to decode DSR data for country @iso3', [
            '@iso3' => $iso3,
          ]);
        }
      }
    }
    else {
      $this->logger->notice('Unable to retrieve DSR data for country @iso3 - response code: @code', [
        '@iso3' => $iso3,
        '@code' => $response->getStatusCode(),
      ]);
    }

    // Parse the data.
    $sitrep = [];
    if (!empty($data) && is_array($data)) {
      $sitreps = $this->parseDigitalSitreps($data);
      if (!empty($sitreps)) {
        // Extract the links to the different versions of the sitrep.
        $links = [];
        foreach ($sitreps as $language => $version) {
          $links[$language] = [
            'url' => $version['url'],
            'label' => $version['language'],
          ];
        }

        // Filter out sitreps which haven't been updated for a while.
        $sitreps = array_filter($sitreps, function ($sitrep) use ($last_update_skip) {
          return $sitrep['updated'] <= $last_update_skip;
        });

        // If no version in current language, English has priority.
        $sitrep = $sitreps[$langcode] ?? $sitreps['en'] ?? $sitreps['fr'] ?? $sitreps['es'] ?? [];

        // If there is no sitrep content, we'll only display the links.
        if (empty($sitrep)) {
          $sitrep = [
            'title' => $this->t('Situation Report'),
          ];
        }

        $sitrep['links'] = $links;
      }
    }

    // If there is no sitrep, then we cache the data for a longer duration.
    if ($response->getStatusCode() === 200 && empty($sitrep)) {
      // If the country doesn't have an ongoing humanitarian situation we
      // cache the data for a much longer period (default: 3h) as it is less
      // likely there will be a DSR added for the country.
      if (!$ongoing) {
        $cache_lifetime *= 36;
      }
      else {
        $cache_lifetime *= 6;
      }
    }
    // If there was an error while fetching the content, we cache the data
    // for a bit longer as well.
    elseif ($response->getStatusCode() != 200) {
      $cache_lifetime *= 6;
    }

    $cache_expiration = $this->time->getRequestTime() + $cache_lifetime;
    $this->cache->set($cache_id, $sitrep, $cache_expiration);

    return $sitrep;
  }

  /**
   * Parse the digital situation reports data from the contentful API.
   *
   * @param array $data
   *   Data from the Contentful API.
   *
   * @return array
   *   Associative array with the digital situation report data.
   */
  protected function parseDigitalSitreps(array $data) {
    $ocha_dsr_url = $this->config->get('ocha_dsr_url');
    if (empty($ocha_dsr_url)) {
      return [];
    }

    $now = date_create();
    $sitreps = [];

    // Ensure we have the proper data to continue.
    if (empty($data['includes']['Entry']) || !is_array($data['includes']['Entry'])) {
      return [];
    }
    if (empty($data['includes']['Asset']) || !is_array($data['includes']['Asset'])) {
      return [];
    }
    if (empty($data['items']) || !is_array($data['items'])) {
      return [];
    }

    // Parse key messages.
    $entries = [];
    foreach ($data['includes']['Entry'] as $entry) {
      if (empty($entry['sys']['id'])) {
        continue;
      }
      // Validate the key message, ensuring it's not empty.
      if (empty($entry['fields']['keyMessage']) || ctype_space($entry['fields']['keyMessage'])) {
        continue;
      }
      $entries[$entry['sys']['id']] = trim($entry['fields']['keyMessage']);
    }

    // Parse highlights image.
    $assets = [];
    foreach ($data['includes']['Asset'] as $asset) {
      if (empty($asset['sys']['id'])) {
        continue;
      }
      // Validate the imag url.
      if (empty($asset['fields']['file']['url'])) {
        continue;
      }
      // We add a protocol if missing, assuming https is available.
      $url = preg_replace('#^//#', 'https://', $asset['fields']['file']['url']);
      if (!filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      $assets[$asset['sys']['id']] = [
        'url' => $url,
        'alt' => trim($asset['fields']['title'] ?? ''),
        'description' => trim($asset['fields']['description'] ?? ''),
      ];
    }

    // Parse the situation reports.
    foreach ($data['items'] as $item) {
      if (!isset($item['fields'])) {
        continue;
      }
      $fields = $item['fields'];
      // Validate the title (country name), ensuring it's not empty.
      if (empty($fields['title']) || ctype_space($fields['title'])) {
        continue;
      }
      // Validate the language, ensuring it's not empty.
      if (empty($fields['language']) || ctype_space($fields['language'])) {
        continue;
      }
      $langcode = strtolower(trim($fields['language']));
      $language = $this->getDigitalSitrepLanguage($langcode);
      if (empty($language)) {
        continue;
      }

      // Validate the slug (to build url), ensuring it's not empty.
      if (empty($fields['slug']) || ctype_space($fields['slug'])) {
        continue;
      }
      // Validate the update date, ensuring it's a valid date.
      if (empty($fields['dateUpdated'])) {
        continue;
      }
      // @todo Confirm that it's correct to assume the time is UTC.
      $date = date_create($fields['dateUpdated'], new \DateTimeZone('UTC'));
      // Skip if the date is invalid.
      if ($date === FALSE) {
        continue;
      }
      // Ensure there are key messages.
      if (empty($fields['keyMessages']) || !is_array($fields['keyMessages'])) {
        continue;
      }
      $highlights = [];
      foreach ($fields['keyMessages'] as $entry) {
        if (isset($entry['sys']['id'], $entries[$entry['sys']['id']])) {
          $highlights[] = $entries[$entry['sys']['id']];
        }
      }
      if (empty($highlights)) {
        continue;
      }

      $image = [];
      if (isset($fields['keyMessagesImage']['sys']['id'], $assets[$fields['keyMessagesImage']['sys']['id']])) {
        $image = $assets[$fields['keyMessagesImage']['sys']['id']];
      }

      $sitreps[$langcode] = [
        'title' => $this->getDigitalSitrepTitle($langcode),
        'date' => $date,
        // Number of days since the last update.
        'updated' => date_diff($now, $date, TRUE)->days,
        'url' => $ocha_dsr_url . '/' . $langcode . '/country/' . $fields['slug'],
        'langcode' => $langcode,
        'language' => $language,
        'highlights' => $highlights,
      ];

      // DSR-360 Disable display of the DSR image to avoid excessive bandwidth
      // usage. This can be re-enabled via a variable.
      if ($this->config->get('show_illustration') === TRUE) {
        $sitreps[$langcode]['illustration'] = $image;
      }
    }

    return $sitreps;
  }

  /**
   * Get the language name from a language code.
   *
   * @param string $language
   *   Language code.
   *
   * @return string
   *   Language name.
   */
  protected function getDigitalSitrepLanguage($language) {
    // Unfortunately this is not exposed in the API so we hardcode the
    // languages.
    // @see https://github.com/UN-OCHA/reports-site/tree/dev/locales
    $languages = [
      'ar' => 'عربي',
      'en' => 'English',
      'es' => 'Español',
      'fr' => 'Français',
      'ru' => 'Русский',
      'uk' => 'Українська',
    ];
    return $languages[$language] ?? '';
  }

  /**
   * Get the digital situation report (DSR) title based on the language.
   *
   * @param string $language
   *   ISO2 language code.
   *
   * @return string
   *   Localized title.
   */
  protected function getDigitalSitrepTitle($language) {
    $titles = [
      'ar' => 'تقرير عن الوضع أبرز الأحداث',
      'en' => 'Situation Report - Highlights',
      'fr' => 'Rapport de situation - Faits saillants',
      'sp' => 'Informe de situación - Destacados',
      'ru' => 'Оперативная сводка, Главное',
      'uk' => 'Оперативне зведення, Головне',
    ];

    // Default to English if the language is not handled.
    return $titles[$language] ?? $titles['en'];
  }

}
