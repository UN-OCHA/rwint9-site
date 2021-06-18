<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
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
  protected $entityType = 'taxonomy_term';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'source';

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Organizations');
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

    // Get the source ids.
    $ids = [];
    foreach ($items as $item) {
      $ids[] = $item['id'];
    }

    // Get the publications of the sources.
    $publications = static::getPublications('source', $ids);

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
        'bundle' => $item['bundle'],
        'title' => $title,
        'tags' => $tags,
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

  /**
   * Get the number of documents tagged with some taxonomy terms.
   *
   * The documents are published reports, jobs and training tagged with the
   * the given taxonomy terms.
   *
   * @param string $field
   *   Field name (without the 'field_' prefix) used to tag the taxonomy terms.
   * @param array $ids
   *   List of taxonomy term ids.
   *
   * @return array
   *   Associative array with the term ids as keys and the total of published
   *   reports, jobs and training as values.
   */
  public static function getPublications($field, array $ids) {
    if (empty($ids)) {
      return [];
    }

    $sources = array_combine($ids, $ids);

    // API request payload (already encoded as it's the same for all the
    // queries).
    $payload = json_encode([
      // We just want the source facet data.
      'limit' => 0,
      'filter' => [
        'field' => $field . '.id',
        'value' => $ids,
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

    $results = \Drupal::service('reliefweb_api.client')
      ->requestMulitple($queries, TRUE);

    // Parse the results.
    $publications = [];
    foreach ($results as $index => $data) {
      $resource = $queries[$index]['resource'];
      if (isset($data['embedded']['facets']['source']['data'])) {
        foreach ($data['embedded']['facets']['source']['data'] as $item) {
          if (isset($sources[$item['value']]) && !empty($item['count'])) {
            $id = $item['value'];
            $count = (int) $item['count'];

            switch ($resource) {
              case 'reports':
                $publications[$id]['reports'] = [
                  'name' => $this->formatPlural($count, '1 published report', '@count published reports'),
                  'url' => UrlHelper::encodeUrl('/updates?advanced-search=(S' . $id . ')'),
                ];
                break;

              case 'jobs':
                $publications[$id]['jobs'] = [
                  'name' => $this->formatPlural($count, '1 open job', '@count open jobs'),
                  'url' => UrlHelper::encodeUrl('/jobs?advanced-search=(S' . $id . ')'),
                ];
                break;

              case 'training':
                $publications[$id]['training'] = [
                  'name' => $this->formatPlural($count, '1 open training', '@count open training'),
                  'url' => UrlHelper::encodeUrl('/training?advanced-search=(S' . $id . ')'),
                ];
                break;
            }
          }
        }
      }
    }

    return $publications;
  }

}
