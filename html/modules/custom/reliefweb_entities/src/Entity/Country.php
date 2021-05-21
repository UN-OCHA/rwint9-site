<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\taxonomy\Entity\Term;

/**
 * Bundle class for country terms.
 */
class Country extends Term implements CountryInterface {

  /**
   * List of sections.
   *
   * @var array
   */
  private $sections;

  /**
   * API payloads for the different content sections.
   *
   * @var array
   */
  protected $payloads = [
    'reports' => [
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'date.created',
          'date.original',
          'country.id',
          'country.iso3',
          'country.name',
          'country.shortname',
          'country.primary',
          'source.id',
          'source.name',
          'source.shortname',
          'language.id',
          'language.name',
          'language.code',
          'format.name',
        ],
      ],
      'sort' => ['date.created:desc'],
    ],
    'disasters' => [
      'fields' => [
        'include' => [
          'id',
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
        ],
      ],
      'sort' => ['date.created:desc'],
    ],
    'jobs' => [
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'title',
          'date',
          'country.id',
          'country.iso3',
          'country.name',
          'country.shortname',
          'source.id',
          'source.name',
          'source.shortname',
        ],
      ],
      'sort' => ['date.created:desc'],
    ],
    'training' => [
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'title',
          'date',
          'country.id',
          'country.iso3',
          'country.name',
          'country.shortname',
          'source.id',
          'source.name',
          'source.shortname',
          'language.id',
          'language.name',
          'language.code',
        ],
      ],
      'sort' => ['date.created:desc'],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function getPageSections() {
    if (isset($this->sections)) {
      return $sections;
    }

    $sections = [];
    $sections['digital-sitrep'] = $this->getDigitalSitrepSection();
    $sections['key-figures'] = $this->getKeyFiguresSection();

    // Get data from the API.
    // @todo move those the Reports etc. river services.
    $queries = [
      'most-read' => $this->getMostReadApiQuery(),
      'updates' => $this->getLatestUpdatesApiQuery(),
      'maps-infographics' => $this->getLatestMapsInfographicsApiQuery(),
      'disasters' => $this->getLatestDisastersApiQuery(),
      'jobs' => $this->getLatestJobsApiQuery(),
      'training' => $this->getLatestTrainingApiQuery(),
    ];

    foreach ($queries as $index => $query) {
      // @todo retrieve data from API. Currently it's just a placeholder code.
      $queries[$index] = $query;
    }

    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTableOfContent() {
    return [
      '#markup' => '<p>TOC</p>',
    ];
  }

  /**
   * Get the Digital Situation Report for the country.
   *
   * @return array
   *   Render array for the Digital Situation Report section.
   */
  protected function getDigitalSitrepSection() {
    $client = \Drupal::service('reliefweb_dsr.client');
    $iso3 = $this->field_iso3->value;
    // @todo use the status once added back.
    $ongoing = TRUE;

    return $client->getDigitalSitrepBuild($iso3, $ongoing);
  }

  /**
   * Get the ReliefWeb key figures for the country.
   *
   * @return array
   *   Render array for the Key Figures section.
   */
  protected function getKeyFiguresSection() {
    $client = \Drupal::service('reliefweb_key_figures.client');
    $iso3 = $this->field_iso3->value;

    return $client->getKeyFiguresBuild($iso3, $this->label());
  }

  /**
   * Get payload for the most read documents.
   *
   * We cache the data for 3 hours as the query to get the most read reports
   * is quite expensive.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   D if it's a disaster).
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getMostReadApiQuery($code = 'PC') {
    $entity_id = $this->id();

    // Load the most-read data. This file is generated via a drush command,
    // usually every day as the query to get the 5 most read reports is very
    // heavy.
    $handle = @fopen('public://most-read/most-read.csv', 'r');
    if ($handle === FALSE) {
      return [];
    }

    // Find the line corresponding to the entity id.
    while (($row = fgetcsv($handle, 100)) !== FALSE) {
      if (count($row) === 2 && $row[0] == $entity_id) {
        $ids = explode(',', $row[1]);
        break;
      }
    }

    // Close the file.
    fclose($handle);

    // Generate the query with the most read report ids.
    if (!empty($ids)) {
      // We reverse the ids to add the boost (higher boost = higher view count).
      foreach (array_reverse($ids) as $index => $id) {
        $ids[] = $id . '^' . ($index * 10);
      }

      $payload = $this->payloads['reports'];
      $payload['query']['value'] = 'id:' . implode(' OR id:', $ids);
      $payload['limit'] = 5;
      $payload['sort'] = ['score:desc', 'date.created:desc'];

      return [
        'resource' => 'reports',
        'payload' => $payload,
        'callback' => '\Drupal\reliefweb_rivers\Services\Reports::parseApiData',
        // Link to the updates river for the entity.
        'url' => UrlHelper::encodeUrl('/updates?advanced-search=(' . $code . $entity_id . ')'),
      ];
    }
    return [];
  }

  /**
   * Get payload for latest updates.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   D if it's a disaster).
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   *
   * @todo move that to a trait or an ancestor class?
   */
  protected function getLatestUpdatesApiQuery($code = 'PC') {
    $bundle = $this->bundle();
    $entity_id = $this->id();
    $field_name = $bundle === 'country' ? 'primary_country' : $bundle;

    $payload = $this->payloads['reports'];
    $payload['filter'] = [
      'field' => $field_name . '.id',
      'value' => $entity_id,
    ];
    $payload['limit'] = 3;

    return [
      'resource' => 'reports',
      'payload' => $payload,
      'callback' => '\Drupal\reliefweb_rivers\Services\Reports::parseApiData',
      // Link to the updates river for the entity.
      'url' => UrlHelper::encodeUrl('/updates?advanced-search=(' . $code . $entity_id . ')'),
    ];
  }

  /**
   * Get payload for maps and infographics.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   D if it's a disaster).
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestMapsInfographicsApiQuery($code = 'PC') {
    $bundle = $this->bundle();
    $entity_id = $this->id();
    $field_name = $bundle === 'country' ? 'primary_country' : $bundle;

    $payload = $this->payloads['reports'];
    $payload['fields']['include'][] = 'file.preview.url-small';
    $payload['filter'] = [
      'conditions' => [
        [
          'field' => $field_name . '.id',
          'value' => $entity_id,
        ],
        [
          'field' => 'format.id',
          // 12 = Map, 12570 = Infographic.
          // @todo use the format.name.exact instead?
          'value' => [12, 12570],
        ],
      ],
      'operator' => 'AND',
    ];
    $payload['limit'] = 3;

    return [
      'resource' => 'reports',
      'payload' => $payload,
      'callback' => '\Drupal\reliefweb_rivers\Services\Reports::parseApiData',
      // Link to the updates river with the maps/infographics for the entity.
      'url' => UrlHelper::encodeUrl('/updates?advanced-search=(' . $code . $entity_id . ')_(F12.F12570)'),
    ];
  }

  /**
   * Get payload for latest jobs.
   *
   * @param string $code
   *   Filter code for the river link (ex: C if the entity is a country, or
   *   S if it's a source).
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestJobsApiQuery($code = 'C') {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = $this->payloads['jobs'];
    $payload['filter'] = [
      'field' => $bundle . '.id',
      'value' => $entity_id,
    ];
    $payload['limit'] = 3;

    return [
      'resource' => 'jobs',
      'payload' => $payload,
      'callback' => '\Drupal\reliefweb_rivers\Services\Jobs::parseApiData',
      // Link to the jobs river for the entity.
      'url' => UrlHelper::encodeUrl('/jobs?advanced-search=(' . $code . $entity_id . ')'),
    ];
  }

  /**
   * Get payload for latest training.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   S if it's a source).
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestTrainingApiQuery($code = 'C') {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = $this->payloads['training'];
    $payload['filter'] = [
      'field' => $bundle . '.id',
      'value' => $entity_id,
    ];
    $payload['limit'] = 3;

    return [
      'resource' => 'training',
      'payload' => $payload,
      'callback' => '\Drupal\reliefweb_rivers\Services\Training::parseApiData',
      // Link to the training river for the entity.
      'url' => UrlHelper::encodeUrl('/training?advanced-search=(' . $code . $entity_id . ')'),
    ];
  }

  /**
   * Get payload for latest alert and ongoing disasters.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestDisastersApiQuery() {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = $this->payloads['disasters'];
    $payload['filter'] = [
      'conditions' => [
        [
          'field' => $bundle . '.id',
          'value' => $entity_id,
        ],
        [
          'field' => 'status',
          'value' => ['alert', 'current'],
        ],
      ],
      'operator' => 'AND',
    ];
    // High limit to ensure we get all of them.
    $payload['limit'] = 100;

    return [
      'resource' => 'disasters',
      'payload' => $payload,
      'callback' => '\Drupal\reliefweb_rivers\Services\Disasters::parseApiData',
      // Link to the disasters river for the country.
      'url' => UrlHelper::encodeUrl('/disasters?advanced-search=(C' . $entity_id . ')'),
    ];
  }

}
