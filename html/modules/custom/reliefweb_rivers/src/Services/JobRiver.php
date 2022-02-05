<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve job resource for the job rivers.
 */
class JobRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'jobs';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'jobs';

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'job';

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Jobs');
  }

  /**
   * {@inheritdoc}
   */
  public function getViews() {
    return [
      'all' => $this->t('All Jobs'),
      'closing-soon' => $this->t('Closing soon'),
      'unspecified-location' => $this->t('Remote / Roster / Roving'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      'TY' => [
        'name' => $this->t('Job type'),
        'type' => 'reference',
        'vocabulary' => 'job_type',
        'field' => 'type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a job type'),
        ],
      ],
      'CC' => [
        'name' => $this->t('Career category'),
        'type' => 'reference',
        'vocabulary' => 'career_category',
        'field' => 'career_categories.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a career categories'),
        ],
      ],
      'E' => [
        'name' => $this->t('Experience'),
        'type' => 'reference',
        'vocabulary' => 'job_experience',
        'field' => 'experience.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select years of experience'),
        ],
        'sort' => 'id',
      ],
      'T' => [
        'name' => $this->t('Theme'),
        'type' => 'reference',
        'vocabulary' => 'theme',
        'field' => 'theme.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a theme'),
        ],
        'exclude' => [
          // Remove Contributions (Collab #2327).
          4589,
          // Remove Humanitarian Financing (Trello #OnXq5cCC).
          4597,
          // Remove Logistics and Telecommunications (Trello #G3YgNUF6).
          4598,
        ],
      ],
      'C' => [
        'name' => $this->t('Country'),
        'type' => 'reference',
        'vocabulary' => 'country',
        'field' => 'country.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a country'),
          'resource' => 'countries',
        ],
        'exclude' => [
          // Remove World (254) (Trello #DI9bxljg).
          254,
        ],
      ],
      'S' => [
        'name' => $this->t('Organization'),
        'type' => 'reference',
        'vocabulary' => 'source',
        'field' => 'source.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for an organization'),
          'resource' => 'sources',
          'parameters' => [
            'filter' => [
              'field' => 'content_type',
              'value' => 'job',
            ],
          ],
        ],
      ],
      'OT' => [
        'name' => $this->t('Organization type'),
        'type' => 'reference',
        'vocabulary' => 'organization_type',
        'field' => 'source.type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select an organization type'),
        ],
      ],
      'DC' => [
        'name' => $this->t('Closing date'),
        'type' => 'date',
        'field' => 'date.closing',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select closing date'),
        ],
      ],
      'DA' => [
        'name' => $this->t('Posting date on ReliefWeb'),
        'type' => 'date',
        'field' => 'date.created',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select posting date on ReliefWeb'),
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterSample() {
    return $this->t('(Country, job type, experience...)');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiPayload($view = '') {
    $payload = [
      'query' => [
        'fields' => [
          'title^20',
          'body',
          'how_to_apply',
          'country.name^100',
          'country.shortname^100',
          'source.name^100',
          'source.shortname^100',
          'theme.name^100',
          'type.name^100',
          'career_categories.name^100',
        ],
        'operator' => 'AND',
      ],
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'title',
          'body-html',
          'date.closing',
          'date.created',
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
    ];

    // Handle the filtered selection (view).
    switch ($view) {
      case 'closing-soon':
        // Jobs closing within a week.
        $date = date_create('now', new \DateTimeZone('UTC'));
        $payload['filter'] = [
          'field' => 'date.closing',
          'value' => [
            'from' => $date->format(DATE_ATOM),
            'to' => $date->add(new \DateInterval('P1W'))->format(DATE_ATOM),
          ],
        ];
        $payload['sort'] = ['date.closing:asc'];
        break;

      case 'unspecified-location':
        $payload['filter'] = [
          'field' => 'country',
          'negate' => TRUE,
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
      $title = $fields['title'];

      // Summary.
      $summary = '';
      if (!empty($fields['body-html'])) {
        $body = HtmlSanitizer::sanitize($fields['body-html']);
        $summary = HtmlSummarizer::summarize($body, 200);
      }

      // Tags (countries, sources etc.).
      $tags = [];

      // Countries.
      $countries = [];
      foreach ($fields['country'] ?? [] as $country) {
        $countries[] = [
          'name' => $country['name'],
          'shortname' => $country['shortname'] ?? $country['name'],
          'code' => $country['iso3'] ?? '',
          'url' => static::getRiverUrl($this->bundle, [
            'advanced-search' => '(C' . $country['id'] . ')',
          ]),
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
          'url' => static::getRiverUrl($this->bundle, [
            'advanced-search' => '(S' . $source['id'] . ')',
          ]),
        ];
      }
      $tags['source'] = $sources;

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
        $data['url'] = UrlHelper::getAliasFromPath('/node/' . $item['id']);
      }

      if (isset($fields['date']['created'])) {
        $data['posted'] = static::createDate($fields['date']['created']);
      }
      if (isset($fields['date']['closing'])) {
        $data['closing'] = static::createDate($fields['date']['closing']);
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
    $payload['fields']['include'][] = 'date.created';
    $payload['fields']['include'][] = 'career_categories.name';
    $payload['fields']['include'][] = 'type.name';
    $payload['fields']['include'][] = 'theme.name';
    $payload['fields']['include'][] = 'how_to_apply-html';
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
      $data['title'] = $fields['title'];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::getAliasFromPath('/node/' . $item['id']);
      }

      // Dates.
      $data['date'] = static::createDate($fields['date']['created']);

      // Body and how to apply.
      $data['body'] = $fields['body-html'] ?? '';

      if (!empty($fields['how_to_apply-html'])) {
        $data['body'] .= '<h3>' . $this->t('How to apply') . '</h3>' . $fields['how_to_apply-html'];
      }

      // Categories.
      $categories = [
        'country' => [
          $this->t('Country'),
          $this->t('Countries'),
        ],
        'source' => [
          $this->t('Organization'),
          $this->t('Organizations'),
        ],
        'career_categories' => [
          $this->t('Career category'),
          $this->t('Career categories'),
        ],
        'type' => [
          $this->t('Job type'),
          $this->t('Job types'),
        ],
        'theme' => [
          $this->t('Theme'),
          $this->t('Themes'),
        ],
      ];
      $inline = ['country' => TRUE, 'source' => TRUE];
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

      // Dates (description header).
      $dates = [
        'closing' => $this->t('Closing date'),
      ];
      foreach ($dates as $date => $label) {
        if (!empty($fields['date'][$date])) {
          $data['dates'][$date] = [
            'label' => $label,
            'value' => $fields['date'][$date],
          ];
        }
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

}
