<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\reliefweb_rivers\AdvancedSearch;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve source resource for the source rivers.
 */
class SourceRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'organizations';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'sources';

  /**
   * {@inheritdoc}
   */
  protected $entityTypeId = 'taxonomy_term';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'source';

  /**
   * {@inheritdoc}
   */
  protected $limit = 40;

  /**
   * {@inheritdoc}
   */
  public function getDefaultPageTitle() {
    return $this->t('Organizations');
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->getDefaultPageTitle();
  }

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    // Get the list of the first letters of the all the viewable sources.
    $letters = static::getFirstLetters();

    // Get the currently selected letter filter and mark the corresponding
    // letter as active.
    $letter = $this->getParameters()->getString('group', 'all');
    if (isset($letters[$letter])) {
      $letters[$letter]['active'] = TRUE;
    }
    else {
      $letters['all']['active'] = TRUE;
    }

    $content = parent::getPageContent();
    $content['#letter_navigation'] = [
      '#theme' => 'reliefweb_rivers_letter_navigation',
      '#title' => $this->t('Filter by first letter'),
      '#letters' => $letters,
      '#cache' => [
        '#contexts' => [
          'url.query_args:group',
        ],
      ],
    ];

    return $content;
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
    $payload = [
      'query' => [
        'fields' => [
          'name',
          'shortname',
        ],
        'operator' => 'AND',
      ],
      'fields' => [
        'include' => [
          'id',
          'name',
          'shortname',
          'url_alias',
        ],
      ],
      'filter' => [
        'field' => 'status',
        'value' => 'active',
      ],
      'sort' => ['name.collation_en:asc'],
    ];

    // Add a filter on the selected letter.
    $letters = static::getFirstLetters();
    $letter = $this->getParameters()->getString('group', 'all');
    if (!empty($letters[$letter]['ids'])) {
      $payload['filter'] = [
        'conditions' => [
          $payload['filter'],
          [
            'field' => 'id',
            'value' => $letters[$letter]['ids'],
            'operator' => 'OR',
          ],
        ],
        'operator' => 'AND',
      ];
    }

    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiData(array $api_data, $view = '') {
    // Retrieve the API data (with backward compatibility).
    $items = $api_data['items'] ?? $api_data['data'] ?? [];

    // Get the source ids.
    $ids = [];
    foreach ($items as $item) {
      // @todo Append the shortname?
      $ids[$item['id']] = $item['fields']['name'] ?? '';
    }

    // Get the publications of the sources.
    $publications = $this->getPublications('source', $ids);

    // Parse the entities retrieved from the API.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];

      // Title.
      $title = $fields['name'];

      // Add the shortname to the title.
      if (!empty($fields['shortname']) && $fields['shortname'] !== $fields['name']) {
        $title .= ' (' . $fields['shortname'] . ')';
      }

      // Use the publications as tags.
      $tags = [];
      if (isset($publications[$item['id']])) {
        $tags['publication'] = $publications[$item['id']];
      }

      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
        'title' => $title,
        'tags' => $tags,
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
   * Get the number of documents tagged with some taxonomy terms.
   *
   * The documents are published reports, jobs and training tagged with the
   * the given taxonomy terms.
   *
   * @param string $field
   *   Field name (without the 'field_' prefix) used to tag the taxonomy terms.
   * @param array $ids
   *   List of taxonomy term ids (id as key, name as value).
   *
   * @return array
   *   Associative array with the term ids as keys and the total of published
   *   reports, jobs and training as values.
   */
  public function getPublications($field, array $ids) {
    if (empty($ids)) {
      return [];
    }

    // API request payload (already encoded as it's the same for all the
    // queries).
    $payload = json_encode([
      // We just want the source facet data.
      'limit' => 0,
      'filter' => [
        'field' => $field . '.id',
        'value' => array_keys($ids),
      ],
      // We'll extract the number of documents for the sources from the
      // the facets data.
      'facets' => [
        0 => [
          'name' => 'source',
          'field' => $field . '.id',
          // Set a limit large enough to ensure we get all the sources.
          // Estimation is that number of ids * 2 would be enough but setting
          // 10000 doesn't hurt performances.
          'limit' => max(10000, count($ids) * 2),
        ],
      ],
    ]);

    // API resources.
    $queries = [
      [
        'resource' => 'reports',
        'payload' => $payload,
      ],
      [
        'resource' => 'jobs',
        'payload' => $payload,
      ],
      [
        'resource' => 'training',
        'payload' => $payload,
      ],
    ];

    $results = $this->apiClient->requestMultiple($queries);

    // Parse the results.
    $publications = [];
    foreach ($results as $index => $data) {
      $resource = $queries[$index]['resource'];
      if (isset($data['embedded']['facets']['source']['data'])) {
        foreach ($data['embedded']['facets']['source']['data'] as $item) {
          if (isset($ids[$item['value']]) && !empty($item['count'])) {
            $id = $item['value'];
            $name = $ids[$id];
            $count = (int) $item['count'];

            switch ($resource) {
              case 'reports':
                $publications[$id]['reports'] = [
                  'name' => $this->formatPlural($count, '1 published report', '@total published reports', [
                    '@total' => LocalizationHelper::formatNumber($count),
                  ]),
                  'url' => static::getRiverUrl('report', [
                    'advanced-search' => '(S' . $id . ')',
                  ], $name, TRUE),
                ];
                break;

              case 'jobs':
                $publications[$id]['jobs'] = [
                  'name' => $this->formatPlural($count, '1 open job', '@total open jobs', [
                    '@total' => LocalizationHelper::formatNumber($count),
                  ]),
                  'url' => static::getRiverUrl('job', [
                    'advanced-search' => '(S' . $id . ')',
                  ], $name, TRUE),
                ];
                break;

              case 'training':
                $publications[$id]['training'] = [
                  'name' => $this->formatPlural($count, '1 open training', '@total open training', [
                    '@total' => LocalizationHelper::formatNumber($count),
                  ]),
                  'url' => static::getRiverUrl('training', [
                    'advanced-search' => '(S' . $id . ')',
                  ], $name, TRUE),
                ];
                break;
            }
          }
        }
      }
    }

    return $publications;
  }

  /**
   * Get the list of first letters for the viewable organizations.
   *
   * @return array
   *   List of letters keyed by letter and with a label, url and list ids of the
   *   sources starting with the letter.
   */
  public static function getFirstLetters() {
    /** @var \Drupal\Core\Cache\CacheBackendInterface $cache_backend */
    $cache_backend = \Drupal::cache();

    // Get the current language code to use for the cache id. The order of the
    // letters may differ from one language to another.
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

    // Cache information.
    $cache_id = 'reliefweb_river:source:letters:' . $langcode;

    // Attempt to get the data from the cache.
    $cache = $cache_backend->get($cache_id);
    if (isset($cache->data)) {
      return $cache->data;
    }

    // We use the loadReferenceValues from the advanced search because it
    // conveniently returns a list of viewable terms sorted by name so we can
    // easily extract the first letters.
    $terms = AdvancedSearch::loadReferenceValues([
      'vocabulary' => 'source',
    ]);

    $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII;');

    $letters = [];
    foreach ($terms as $term) {
      $name = TextHelper::trimText($term['name']);
      $letter = mb_strtoupper(mb_substr($name, 0, 1));
      $letter = $transliterator->transliterate($letter);
      if (is_numeric($letter)) {
        $letter = '#';
      }

      if (!isset($letters[$letter])) {
        $letters[$letter] = [
          'label' => $letter,
          'url' => static::getRiverUrl('source', [
            'group' => $letter,
          ]),
          'ids' => [],
        ];
      }

      // Store the term ids so we can easily filter the sources.
      $letters[$letter]['ids'][] = $term['id'];
    }

    $letters['all'] = [
      'label' => t('All'),
      'url' => static::getRiverUrl('source'),
    ];

    // Cache the list of letters permanently. It will be rebuilt when a source
    // is modified.
    $cache_backend->set($cache_id, $letters, CacheBackendInterface::CACHE_PERMANENT, ['taxonomy_term_list:source']);
    return $letters;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRiverDescription() {
    return $this->t('A list of organizations that are actively providing ReliefWeb with content (reports, jobs and training).');
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverCacheTags() {
    return [
      $this->getEntityTypeId() . '_list:' . $this->getBundle(),
      'taxonomy_term_list:country',
      'taxonomy_term_list:organization_type',
      'node_list:report',
      'node_list:job',
      'node_list:training',
    ];
  }

}
