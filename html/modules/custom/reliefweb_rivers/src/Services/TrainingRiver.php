<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve training resource for the training rivers.
 */
class TrainingRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'training';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'training';

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'training';

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Training');
  }

  /**
   * {@inheritdoc}
   */
  public function getViews() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getApiPayload($view = '') {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiData(array $api_data, $view = '') {
    // Retrieve the API data (with backward compatibility).
    $items = $api_data['items'] ?? $api_data['data'] ?? [];

    // Parse the entities retrieved from the API.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];

      // Title.
      $title = $fields['title'];

      // Summary.
      $summary = '';
      if (!empty($fields['body-html'])) {
        $body = HtmlSanitizer::sanitize($fields['body-html']);
        $summary = HtmlSummarizer::summarize($body, 200);
      }

      // Tags (countries, sources etc.).
      $tags = [];
      $tag_base_url = '/' . $this->river . '?advanced-search=';

      // Countries.
      $countries = [];
      foreach ($fields['country'] ?? [] as $country) {
        $countries[] = [
          'name' => $country['name'],
          'shortname' => $country['shortname'] ?? $country['name'],
          'code' => $country['iso3'] ?? '',
          'url' => UrlHelper::encodeUrl($tag_base_url . '(C' . $country['id'] . ')'),
          'main' => !empty($country['primary']),
        ];
      }
      $tags['country'] = $countries;

      // Sources.
      $sources = [];
      foreach ($fields['source'] ?? [] as $source) {
        $sources[] = [
          'name' => $source['name'],
          'shortname' => $source['shortname'] ?? $source['name'],
          'url' => UrlHelper::encodeUrl($tag_base_url . '(S' . $source['id'] . ')'),
        ];
      }
      $tags['source'] = $sources;

      // Languages.
      $languages = [];
      foreach ($fields['language'] ?? [] as $language) {
        $languages[] = [
          'name' => $language['name'],
          'code' => $language['code'],
        ];
      }
      $tags['language'] = $languages;

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
        'title' => $title,
        'summary' => $summary,
        'tags' => $tags,
      ];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::encodeUrl('node/' . $item['id'], FALSE);
      }

      if (isset($fields['date']['created'])) {
        $data['posted'] = static::createDate($fields['date']['created']);
      }
      if (isset($fields['date']['start'])) {
        $data['start'] = static::createDate($fields['date']['start']);
      }
      if (isset($fields['date']['end'])) {
        $data['end'] = static::createDate($fields['date']['end']);
      }
      if (isset($fields['date']['registration'])) {
        $data['registration'] = static::createDate($fields['date']['registration']);
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

}
