<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
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
  protected $entityTypeId = 'taxomomy_term';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'country';

  /**
   * {@inheritdoc}
   */
  protected $limit = 1000;

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Countries');
  }

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    $content = parent::getPageContent();
    unset($content['#search']);

    // Generate the list of letters for the letter navigation.
    $letters = [];
    foreach ($content['#content']['#groups'] as $letter => $countries) {
      $letters[$letter] = [
        'label' => $letter,
        // Internal link. See 'reliefweb-rivers-country-list.html.twig'.
        'url' => '#group-' . $letter,
      ];
    }
    $letters['all'] = [
      'label' => $this->t('All'),
      'url' => '#',
    ];

    $content['#letter_navigation'] = [
      '#theme' => 'reliefweb_rivers_letter_navigation',
      '#title' => $this->t('Jump to letter'),
      '#letters' => $letters,
    ];

    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverContent() {
    // Get the resources for the search query.
    $entities = $this->getApiData($this->limit);

    // Group the countries by first letter.
    $groups = [];
    foreach ($entities as $entity) {
      $letter = mb_strtoupper(mb_substr($entity['title'], 0, 1));

      $groups[$letter][] = $entity;
    }

    // Sort the countries by alpha.
    foreach ($groups as &$countries) {
      LocalizationHelper::collatedSort($countries, 'title');
    }

    // Sort the groups by alpha.
    LocalizationHelper::collatedKsort($groups);

    return [
      '#theme' => 'reliefweb_rivers_country_list',
      '#groups' => $groups,
      '#cache' => [
        'tags' => $this->getRiverCacheTags(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getViews() {
    return [
      'all' => $this->t('All Countries'),
      'ongoing' => $this->t('Humanitarian Situations'),
    ];
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
    $payload = [
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'name',
          'status',
          'iso3',
        ],
      ],
    ];

    // Handle the filtered selection (view).
    switch ($view) {
      case 'ongoing':
        $payload['filter'] = [
          'field' => 'status',
          'value' => 'current',
        ];
        break;
    }

    return $payload;
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
        $data['url'] = UrlHelper::getAliasFromPath('/taxonomy/term/' . $item['id']);
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverCacheTags() {
    return [
      $this->getEntityTypeId() . '_list:' . $this->getBundle(),
    ];
  }

}
