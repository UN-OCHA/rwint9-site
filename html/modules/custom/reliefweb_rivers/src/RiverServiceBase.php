<?php

namespace Drupal\reliefweb_rivers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Base for river services.
 */
abstract class RiverServiceBase implements RiverServiceInterface {

  use StringTranslationTrait;

  /**
   * The pager manager servie.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * The pager parameters servie.
   *
   * @var \Drupal\Core\Pager\PagerParametersInterface
   */
  protected $pagerParameters;

  /**
   * The ReliefWeb API Client service.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $apiClient;

  /**
   * The river name.
   *
   * @var string
   */
  protected $river;

  /**
   * The API resource for the river.
   *
   * @var string
   */
  protected $resource;


  /**
   * The entity type associated with the resource.
   *
   * @var string
   */
  protected $entityType;

  /**
   * The entity bundle associated with the resource.
   *
   * @var string
   */
  protected $bundle;

  /**
   * Maximum number of resources to display in the river.
   *
   * @var int
   */
  protected $limit = 20;

  /**
   * Advanced search operator mapping.
   *
   * @var array
   */
  protected $advancedSearchOperators = [
    'with' => '(',
    'without' => '!(',
    'and-with' => ')_(',
    'and-without' => ')_!(',
    'or-with' => ').(',
    'or-without' => ').!(',
    'or' => '.',
    'and' => '_',
  ];

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager service.
   * @param \Drupal\Core\Pager\PagerParametersInterface $pager_parameters
   *   The pager parameter service.
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $api_client
   *   The ReliefWeb API Client service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(PagerManagerInterface $pager_manager, PagerParametersInterface $pager_parameters, ReliefWebApiClient $api_client, TranslationInterface $string_translation) {
    $this->pagerManager = $pager_manager;
    $this->pagerParameters = $pager_parameters;
    $this->apiClient = $api_client;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getPageTitle();

  /**
   * {@inheritdoc}
   */
  abstract public function getViews();

  /**
   * {@inheritdoc}
   */
  abstract public function getApiPayload($view = '');

  /**
   * {@inheritdoc}
   */
  abstract public function getApiFilters();

  /**
   * {@inheritdoc}
   */
  abstract public function parseApiData(array $api_data, $view = '');

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    // Get the resources for the search query.
    $entities = $this->getApiData($this->limit);

    return [
      '#theme' => 'reliefweb_rivers_page',
      '#river' => $this->river,
      '#title' => $this->getPageTitle(),
      '#view' => $this->getSelectedView(),
      '#entities' => $entities,
      '#views' => $this->getRiverViews(),
      '#search' => $this->getRiverSearch(),
      '#advanced_search' => $this->getRiverAdvancedSearch(),
      '#results' => $this->getRiverResults(count($entities)),
      '#pager' => $this->getRiverPager(),
      '#links' => $this->getRiverLinks(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultView() {
    return 'all';
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectedView() {
    $view = $this->getQueryParameter('view');
    $views = $this->getViews();
    return isset($views[$view]) ? $view : $this->getDefaultView();
  }

  /**
   * {@inheritdoc}
   */
  public function getSearch() {
    $search = $this->getQueryParameter('search', '');
    // @todo sanitize the parameter, also statically cache it?
    return $search;
  }

  /**
   * {@inheritdoc}
   */
  public function getAdvancedSearch() {
    $advanced_search = $this->getQueryParameter('advanced-search', '');
    // @todo sanitize the parameter, also statically cache it?
    return $advanced_search;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryParameter($parameter, $default = NULL) {
    $parameters = $this->pagerParameters->getQueryParameters();
    return $parameters[$parameter] ?? $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverViews() {
    $views = $this->getViews();
    $view = $this->getSelectedView();
    $default = $this->getDefaultView();

    foreach ($views as $id => $title) {
      $item = [
        'id' => $id,
        'title' => $title,
      ];

      // Set the view URL and mark the default one.
      if ($id === $default) {
        $item['default'] = TRUE;
        $item['url'] = UrlHelper::encodeUrl($this->river);
      }
      else {
        $item['url'] = UrlHelper::encodeUrl($this->river . '?view=' . $id);
      }

      // Mark the current view as selected.
      if ($id === $view) {
        $item['selected'] = TRUE;
      }
      $views[$id] = $item;
    }

    return [
      '#theme' => 'reliefweb_rivers_views',
      '#views' => $views,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverSearch() {
    $search = $this->getSearch();
    $parameters = [];

    // Add the selected view as parameter so it's preserved when submitting.
    $view = $this->getSelectedView();
    if (!empty($view) && $view !== $this->getDefaultView()) {
      $parameters['view'] = $view;
    }

    // Add the advanced search as parameter so it's preserved when submitting.
    $advanced_search = $this->getAdvancedSearch();
    if (!empty($advanced_search)) {
      $parameters['advanced-search'] = $advanced_search;
    }

    return [
      '#theme' => 'reliefweb_rivers_search',
      '#path' => UrlHelper::encodeUrl($this->river),
      '#parameters' => $parameters,
      '#label' => $this->t('Search for @resource with keywords', [
        '@resource' => $this->resource,
      ]),
      '#query' => $search,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverAdvancedSearch() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverResults($count) {
    $total = 0;
    $start = 0;
    $end = 0;

    $pager = $this->pagerManager->getPager();
    if (isset($pager)) {
      $page = $pager->getCurrentPage();
      $limit = $pager->getLimit();
      $total = $pager->getTotalItems();

      $offset = $page * $limit;
      // Range is inclusive so we start at 1.
      $start = $count > 0 ? $offset + 1 : 0;
      $end = $offset + $count;
    }

    return [
      '#theme' => 'reliefweb_rivers_results',
      '#total' => $total,
      '#start' => $start,
      '#end' => $end,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverPager() {
    return [
      '#type' => 'pager',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverLinks() {
    return [
      '#theme' => 'reliefweb_rivers_links',
      '#links' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilerSample() {
    return $this->t('(Country, organization...)');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiData($limit = 20) {
    $view = $this->getSelectedView();
    $page = $this->pagerParameters->findPage();
    $offset = $page * $limit;

    $payload = $this->getApiPayload($view);
    $payload['offset'] = $offset;
    $payload['limit'] = $limit;

    // Full text search.
    // @todo add the filtering from the advanced search.
    $search = $this->getSearch();
    if (!empty($search)) {
      $payload['query']['value'] = $search;
    }

    // Retrieve the API data.
    $data = $this->apiClient->request($this->resource, $payload);

    // Skip if there is no data.
    if (empty($data)) {
      return [];
    }

    // Initialize the pager.
    $this->pagerManager->createPager($data['totalCount'] ?? 0, $limit);

    // Parse the API data and return the entities.
    return $this->parseApiData($data, $view);
  }

  /**
   * {@inheritdoc}
   */
  public static function getLanguageCode(array &$data = NULL) {
    if (isset($data['langcode'])) {
      $langcode = $data['langcode'];
    }
    // Extract the main language code from the entity language tag.
    elseif (isset($data['tags']['language'])) {
      // English has priority over the other languages. If not present we
      // just get the first language code in the list.
      foreach ($data['tags']['language'] as $item) {
        if (isset($item['code'])) {
          if ($item['code'] === 'en') {
            $langcode = 'en';
            break;
          }
          elseif (!isset($langcode)) {
            $langcode = $item['code'];
          }
        }
      }
    }
    return $langcode ?? 'en';
  }

  /**
   * {@inheritdoc}
   */
  public static function createDate($date) {
    return new \DateTime($date, new \DateTimeZone('UTC'));
  }

}
