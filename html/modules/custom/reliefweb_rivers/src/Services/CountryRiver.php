<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve country resource for the country river.
 */
class CountryRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'countries';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'countries';

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'taxomomy_term';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'country';

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Countries');
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

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
        'title' => $title,
        'status' => $status,
        'iso3' => $fields['iso3'],
        'shortname' => $fields['shortname'] ?? $fields['name'],
        'location' => $fields['location'] ?? [],
        'tags' => [],
      ];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::encodeUrl('taxonomy/term/' . $item['id'], FALSE);
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

}
