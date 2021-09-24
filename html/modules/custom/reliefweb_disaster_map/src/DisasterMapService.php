<?php

namespace Drupal\reliefweb_disaster_map;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;

/**
 * ReliefWeb disaster map service.
 */
class DisasterMapService implements TrustedCallbackInterface {

  use StringTranslationTrait;

  /**
   * The ReliefWeb API client.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $reliefWebApiClient;

  /**
   * Renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * Constructor.
   *
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $reliefweb_api_client
   *   The reliefweb api client service.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The reliefweb api client service.
   */
  public function __construct(ReliefWebApiClient $reliefweb_api_client, Renderer $renderer) {
    $this->reliefWebApiClient = $reliefweb_api_client;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return [
      'parseDisasterMapApiData',
    ];
  }

  /**
   * Get a disaster map.
   *
   * @param string $id
   *   Map id.
   * @param string $title
   *   Map title.
   * @param array $options
   *   Associative array with the optional keys:
   *   - statuses (array); list of disaster statuses (current, alert, past)
   *   - types (array): list of disaster type codes (ex: EP for epidemic)
   *   - ids (array): list of disaster ids
   *   - from (int): to retrieve disasters creatrd after the timestamp.
   * @param string $mode
   *   Type of data to be returned by the function:
   *   - data (default): RWPageDataWrapper to be passed to the template
   *   - html: render the template and return the generated HTML
   *   - query: API query as an associative array with:
   *     - title: map title
   *     - resource: 'disasters' API resource
   *     - payload: API payload to get the disasters
   *     - callback: callback to parse the API data and get a RWPageDataWrapper.
   *
   * @return mixed
   *   Disaster map data based on the $mode parameter.
   */
  public function getDisasterMap($id, $title, array $options = [], $mode = 'data') {
    $legend = [
      'ongoing' => $this->t('Red markers indicate ongoing situations.'),
      'alert' => $this->t('Orange markers indicate disaster alerts.'),
      'past' => $this->t('Grey markers indicate events that are no longer considered emergencies.'),
    ];

    $payload = [
      'fields' => [
        'include' => [
          'name',
          'status',
          'primary_country',
          'url_alias',
          'primary_type.code',
          'profile.overview-html',
        ],
      ],
      'sort' => ['date.created:desc'],
      'limit' => 200,
    ];

    // Query conditions.
    $conditions = [];

    // Filter by status.
    $statuses = ['current', 'alert', 'past'];
    if (!empty($options['statuses'])) {
      $options['statuses'] = array_intersect($statuses, $options['statuses']);
      $statuses = $options['statuses'] ?? $statuses;
    }
    $conditions[] = [
      'field' => 'status',
      'value' => $statuses,
      'operator' => 'OR',
    ];

    // Filter by disaster type.
    if (!empty($options['types'])) {
      $conditions[] = [
        'field' => 'type.code',
        'value' => $options['types'],
        'operator' => 'OR',
      ];
    }

    // Filter by disaster id.
    if (!empty($options['ids'])) {
      $conditions[] = [
        'field' => 'id',
        'value' => $options['ids'],
        'operator' => 'OR',
      ];
    }

    // Filter by date.
    if (!empty($options['from'])) {
      $conditions[] = [
        'field' => 'date.created',
        'value' => [
          'from' => gmdate('c', $options['from']),
        ],
      ];
    }

    // Add the combined filter to the payload.
    if (count($conditions) === 1) {
      $payload['filter'] = $conditions[0];
    }
    else {
      $payload['filter'] = [
        'conditions' => $conditions,
        'operator' => 'AND',
      ];
    }

    // Return a query that could be used with reliefweb_api_query_multiple().
    if ($mode === 'query') {
      return [
        'title' => $title,
        'resource' => 'disasters',
        'payload' => $payload,
        'callback' => [$this, 'parseDisasterMapApiData'],
      ];
    }

    // Get the disasters.
    $data = $this->reliefWebApiClient->request('disasters', $payload);
    $entities = self::parseDisasterMapApiData($data);

    // Limit the statuses for the legend to those of the disasters that would be
    // displayed.
    $statuses = [];
    foreach ($entities as $entity) {
      $statuses[$entity['status']] = TRUE;
    }

    // Skip if there is no content.
    if (empty($entities)) {
      return $mode === 'data' ? NULL : '';
    }

    // Wrap the page data.
    $bundle = 'disaster';

    $labels = [
      'status' => [
        'alert' => $this->t('Alert'),
        'ongoing' => $this->t('Ongoing'),
        'past' => $this->t('Past disaster'),
      ],
    ];

    $render_array = [
      '#theme' => 'reliefweb_disaster_map',
      '#id' => Html::getId($id),
      '#title' => $title,
      '#settings' => [
        'legend' => array_intersect_key($legend, $statuses),
        'close' => $this->t('Close'),
        'fitBounds' => TRUE,
      ],
      '#entities' => $entities,
      '#bundle' => $bundle,
      '#labels' => $labels,
    ];

    if ($mode === 'html') {
      return $this->renderer->renderRoot($render_array);
    }

    return $render_array;
  }

  /**
   * Parse the API data for the disaster map.
   *
   * @param array $api_data
   *   API data.
   *
   * @return array
   *   List of disaster entities wrapped in a RWPageDataWrapper.
   */
  public static function parseDisasterMapApiData(array $api_data) {
    $items = $api_data['items'] ?? $api_data['data'] ?? [];

    // Parse the entities retrieved from the API.
    // We group the disasters by primary country and add other disasters
    // affecting the same primary country as references.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];
      $data = [];

      // Skip if there are no locations for the primary country.
      // That should never happen though.
      if (empty($fields['primary_country']['location'])) {
        continue;
      }

      // Url.
      $data['url'] = $fields['url_alias'] ?? urlencode('taxonomy/term/' . $item['id']);

      // Title.
      $data['title'] = $fields['name'];

      // Status.
      $data['status'] = $fields['status'] === 'current' ? 'ongoing' : $fields['status'];

      // Primary type, default to Other if not defined.
      $data['type'] = $fields['primary_type']['code'] ?? 'OT';

      // There is already a more recent disaster affecting the primary country
      // so we simply add this disaster as additional disaster reference.
      $primary_country_id = $fields['primary_country']['id'];
      if (isset($entities[$primary_country_id])) {
        $entities[$primary_country_id]['references'][] = $data;
      }
      else {
        // Disaster location (= centroid coordinates of the primary country).
        $data['location'] = $fields['primary_country']['location'];

        // Summary.
        if (!empty($fields['profile']['overview-html'])) {
          $data['summary'] = HtmlSummarizer::summarize($fields['profile']['overview-html'], 260);
        }

        $entities[$primary_country_id] = $data;
      }
    }

    return $entities;
  }

}
