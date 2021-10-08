<?php

namespace Drupal\reliefweb_rivers\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_rivers\Parameters;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the reliefweb_rivers.search.results route.
 */
class SearchResults extends ControllerBase {

  /**
   * The ReliefWeb API client.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $reliefWebApiClient;

  /**
   * Search query.
   *
   * @var string
   */
  protected $searchQuery;

  /**
   * Constructor.
   *
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $reliefweb_api_client
   *   The reliefweb api client service.
   */
  public function __construct(ReliefWebApiClient $reliefweb_api_client) {
    $this->reliefWebApiClient = $reliefweb_api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_api.client')
    );
  }

  /**
   * Get the page content.
   *
   * @return array
   *   Render array for the homepage.
   */
  public function getPageContent() {
    $totals = [];
    $sections = [];
    $search = $this->getSearchQuery();

    if (!empty($search)) {
      // API queries.
      $queries = [
        'reports' => $this->getRiverApiQuery('report'),
        'jobs' => $this->getRiverApiQuery('job'),
        'training' => $this->getRiverApiQuery('training'),
        'disasters' => $this->getRiverApiQuery('disaster'),
        'organizations' => $this->getRiverApiQuery('source'),
        'countries' => $this->getRiverApiQuery('country'),
      ];

      // @todo replace with a switch and formatPlural?
      $labels = [
        'reports' => [$this->t('report'), $this->t('reports')],
        'jobs' => [$this->t('job'), $this->t('jobs')],
        'training' => [$this->t('training'), $this->t('training')],
        'disasters' => [$this->t('disaster'), $this->t('disasters')],
        'organizations' => [$this->t('organization'), $this->t('organizations')],
        'countries' => [$this->t('country'), $this->t('countries')],
      ];

      // Get the API data.
      $results = $this->reliefWebApiClient
        ->requestMultiple(array_filter($queries), TRUE);

      // Parse the API results, building the page sections data.
      foreach ($results as $index => $result) {
        $query = $queries[$index];

        if (empty($result['data'])) {
          continue;
        }

        $total = $result['totalCount'];
        $bundle = $query['bundle'];
        $view = $query['view'] ?? '';
        $exclude = $query['exclude'] ?? [];

        $entities = RiverServiceBase::getRiverData($bundle, $result, $view, $exclude);
        if (empty($entities)) {
          continue;
        }

        $sections[$index] = [
          '#theme' => 'reliefweb_rivers_river',
          '#id' => $index,
          '#title' => $query['title'],
          '#results' => [
            // @todo create helper that properly format plural AND format the
            // number.
            '#markup' => '<p>' . $this->formatPlural($total, '1 entry found', '@total entries found', [
              '@total' => number_format($total),
            ]) . '</p>',
          ],
          '#resource' => $query['resource'],
          '#entities' => $entities,
          '#more' => $query['more'] ?? NULL,
        ];

        $totals[$index] = [
          'label' => $labels[$index][$total > 1 ? 1 : 0],
          'total' => $total,
        ];
      }
    }

    return [
      '#theme' => 'reliefweb_rivers_search_results',
      '#title' => $this->t('Search results'),
      '#search' => $this->getSearch(),
      '#totals' => $totals,
      '#sections' => $sections,
    ];
  }

  /**
   * Get the API payload to get the reports.
   *
   * @param string $bundle
   *   Entity bundle of the river.
   * @param int $limit
   *   Number of headlines to return.
   *
   * @return array
   *   API Payload.
   */
  public function getRiverApiQuery($bundle, $limit = 3) {
    $service = RiverServiceBase::getRiverService($bundle);
    $resource = $service->getResource();
    $title = $service->getPageTitle();
    $search = $this->getSearchQuery();

    $payload = $service->getApiPayload();
    $payload['query']['value'] = $this->getSearchQuery();

    // The country river is not searchable, so ensure we get all the countries
    // matching the query.
    if ($bundle === 'country') {
      $payload['limit'] = 1000;
      $more = NULL;
    }
    // Otherwise add a link to the full river for the entity bundle, with the
    // search query.
    else {
      $payload['limit'] = $limit;
      $more = [
        'url' => RiverServiceBase::getRiverUrl($bundle, [
          'search' => $search,
        ]),
        'label' => $this->t('View all matching @resources', [
          '@resources' => mb_strtolower($title),
        ]),
      ];
    }

    return [
      'resource' => $resource,
      'bundle' => $bundle,
      'payload' => $payload,
      'title' => $title,
      'more' => $more,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSearch() {
    return [
      '#theme' => 'reliefweb_rivers_search',
      '#path' => '/search/results',
      '#label' => $this->t('Search ReliefWeb'),
      '#query' => $this->getSearchQuery(),
    ];
  }

  /**
   * Get the search query from the URL parameter.
   *
   * @return string
   *   Search query.
   */
  public function getSearchQuery() {
    if (!isset($this->searchQuery)) {
      $parameters = Parameters::getParameters();
      if (isset($parameters['search'])) {
        $this->searchQuery = trim($parameters['search']);
      }
      else {
        $this->searchQuery = '';
      }
    }
    return $this->searchQuery;
  }

}
