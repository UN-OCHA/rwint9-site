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
  protected $entityTypeId = 'node';

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
    return [
      'all' => $this->t('All Training'),
      'closing-soon' => $this->t('Closing soon'),
      'free' => $this->t('Free courses'),
      'online' => $this->t('Online courses'),
      'ongoing' => $this->t('Ongoing / Permanent'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      'TY' => [
        'name' => $this->t('Category'),
        'type' => 'reference',
        'vocabulary' => 'training_type',
        'field' => 'type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a training category'),
        ],
      ],
      'CC' => [
        'name' => $this->t('Professional function'),
        'type' => 'reference',
        'vocabulary' => 'career_category',
        'field' => 'career_categories.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a professional function'),
        ],
      ],
      'F' => [
        'name' => $this->t('Format'),
        'type' => 'reference',
        'vocabulary' => 'training_format',
        'field' => 'format.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a training format'),
        ],
      ],
      'CO' => [
        'name' => $this->t('Cost'),
        'type' => 'fixed',
        'values' => [
          'free' => $this->t('Free'),
          'fee-based' => $this->t('Fee-based'),
        ],
        'field' => 'cost',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select cost'),
        ],
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
          // Remove Logistics and Telecommunications (Trello #G3YgNUF6).
          4598,
          // Camp Coordination and Camp Management.
          49458,
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
              'value' => 'training',
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
      'TL' => [
        'name' => $this->t('Training language'),
        'type' => 'reference',
        'vocabulary' => 'language',
        'exclude' => [
          // Other.
          31996,
        ],
        'field' => 'training_language.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select training language'),
        ],
      ],
      'DS' => [
        'name' => $this->t('Starting date'),
        'type' => 'date',
        'field' => 'date.start',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select training start date'),
        ],
      ],
      'DE' => [
        'name' => $this->t('Ending date'),
        'type' => 'date',
        'field' => 'date.end',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select training end date'),
        ],
      ],
      'DR' => [
        'name' => $this->t('Registration deadline'),
        'type' => 'date',
        'field' => 'date.registration',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select registration deadline'),
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
    return $this->t('(Country, cost, deadline...)');
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
          'how_to_register',
          'country.name^100',
          'country.shortname^100',
          'source.name^100',
          'source.shortname^100',
          'theme.name^100',
          'type.name^100',
          'career_categories.name^100',
          'format.name^100',
          'training_language.name^100',
          'cost^200',
        ],
        'operator' => 'AND',
      ],
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'title',
          'body-html',
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
    ];

    // Handle the filtered selection (view).
    switch ($view) {
      case 'closing-soon':
        // Training closing within a month.
        $date = date_create('now', new \DateTimeZone('UTC'));
        $payload['filter'] = [
          'field' => 'date.registration',
          'value' => [
            'from' => $date->format(DATE_ATOM),
            'to' => $date->add(new \DateInterval('P1M'))->format(DATE_ATOM),
          ],
        ];
        $payload['sort'] = ['date.registration:asc'];
        break;

      case 'free':
        $payload['filter'] = [
          'field' => 'cost',
          'value' => 'free',
        ];
        break;

      case 'online':
        $payload['filter'] = [
          'field' => 'format.id',
          'value' => 4607,
        ];
        break;

      case 'ongoing':
        $payload['filter'] = [
          'field' => 'date.start',
          'negate' => TRUE,
        ];
        break;

      case 'workshop':
        $payload['filter'] = [
          'field' => 'type.id',
          'value' => 4609,
        ];
        break;

      case 'academic':
        $payload['filter'] = [
          'field' => 'type.id',
          'value' => 4610,
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
        $data['url'] = UrlHelper::getAliasFromPath('/node/' . $item['id'], FALSE);
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
