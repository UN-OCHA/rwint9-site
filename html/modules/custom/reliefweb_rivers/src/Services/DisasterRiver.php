<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve disaster resource for the disaster rivers.
 */
class DisasterRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'disasters';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'disasters';

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'taxomomy_term';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'disaster';

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Disasters');
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
      $title = $fields['name'];

      // Status.
      $status = $fields['status'] === 'current' ? 'ongoing' : $fields['status'];

      // Tags (countries, sources etc.).
      $tags = [];

      // Countries.
      $countries = [];
      foreach ($fields['country'] ?? [] as $country) {
        $countries[] = [
          'name' => $country['name'],
          'shortname' => $country['shortname'] ?? $country['name'],
          'code' => $country['iso3'] ?? '',
          'url' => UrlHelper::getAliasFromPath('/taxonomy/term/' . $country['id']),
          'main' => !empty($country['primary']),
        ];
      }
      $tags['country'] = $countries;

      // Disaster types.
      $types = [];
      foreach ($fields['type'] ?? [] as $type) {
        $types[] = [
          'name' => $type['name'],
          'url' => static::getRiverUrl($this->bundle, [
            'advanced-search' => '(TY' . $type['id'] . ')',
          ]),
          'main' => !empty($country['primary']),
        ];
      }
      $tags['type'] = $types;

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
        // Primary disaster type.
        'type' => $fields['primary_type']['code'] ?? '',
        'title' => $title,
        'status' => $status,
        'tags' => $tags,
      ];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::getAliasFromPath('/taxonomy/term/' . $item['id']);
      }

      // Summary.
      if (!empty($fields['profile']['overview-html'])) {
        $overview = HtmlSanitizer::sanitize($fields['profile']['overview-html']);
        $data['summary'] = HtmlSummarizer::summarize($overview, 260);
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

}
