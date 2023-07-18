<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_disaster_map\DisasterMapService;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve disaster resource for the disaster rivers.
 *
 * @todo add disaster map.
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
  protected $entityTypeId = 'taxomomy_term';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'disaster';

  /**
   * {@inheritdoc}
   */
  public function getDefaultPageTitle() {
    return $this->t('Disasters');
  }

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    $content = parent::getPageContent();

    // Add the map with the alert and ongoing disasters if the river is
    // not filtered.
    $search = $this->getSearch();
    $filter_selection = $this->getAdvancedSearch()->getSelection();
    if (empty($search) && empty($filter_selection)) {
      $content['#pre_content'] = DisasterMapService::getAlertAndOngoingDisasterMap();
    }

    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function getViews() {
    return [
      'all' => $this->t('All Disasters'),
      'ongoing' => $this->t('Alert / Ongoing'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      'C' => [
        'name' => $this->t('Country'),
        'shortname' => TRUE,
        'type' => 'reference',
        'vocabulary' => 'country',
        'field' => 'country.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a country'),
          'resource' => 'countries',
        ],
      ],
      'TY' => [
        'name' => $this->t('Disaster type'),
        'type' => 'reference',
        'vocabulary' => 'disaster_type',
        'field' => 'type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a disaster type'),
        ],
      ],
      'ST' => [
        'name' => $this->t('Status'),
        'type' => 'fixed',
        'values' => [
          'alert' => $this->t('Alert'),
          // The legacy status for "ongoing" is "current".
          // @todo change to "ongoing" once the conversion is handled by the
          // API itself.
          'current' => $this->t('Ongoing'),
          'past' => $this->t('Past disaster'),
        ],
        'field' => 'status',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a status'),
        ],
      ],
      'DA' => [
        'name' => $this->t('Date'),
        'type' => 'date',
        'field' => 'date.event',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select disaster date'),
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterSample() {
    return $this->t('(Country, type, status...)');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiPayload($view = '') {
    $payload = [
      'query' => [
        'fields' => [
          'name^20',
          'country.name^50',
          'country.shortname^50',
          'type.name^100',
          'status^100',
        ],
        'operator' => 'AND',
      ],
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'name',
          'status',
          'country.id',
          'country.iso3',
          'country.name',
          'country.shortname',
          'country.primary',
          'type.id',
          'type.name',
          'type.code',
          'type.primary',
          'primary_type.code',
          'date.event',
        ],
      ],
      // To ensure consistent order for disaster with the same event day,
      // we also sort those by ID to have the most recents first.
      'sort' => ['date.event:desc', 'id:desc'],
    ];

    // Handle the filtered selection (view).
    switch ($view) {
      case 'ongoing':
        $payload['filter'] = [
          'field' => 'status',
          'value' => ['alert', 'current', 'ongoing'],
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
      $status_label = reliefweb_moderation_get_moderation_status_label('disaster', $status);

      // Tags (countries, sources etc.).
      $tags = [];

      // Countries.
      $countries = [];
      foreach ($fields['country'] ?? [] as $country) {
        $countries[] = [
          'id' => $country['id'],
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
          ], $type['name'], TRUE),
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
        'status_label' => $status_label,
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

      // Disaster location (= centroid coordinates of the primary country).
      if (!empty($fields['primary_country']['location'])) {
        $data['location'] = $fields['primary_country']['location'];
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
  public function getApiPayloadForRss($view = '') {
    $payload = $this->getApiPayload($view);
    $payload['fields']['include'][] = 'glide';
    $payload['fields']['include'][] = 'profile.overview-html';
    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiDataForRss(array $data, $view = '') {
    $items = $data['items'] ?? $data['data'] ?? [];

    // Parse the entities retrieved from the API.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
      ];

      // Title.
      $data['title'] = $fields['name'];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::getAliasFromPath('/taxonomy/term/' . $item['id']);
      }

      // Dates.
      $data['date'] = static::createDate($fields['date']['event']);

      // Body and how to apply.
      $data['body'] = $fields['profile']['overview-html'] ?? '';

      // Categories.
      $categories = [
        'country' => [
          $this->t('Affected country'),
          $this->t('Affected countries'),
        ],
        'disaster_type' => [
          $this->t('Disaster type'),
          $this->t('Disaster types'),
        ],
      ];
      $inline = ['country' => TRUE];
      foreach ($categories as $category => $labels) {
        if (!empty($fields[$category])) {
          $data['categories'][$category] = [
            'label' => $labels[count($fields[$category]) > 1 ? 1 : 0],
            'values' => array_map(function ($term) {
              return $term['name'];
            }, $fields[$category]),
            'inline' => isset($inline[$category]),
          ];
        }
      }
      if (!empty($fields['glide'])) {
        $data['categories']['glide'] = [
          'label' => $this->t('Glide'),
          'values' => [$fields['glide']],
          'inline' => TRUE,
        ];
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
  public function requestApi(array $payload) {
    if (!empty($payload['query']['value'])) {
      // Tiny hack to make searching by "ongoing" status possible as for
      // legacy reasons the actual status is "current".
      $payload['query']['value'] = str_replace('ongoing', 'current', $payload['query']['value']);
    }

    return parent::requestApi($payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRiverDescription() {
    return $this->t('ReliefWeb disaster pages provide an overview of the situation and situation reports, news and press releases, assessments, evaluations, infographics and maps. Browse our list of natural disasters with humanitarian impact from 1981 until today.');
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverCacheTags() {
    return [
      $this->getEntityTypeId() . '_list:' . $this->getBundle(),
      'taxonomy_term_list:country',
      'taxonomy_term_list:disaster_type',
    ];
  }

}
