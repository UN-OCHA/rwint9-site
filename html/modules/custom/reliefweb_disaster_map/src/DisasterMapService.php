<?php

namespace Drupal\reliefweb_disaster_map;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Template\Attribute;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_rivers\Services\DisasterRiver;

/**
 * ReliefWeb disaster map service.
 */
class DisasterMapService {

  use StringTranslationTrait;

  /**
   * ReliefWeb Disaster Map config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The ReliefWeb Disaster River service.
   *
   * @var \Drupal\reliefweb_rivers\Services\DisasterRiver
   */
  protected $disasterRiver;

  /**
   * Renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\reliefweb_rivers\Services\DisasterRiver $disaster_river
   *   The reliefweb disaster river service.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The reliefweb api client service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, DisasterRiver $disaster_river, Renderer $renderer) {
    $this->config = $config_factory->get('reliefweb_disaster_map.settings');
    $this->disasterRiver = $disaster_river;
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
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The disaster map rendered HTML.
   */
  public function getDisasterMap($id, $title, array $options = []) {
    $id = Html::getUniqueId($id);

    $legend = [
      'ongoing' => $this->t('Red markers indicate ongoing situations.'),
      'alert' => $this->t('Orange markers indicate disaster alerts.'),
      'past' => $this->t('Grey markers indicate events that are no longer considered emergencies.'),
    ];

    $payload = $this->disasterRiver->getApiPayload();
    $payload['fields']['include'][] = 'profile.overview-html';
    $payload['fields']['include'][] = 'primary_country';
    $payload['limit'] = 200;

    // Query conditions.
    $conditions = [];

    // Filter by status.
    if (!empty($options['statuses'])) {
      $statuses = [];
      foreach ($options['statuses'] as $status) {
        switch ($status) {
          case 'current':
          case 'ongoing':
            // Current is the legacy ongoing status. Add both for compatibility.
            $statuses['current'] = 'current';
            $statuses['ongoing'] = 'ongoing';
            break;

          case 'alert':
          case 'part':
            $statuses[$status] = $status;
            break;
        }
      }

      $conditions[] = [
        'field' => 'status',
        'value' => $statuses,
        'operator' => 'OR',
      ];
    }

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
    $payload['filter'] = [
      'conditions' => $conditions,
      'operator' => 'AND',
    ];

    // Get the disasters.
    $data = $this->disasterRiver->requestApi($payload);

    // We group the disasters by primary country and add other disasters
    // affecting the same primary country as related.
    $entities = [];
    foreach ($this->disasterRiver->parseApiData($data ?? []) as $entity) {
      // Get the primary country.
      $primary_country_id = NULL;
      foreach ($entity['tags']['country'] as $country) {
        if (!empty($country['main'])) {
          $primary_country_id = $country['id'];
          break;
        }
      }

      // Skip if there is no primary country or no locations for the disaster
      // as we will not be able to render it on the map. This should never
      // happen though.
      if (empty($primary_country_id) || empty($entity['location'])) {
        continue;
      }

      // There is already a more recent disaster affecting the primary country
      // so we simply add this disaster as a related disaster.
      if (isset($entities[$primary_country_id])) {
        $entities[$primary_country_id]['related_disasters'][] = $entity;
      }
      else {
        $entities[$primary_country_id] = $entity;
      }
    }

    // Skip if there is no content.
    if (empty($entities)) {
      return '';
    }

    // Limit the statuses for the legend to those of the disasters that would be
    // displayed.
    $statuses = [];
    foreach ($entities as $entity) {
      $statuses[$entity['status']] = TRUE;
    }

    // Map settings.
    $settings = [
      'legend' => array_intersect_key($legend, $statuses),
      'close' => $this->t('Close'),
      'fitBounds' => TRUE,
    ];

    // We use the reliefweb river template with a few additional attributes
    // and attaching the disaster map library to convert the river to a map.
    $render_array = [
      '#theme' => 'reliefweb_disaster_map',
      '#id' => $id,
      '#title' => $title,
      '#attributes' => new Attribute([
        'data-disaster-map' => $id,
        'data-map-enabled' => '',
      ]),
      '#river_attributes' => new Attribute([
        'data-map-content' => '',
      ]),
      '#entities' => $entities,
      '#attached' => [
        'library' => [
          'reliefweb_disaster_map/map',
        ],
        'drupalSettings' => [
          'reliefwebDisasterMap' => [
            'mapboxKey' => $this->config->get('mapbox_key') ?? '',
            'mapboxToken' => $this->config->get('mapbox_token') ?? '',
            'maps' => [
              $id => $settings,
            ],
          ],
        ],
      ],
      '#cache' => [
        'tag' => [
          'taxonomy_term_list:disaster',
        ],
      ],
    ];

    return $this->renderer->render($render_array);
  }

  /**
   * Get the map of the latest alert and ongoing diasters.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The disaster map rendered HTML.
   */
  public static function getAlertAndOngoingDisasterMap() {
    return [
      '#markup' => \Drupal::service('reliefweb_disaster_map.service')
        ->getDisasterMap('disaster-map', t('Alert and Ongoing Disasters'), [
          'statuses' => ['alert', 'ongoing'],
        ]),
    ];
  }

}
