<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_rivers\RiverServiceBase;

/**
 * Trait implementing most methods of the SectionedContentInterface.
 *
 * @see Drupal\reliefweb_entities\SectionedContentInterface
 */
trait SectionedContentTrait {

  use StringTranslationTrait;

  /**
   * Get the section data for the given ReliefWeb API queries.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetSectionsFromReliefWebApiQueries()
   */
  public function getSectionsFromReliefWebApiQueries(array $queries) {
    $results = \Drupal::service('reliefweb_api.client')
      ->requestMultiple(array_filter($queries));

    // Parse the API results, building the page sections data.
    $sections = [];
    foreach ($results as $index => $result) {
      if (!empty($result['data'])) {
        $query = $queries[$index];

        $bundle = $query['bundle'];
        $view = $query['view'] ?? '';
        $exclude = $query['exclude'] ?? [];

        // Parse the API result and return data suitable for use in the
        // river templates.
        $entities = RiverServiceBase::getRiverData($bundle, $result, $view, $exclude);

        $sections[$index] = [
          '#theme' => 'reliefweb_rivers_river',
          '#id' => $index,
          '#resource' => $query['resource'],
          '#entities' => $entities,
          '#more' => $query['more'] ?? NULL,
          '#title' => $query['title'] ?? NULL,
        ];
      }
    }
    return $sections;
  }

  /**
   * Consolidate content sections.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfaceconsolidateSections()
   */
  public function consolidateSections(array $contents, array $sections, array $labels = []) {
    $consolidated = [];

    // Parse the table of content, remove empty sections and update the title
    // of the sections.
    foreach ($contents as $key => &$group) {
      foreach ($group['sections'] as $name => $label) {
        // Remove section from table of contents group if there is no
        // corresponding section.
        if (empty($sections[$name])) {
          unset($group['sections'][$name]);
        }
        // Otherwise update the title and id of the section.
        else {
          $section = $sections[$name];

          // Use the label override for the section, or the section title
          // is defined or the label from the table of content.
          $section['#title'] = $labels[$name] ?? $section['#title'] ?? $label;
          $section['#id'] = $section['#id'] ?? $name;

          $consolidated[$name] = $section;
        }
      }

      // Remove the group of sections from the table of contents if there is
      // no corresponding sections.
      if (empty($group['sections'])) {
        unset($contents[$key]);
      }
    }

    // Skip if there is no content.
    if (empty($consolidated)) {
      return [];
    }

    return [
      '#theme' => 'reliefweb_entities_sectioned_content',
      '#contents' => [
        '#theme' => 'reliefweb_entities_table_of_contents',
        '#title' => $this->t('Table of Contents'),
        '#sections' => $contents,
      ],
      '#sections' => $consolidated,
    ];
  }

  /**
   * Get the entity description (for countries, disasters, sources).
   *
   * @param string $id
   *   Section id.
   *
   * @return array
   *   Render array with the description.
   */
  public function getEntityDescription($id) {
    return $this->getEntityTextField('description', $id, $this->t('Description'), FALSE);
  }

  /**
   * Get the content of a text field.
   *
   * @param string $field_name
   *   Text field name.
   * @param string $id
   *   Section id.
   * @param string $title
   *   Section title.
   * @param bool $iframe
   *   Flag indicating whether iframes are allowed in the rendred HTML or not.
   * @param array $allowed_attributes
   *   Extra attributes allowed in the rendered HtmL.
   *
   * @return array
   *   Render array with the text content.
   */
  public function getEntityTextField($field_name, $id, $title, $iframe = TRUE, array $allowed_attributes = []) {
    if (!$this->{$field_name}->isEmpty()) {
      return [
        '#theme' => [
          'reliefweb_entities_entity_text__' . $this->bundle() . '__' . $id,
          'reliefweb_entities_entity_text__' . $id,
          'reliefweb_entities_entity_text',
        ],
        '#id' => $id,
        '#content' => $this->{$field_name}->first()->view(),
        '#iframe' => $iframe,
        '#allowed_attributes' => $allowed_attributes,
      ];
    }
    return [];
  }

