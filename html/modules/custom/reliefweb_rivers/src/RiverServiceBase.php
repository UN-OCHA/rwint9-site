<?php

namespace Drupal\reliefweb_rivers;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Base for river services.
 */
abstract class RiverServiceBase implements RiverServiceInterface {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The pager manager servie.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * The pager parameters service.
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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

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
   * The entity type id associated with the river.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The entity bundle associated with the river.
   *
   * @var string
   */
  protected $bundle;

  /**
   * The river URL.
   *
   * @var string
   */
  protected $url;

  /**
   * The river parameter handler.
   *
   * @var \Drupal\reliefweb_rivers\Parameters
   */
  protected $parameters;

  /**
   * The advanced search handler.
   *
   * @var \Drupal\reliefweb_rivers\AdvancedSearch
   */
  protected $advancedSearch;

  /**
   * Maximum number of resources to display in the river.
   *
   * @var int
   */
  protected $limit = 20;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager service.
   * @param \Drupal\Core\Pager\PagerParametersInterface $pager_parameters
   *   The pager parameter service.
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $api_client
   *   The ReliefWeb API Client service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager,
    PagerManagerInterface $pager_manager,
    PagerParametersInterface $pager_parameters,
    ReliefWebApiClient $api_client,
    RequestStack $request_stack,
    RendererInterface $renderer,
    TranslationInterface $string_translation
  ) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->pagerManager = $pager_manager;
    $this->pagerParameters = $pager_parameters;
    $this->apiClient = $api_client;
    $this->requestStack = $request_stack;
    $this->renderer = $renderer;
    $this->stringTranslation = $string_translation;
    $this->url = static::getRiverUrl($this->getBundle());
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
  abstract public function getFilters();

  /**
   * {@inheritdoc}
   */
  abstract public function getApiPayload($view = '');

  /**
   * {@inheritdoc}
   */
  abstract public function parseApiData(array $api_data, $view = '');

  /**
   * {@inheritdoc}
   */
  public function getRiver() {
    return $this->river;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return $this->bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return $this->entityTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function getResource() {
    return $this->resource;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    return $this->url;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    $river = Html::getId($this->getRiver());

    return [
      '#theme' => 'reliefweb_rivers_page__' . $river,
      '#river' => $this->getRiver(),
      '#title' => $this->getPageTitle(),
      '#view' => $this->getSelectedView(),
      '#views' => $this->getRiverViews(),
      '#search' => $this->getRiverSearch(),
      '#advanced_search' => $this->getRiverAdvancedSearch(),
      '#content' => $this->getRiverContent(),
      '#links' => $this->getRiverLinks(),
      '#cache' => [
        'keys' => [
          'reliefweb',
          'rivers',
          'page',
          $river,
        ],
      ],
      '#cache_properties' => [
        '#river',
        '#title',
        '#view',
        '#views',
        '#search',
        '#advanced_search',
        '#content',
        '#links',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getParameters() {
    if (!isset($this->parameters)) {
      $this->parameters = new Parameters();
    }
    return $this->parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function getAdvancedSearch() {
    if (!isset($this->advancedSearch)) {
      $this->advancedSearch = new AdvancedSearch(
        $this->getBundle(),
        $this->getRiver(),
        $this->getParameters(),
        $this->getFilters(),
        $this->getFilterSample()
      );
    }
    return $this->advancedSearch;
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
    $view = $this->getParameters()->get('view');
    $views = $this->getViews();
    return isset($views[$view]) ? $view : $this->getDefaultView();
  }

  /**
   * {@inheritdoc}
   */
  public function getSearch() {
    $search = $this->getParameters()->get('search', '');
    return trim($search);
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverViews() {
    $views = $this->getViews();
    if (empty($views)) {
      return [];
    }

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
        $item['url'] = $this->getUrl();
      }
      else {
        $item['url'] = static::getRiverUrl($this->getBundle(), [
          'view' => $id,
        ]);
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
      '#cache' => [
        '#contexts' => [
          'url.query_args:view',
        ],
      ],
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
    $advanced_search_parameter = $this->getAdvancedSearch()->getParameter();
    if (!empty($advanced_search_parameter)) {
      $parameters['advanced-search'] = $advanced_search_parameter;
    }

    return [
      '#theme' => 'reliefweb_rivers_search',
      '#path' => $this->getUrl(),
      '#parameters' => $parameters,
      '#label' => $this->t('Search for @river with keywords', [
        '@river' => $this->getRiver(),
      ]),
      '#query' => $search,
      '#cache' => [
        '#contexts' => [
          'url.query_args:search',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverAdvancedSearch() {
    $advanced_search = $this->getAdvancedSearch();

    $settings = $advanced_search->getSettings();
    if (empty($settings['filters'])) {
      return [];
    }

    return [
      '#theme' => 'reliefweb_rivers_advanced_search',
      '#title' => $this->t('Refine the list with filters'),
      '#path' => $this->getUrl(),
      '#parameter' => $advanced_search->getParameter(),
      '#selection' => $advanced_search->getSelection(),
      '#remove' => $advanced_search->getClearUrl(),
      '#settings' => $advanced_search->getSettings(),
      '#cache' => [
        '#contexts' => [
          'url.query_args:advanced-search',
        ],
        // The advanced search filters use terms so we need to make sure the
        // cache is properly invalidated when they change.
        '#tags' => [
          'taxonomy_term_list',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverContent() {
    // Get the resources for the search query.
    $entities = $this->getApiData($this->limit);

    return [
      '#theme' => 'reliefweb_rivers_river',
      '#id' => 'river-list',
      '#title' => $this->t('List'),
      '#results' => $this->getRiverResults(count($entities)),
      '#entities' => $entities,
      '#pager' => $this->getRiverPager(),
      '#empty' => $this->t('No results found. Please modify your search or filter selection.'),
      '#cache' => [
        'tags' => [
          $this->getEntityTypeId() . '_list:' . $this->getBundle(),
          'taxonomy_term_list',
        ],
      ],
    ];
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
    // The renderer will automatically populate the render array for the pager
    // and notably add the cache elements including 'url.query_args.pagers:0'.
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
      '#links' => array_filter([
        'rss' => $this->getRssLink(),
        'api' => $this->getApiLink(),
      ]),
      '#cache' => [
        '#contexts' => [
          'url.query_args:advanced-search',
          'url.query_args:search',
          'url.query_args:view',
          'url.query_args.pagers:0',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRssLink() {
    try {
      return Url::fromRoute('reliefweb_rivers.' . $this->getBundle() . '.rss', [], [
        'query' => $this->getParameters()->get(),
        'absolute' => TRUE,
      ])->toString();
    }
    catch (RouteNotFoundException $exception) {
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getApiLink() {
    $url = $this->configFactory
      ->get('reliefweb_rivers.settings')
      ->get('search_converter_url');

    if (empty($url)) {
      return '';
    }

    $parameters = $this->getParameters()->get();
    $search_url = static::getRiverUrl($this->getBundle(), $parameters, TRUE);

    return Url::fromUri($url, [
      'query' => [
        'appname' => 'rwint-user-' . $this->currentUser->id(),
        'search-url' => $search_url,
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterSample() {
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
    else {
      unset($payload['query']);
    }

    // Generate the API filter with the facet and advanced search filters.
    $filter = $this->getAdvancedSearch()->getApiFilter();
    if (!empty($filter)) {
      // Update the payload filter.
      if (!empty($payload['filter'])) {
        $payload['filter'] = [
          'conditions' => [
            $payload['filter'],
            $filter,
          ],
          'operator' => 'AND',
        ];
      }
      else {
        $payload['filter'] = $filter;
      }
    }

    // Retrieve the API data.
    $data = $this->requestApi($payload);

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
  public function requestApi(array $payload) {
    return $this->apiClient->request($this->resource, $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getRssContent() {
    $river = Html::getId($this->getRiver());
    $request = $this->requestStack->getCurrentRequest();
    $items = $this->getApiDataForRss();
    $first = reset($items);
    $date = $first['date'] ?? static::createDate('now');

    $content = [
      '#theme' => 'reliefweb_rivers_rss__' . $river,
      '#site_url' => $request->getSchemeAndHttpHost(),
      '#title' => $this->getPageTitle(),
      '#feed_url' => $request->getUri(),
      '#language' => $this->languageManager->getCurrentLanguage()->getId(),
      '#date' => $date,
      '#items' => $items,
      '#cache' => [
        'keys' => [
          'reliefweb',
          'rivers',
          'rss',
          $river,
        ],
        'contexts' => [
          'url.query_args:advanced-search',
          'url.query_args:search',
          'url.query_args:view',
          'url.query_args.pagers:0',
        ],
        'tags' => [
          $this->getEntityTypeId() . '_list:' . $this->getBundle(),
          'taxonomy_term_list',
        ],
      ],
      '#cache_properties' => [
        '#site_url',
        '#title',
        '#feed_url',
        '#language',
        '#date',
        '#items',
      ],
    ];

    $headers = [
      'Content-Type' => 'application/rss+xml; charset=utf-8',
      'Cache-Control' => 'private',
    ];

    return new Response($this->renderer->render($content), 200, $headers);
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDataForRss($limit = 20) {
    $view = $this->getSelectedView();

    $payload = $this->getApiPayloadForRss($view);
    $payload['limit'] = $limit;

    // Full text search.
    // @todo add the filtering from the advanced search.
    $search = $this->getSearch();
    if (!empty($search)) {
      $payload['query']['value'] = $search;
    }
    else {
      unset($payload['query']);
    }

    // Generate the API filter with the facet and advanced search filters.
    $filter = $this->getAdvancedSearch()->getApiFilter();
    if (!empty($filter)) {
      // Update the payload filter.
      if (!empty($payload['filter'])) {
        $payload['filter'] = [
          'conditions' => [
            $payload['filter'],
            $filter,
          ],
          'operator' => 'AND',
        ];
      }
      else {
        $payload['filter'] = $filter;
      }
    }

    // Retrieve the API data.
    $data = $this->requestApi($payload);

    // Skip if there is no data.
    if (empty($data)) {
      return [];
    }

    // Parse the API data and return the entities.
    return $this->parseApiDataForRss($data, $view);
  }

  /**
   * {@inheritdoc}
   */
  public function getApiPayloadForRss($view = '') {
    return $this->getApiPayload($view);
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiDataForRss(array $data, $view = '') {
    return [];
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

  /**
   * {@inheritdoc}
   */
  public static function getRiverUrl($bundle, array $parameters = [], $absolute = FALSE) {
    try {
      return Url::fromRoute('reliefweb_rivers.' . $bundle . '.river', [], [
        'query' => $parameters,
        'absolute' => $absolute,
      ])->toString();
    }
    catch (RouteNotFoundException $exception) {
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getRiverData($bundle, ?array $data, $view = '', array $exclude = []) {
    if (empty($data)) {
      return [];
    }

    $service = static::getRiverService($bundle);
    if (empty($service)) {
      return [];
    }

    // Parse the API data using the river service for the entity bundle.
    $entities = $service->parseApiData($data, $view);

    // If instructed so, remove some properties from the entities.
    if (!empty($exclude)) {
      $exclude = array_flip($exclude);
      foreach ($entities as $index => $entity) {
        $entities[$index] = array_diff_key($entity, $exclude);
      }
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public static function getRiverApiPayload($bundle, $view = '', array $exclude = ['query']) {
    $service = static::getRiverService($bundle);
    if (empty($service)) {
      return [];
    }

    $payload = $service->getApiPayload($view);

    // If instructed so, remove some properties from the payload.
    if (!empty($exclude)) {
      $exclude = array_flip($exclude);
      $payload = array_diff_key($payload, $exclude);
    }

    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public static function getRiverService($bundle) {
    try {
      return \Drupal::service('reliefweb_rivers.' . $bundle . '.river');
    }
    catch (ServiceNotFoundException $exception) {
      return NULL;
    }
  }

  /**
   * Get the river path to river data mapping.
   *
   * This notably handles the legacy river paths like "maps".
   *
   * @return array
   *   Mapping with the river path as key and an array with the entity bundle
   *   associated with the river and an optional view for legacy paths.
   *
   * @todo check if nginx handles the redirections correctly.
   */
  public static function getRiverMapping() {
    return [
      'blog' => [
        'bundle' => 'blog_post',
      ],
      'countries' => [
        'bundle' => 'country',
      ],
      'disasters' => [
        'bundle' => 'disaster',
      ],
      'jobs' => [
        'bundle' => 'job',
      ],
      'organizations' => [
        'bundle' => 'source',
      ],
      'topics' => [
        'bundle' => 'topic',
      ],
      'training' => [
        'bundle' => 'training',
      ],
      'updates' => [
        'bundle' => 'report',
      ],
      // Legacy path.
      'maps' => [
        'bundle' => 'report',
        'view' => 'maps',
      ],
    ];
  }

  /**
   * Get the river service from a river URL.
   *
   * @param string $url
   *   River url.
   *
   * @return array
   *   If there is a match, then an array with the river service, associated
   *   entity bundle and view.
   */
  public static function getRiverServiceFromUrl($url) {
    $mapping = static::getRiverMapping();
    $path = trim(parse_url($url, PHP_URL_PATH), '/');

    if (isset($mapping[$path]['bundle'])) {
      $data = $mapping[$path];
      $service = static::getRiverService($data['bundle']);
      if (isset($service)) {
        return $data + ['service' => $service];
      }
    }
    return [];
  }

}
