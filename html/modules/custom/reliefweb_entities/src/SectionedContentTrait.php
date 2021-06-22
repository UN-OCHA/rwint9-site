<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;

/**
 * Trait implementing most methods of the SectionedContentInterface.
 *
 * @see Drupal\reliefweb_entities\SectionedContentInterface
 */
trait SectionedContentTrait {

  use StringTranslationTrait;

  /**
   * API payloads for the different content sections.
   *
   * @var array
   *
   * @todo retrieve the payloads from the river services.
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
   * Get the base ReliefWeb API query payload for the resource.
   *
   * @see \Drupal\reliefweb_entities::getSectionsFromReliefWebApiQueries()
   */
  public function getReliefWebApiPayload($resource) {
    return $this->payloads[$resource] ?? [];
  }

  /**
   * Get the section data for the given ReliefWeb API queries.
   *
   * @see \Drupal\reliefweb_entities::getSectionsFromReliefWebApiQueries()
   */
  public function getSectionsFromReliefWebApiQueries(array $queries) {
    $results = \Drupal::service('reliefweb_api.client')
      ->requestMultiple(array_filter($queries));

    // Parse the API results, building the page sections data.
    $sections = [];
    foreach ($results as $index => $result) {
      if (!empty($result['data'])) {
        $query = $queries[$index];

        $sections[$index] = [
          '#theme' => 'reliefweb_rivers_river',
          '#id' => $index,
          '#resource' => $query['resource'],
          '#total' => $result['totalCount'],
          '#entities' => $this->parseReliefWebApiData($query['bundle'], $result),
          '#more' => $query['more'] ?? NULL,
        ];
      }
    }
    return $sections;
  }

  /**
   * Parse the data returned by the ReliefWeb API.
   *
   * @see \Drupal\reliefweb_entities::parseReliefWebApiData()
   */
  public function parseReliefWebApiData($bundle, array $data, $view = '') {
    $handler = \Drupal::service('reliefweb_rivers.' . $bundle . '.river');
    return $handler->parseApiData($data, $view);
  }

  /**
   * Consolidate content sections.
   *
   * @see \Drupal\reliefweb_entities::consolidateSections()
   */
  public function consolidateSections(array $contents, array $sections, array $labels) {
    // Parse the table of content, remove empty sections and update the title
    // of the sections.
    foreach ($contents as $key => &$group) {
      foreach ($group['sections'] as $name => $label) {
        if (empty($sections[$name])) {
          unset($group['sections'][$name]);
        }
        else {
          // Use the label override for the section, or the section title
          // is defined or the label from the table of content.
          $sections[$name]['#title'] = $labels[$name] ?? $section['#title'] ?? $label;
          $sections[$name]['#id'] = $sections[$name]['#id'] ?? $name;
        }
      }
      if (empty($group['sections'])) {
        unset($contents[$key]);
      }
    }

    return [
      '#theme' => 'reliefweb_entities_sectioned_content',
      '#contents' => [
        '#theme' => 'reliefweb_entities_table_of_contents',
        '#title' => $this->t('Table of Contents'),
        '#sections' => $contents,
      ],
      '#sections' => $sections,
    ];
  }

  /**
   * Get the entity description (for countries, disasters, sources).
   *
   * @return array
   *   Render array with the description.
   */
  public function getEntityDescription() {
    if (!empty($this->description->value)) {
      // @todo review handling of markdown when there is a proper release of
      // https://www.drupal.org/project/markdown for Drupal 9.
      if ($this->description->format === 'markdown') {
        $description = HtmlSanitizer::sanitizeFromMarkdown($this->description->value);
      }
      else {
        $description = HtmlSanitizer::sanitize(check_markup($this->description->value));
      }

      return [
        '#theme' => 'reliefweb_entities_entity_description',
        '#description' => $description,
      ];
    }
    return [];
  }