  /**
   * Get payload for the key content reports.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetKeyContentApiQuery()
   */
  public function getKeyContentApiQuery($code = 'PC', $limit = 3) {
    $links = $this->getProfileFieldLinks('field_key_content');
    if (empty($links)) {
      return [];
    }

    // Extract the report ids from the key content profile field.
    $ids = [];
    foreach ($links as $link) {
      if (isset($link['url']) && preg_match('#/node/(?<id>\d+)#', $link['url'], $match) === 1) {
        $ids[] = $match['id'];
      }
    }
    if (empty($ids)) {
      return [];
    }

    // Apply the given limit.
    $ids = array_slice($ids, 0, $limit);

    // Build the query, boosting the ids to preserve the order.
    $count = count($ids);
    foreach ($ids as $index => $id) {
      $ids[$index] .= '^' . (($count - $index) * 10);
    }

    $payload = RiverServiceBase::getRiverApiPayload('report');
    $payload['fields']['exclude'][] = 'file.preview.url-thumb';
    $payload['fields']['include'][] = 'headline.summary';
    $payload['query']['value'] = 'id:' . implode(' OR id:', $ids);
    $payload['sort'] = ['score:desc', 'date.created:desc'];
    $payload['limit'] = $limit;

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'payload' => $payload,
      // Link to the updates river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report', [
          'advanced-search' => '(' . $code . $this->id() . ')',
        ]),
        'label' => $this->t('View all @label Situation Reports', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for the appeals and response plans.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetAppealsResponsePlansApiQuery()
   */
  public function getAppealsResponsePlansApiQuery($code = 'PC', $limit = 50) {
    $links = $this->getProfileFieldLinks('field_appeals_response_plans');
    if (empty($links)) {
      return [];
    }

    // Extract the report ids from the appeals/response plans profile field.
    $ids = [];
    foreach ($links as $link) {
      if (isset($link['url']) && preg_match('#/node/(?<id>\d+)#', $link['url'], $match) === 1) {
        $ids[] = $match['id'];
      }
    }
    if (empty($ids)) {
      return [];
    }

    // Apply the given limit.
    $ids = array_slice($ids, 0, $limit);

    // Build the query, boosting the ids to preserve the order.
    $count = count($ids);
    foreach ($ids as $index => $id) {
      $ids[$index] .= '^' . (($count - $index) * 10);
    }

    $payload = RiverServiceBase::getRiverApiPayload('report');
    $payload['fields']['exclude'][] = 'file.preview.url-thumb';
    $payload['fields']['exclude'][] = 'body-html';
    $payload['query']['value'] = 'id:' . implode(' OR id:', $ids);
    $payload['sort'] = ['score:desc', 'date.created:desc'];
    $payload['limit'] = $limit;

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'payload' => $payload,
      'exclude' => ['summary', 'format'],
      // Link to the updates river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report', [
          'advanced-search' => '(' . $code . $this->id() . ')',
        ]),
        'label' => $this->t('View all @label Appeals and Response Plans', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for the most read documents.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetMostReadApiQuery()
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

      $payload = RiverServiceBase::getRiverApiPayload('report');
      $payload['fields']['exclude'][] = 'file';
      $payload['fields']['exclude'][] = 'body-html';
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
          'label' => $this->t('View all @label Updates', [
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
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetLatestUpdatesApiQuery()
   */
  public function getLatestUpdatesApiQuery($code = 'PC', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();
    $field_name = $bundle === 'country' ? 'primary_country' : $bundle;

    $payload = RiverServiceBase::getRiverApiPayload('report');
    $payload['fields']['exclude'][] = 'file';
    $payload['fields']['exclude'][] = 'body-html';
    $payload['filter'] = [
      'field' => $field_name . '.id',
      'value' => $entity_id,
    ];
    $payload['limit'] = $limit;

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'payload' => $payload,
      // Link to the updates river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report', [
          'advanced-search' => '(' . $code . $entity_id . ')',
        ]),
        'label' => $this->t('View all @label Updates', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for maps and infographics.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetLatestMapsInfographicsApiQuery()
   */
  public function getLatestMapsInfographicsApiQuery($code = 'PC', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();
    $field_name = $bundle === 'country' ? 'primary_country' : $bundle;

    $payload = RiverServiceBase::getRiverApiPayload('report');
    $payload['fields']['exclude'][] = 'file.preview.url-thumb';
    $payload['fields']['exclude'][] = 'body-html';
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
      'exclude' => ['summary', 'format'],
      // Link to the updates river with the maps/infographics for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report', [
          'advanced-search' => '(' . $code . $entity_id . ')_(F12.F12570)',
        ]),
        'label' => $this->t('View all @label Maps and Infographics', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for latest jobs.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetLatestJobsApiQuery()
   */
  public function getLatestJobsApiQuery($code = 'C', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = RiverServiceBase::getRiverApiPayload('job');
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
        'label' => $this->t('View all @label Jobs', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for latest training.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetLatestTrainingApiQuery()
   */
  public function getLatestTrainingApiQuery($code = 'C', $limit = 3) {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = RiverServiceBase::getRiverApiPayload('training');
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
        'label' => $this->t('View all @label Training Opportunities', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get payload for latest alert and ongoing disasters.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetLatestDisastersApiQuery()
   */
  public function getLatestDisastersApiQuery($code = 'C', $limit = 100) {
    $bundle = $this->bundle();
    $entity_id = $this->id();

    $payload = RiverServiceBase::getRiverApiPayload('disaster');
    $payload['filter'] = [
      'conditions' => [
        [
          'field' => $bundle . '.id',
          'value' => $entity_id,
        ],
        [
          'field' => 'status',
          // Current is the legacy equivalent of ongoing.
          'value' => ['alert', 'current', 'ongoing'],
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
        'label' => $this->t('View all @label Disasters', [
          '@label' => $this->label(),
        ]),
      ],
    ];
  }

  /**
   * Get the section with the useful links for the entity (country/disaster).
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterfacegetUsefulLinksSection()
   */
  public function getUsefulLinksSection() {
    $links = $this->getProfileFieldLinks('field_useful_links');
    if (empty($links)) {
      return [];
    }

    $links = array_map(function ($item) {
      return [
        'url' => $item['url'],
        'title' => $item['title'] ?? $item['url'],
        'logo' => $item['image'] ?? '',
      ];
    }, $links);

    return [
      '#theme' => 'reliefweb_entities_entity_useful_links',
      '#links' => $links,
    ];
  }

  /**
   * Get the links of a country/disaster profile field.
   *
   * @see \Drupal\reliefweb_entities\SectionedContentInterface::getProfileFieldLinks()
   */
  public function getProfileFieldLinks($field) {
    if ($this->hasField($field)) {
      $links = array_filter($this->get($field)->getValue(), function ($item) {
        return !empty($item['url']) && !empty($item['active']);
      });
      // Reverse the array to have the most recent first.
      return array_reverse($links);
    }
    return [];
  }

}