  /**
   * Get payload for the most read documents.
   *
   * @see \Drupal\reliefweb_entities::getMostReadApiQuery()
   */
  public function getMostReadApiQuery($code = 'PC', $limit = 5) {
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

      $payload = $this->getReliefWebApiPayload('reports');
      $payload['query']['value'] = 'id:' . implode(' OR id:', $ids);
      $payload['limit'] = $limit;
      $payload['sort'] = ['score:desc', 'date.created:desc'];

      return [
        'resource' => 'reports',
        'bundle' => 'report',
        'payload' => $payload,
        // Link to the updates river for the entity.
        'more' => [
          'url' => RiverServiceBase::getRiverUrl('report', [
            'advanced-search' => '(' . $code . $entity_id . ')',
          ]),
          'label' => $this->t('View all @label updates', [
            '@label' => $this->label(),
          ]),
        ],
      ];
    }
    return [];
  }

  /**
   * Get payload for latest updates.
   *
   * @see \Drupal\reliefweb_entities::getLatestUpdatesApiQuery()
   */
  public function getLatestUpdatesApiQuery($code = 'PC', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();
    $field_name = $bundle === 'country' ? 'primary_country' : $bundle;

    $payload = $this->getReliefWebApiPayload('reports');
    $payload['filter'] = [
      'field' => $field_name . '.id',
      'value' => $entity_id,
    ];
    $payload['limit'] = $limit;

    // DEBUG.
    // @todo remove.
    $payload['fields']['include'][] = 'file.preview.url-small';
    $payload['fields']['include'][] = 'headline.summary';
    $payload['fields']['include'][] = 'body-html';

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'payload' => $payload,
      // Link to the updates river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report', [
          'advanced-search' => '(' . $code . $entity_id . ')',
        ]),
        'label' => $this->t('View all @label updates', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for maps and infographics.
   *
   * @see \Drupal\reliefweb_entities::getLatestMapsInfographicsApiQuery()
   */
  public function getLatestMapsInfographicsApiQuery($code = 'PC', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();
    $field_name = $bundle === 'country' ? 'primary_country' : $bundle;

    $payload = $this->getReliefWebApiPayload('reports');
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
    $payload['limit'] = $limit;

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'payload' => $payload,
      // Link to the updates river with the maps/infographics for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report', [
          'advanced-search' => '(' . $code . $entity_id . ')_(F12.F12570)',
        ]),
        'label' => $this->t('View all @label maps and infographics', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for latest jobs.
   *
   * @see \Drupal\reliefweb_entities::getLatestJobsApiQuery()
   */
  public function getLatestJobsApiQuery($code = 'C', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = $this->getReliefWebApiPayload('jobs');
    $payload['filter'] = [
      'field' => $bundle . '.id',
      'value' => $entity_id,
    ];
    $payload['limit'] = $limit;

    return [
      'resource' => 'jobs',
      'bundle' => 'job',
      'payload' => $payload,
      // Link to the jobs river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('job', [
          'advanced-search' => '(' . $code . $entity_id . ')',
        ]),
        'label' => $this->t('View all @label jobs', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for latest training.
   *
   * @see \Drupal\reliefweb_entities::getLatestTrainingApiQuery()
   */
  public function getLatestTrainingApiQuery($code = 'C', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = $this->getReliefWebApiPayload('training');
    $payload['filter'] = [
      'field' => $bundle . '.id',
      'value' => $entity_id,
    ];
    $payload['limit'] = $limit;

    return [
      'resource' => 'training',
      'bundle' => 'training',
      'payload' => $payload,
      // Link to the training river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('training', [
          'advanced-search' => '(' . $code . $entity_id . ')',
        ]),
        'label' => $this->t('View all @label training opportunities', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for latest alert and ongoing disasters.
   *
   * @see \Drupal\reliefweb_entities::getLatestDisastersApiQuery()
   */
  public function getLatestDisastersApiQuery($code = 'C', $limit = 100) {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = $this->getReliefWebApiPayload('disasters');
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
      'bundle' => 'disaster',
      'payload' => $payload,
      // Link to the disasters river for the country.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('disaster', [
          'advanced-search' => '(' . $code . $entity_id . ')',
        ]),
        'label' => $this->t('View all @label disasters', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

}
