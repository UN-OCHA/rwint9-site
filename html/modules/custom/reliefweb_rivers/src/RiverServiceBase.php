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
   * River URL used to construct the service.
   *
   * @var string|null
   */
  protected $originalUrl = NULL;

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
   * @param string|null $url
   *   Optional river URL with parameters from which to construct the service.
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
    TranslationInterface $string_translation,
    $url = NULL
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
    $this->originalUrl = $url;
  }

  /**
   * {@inheritdoc}
   */
  public function createNewInstanceFromUrl($url = NULL) {
    return new static(
      $this->configFactory,
      $this->currentUser,
      $this->languageManager,
      $this->pagerManager,
      $this->pagerParameters,
      $this->apiClient,
      $this->requestStack,
      $this->renderer,
      $this->stringTranslation,
      $url
    );
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getDefaultPageTitle();

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
  abstract public function getDefaultRiverDescription();

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
  public function getPageTitle() {
    $title = $this->getDefaultPageTitle();
    $view = $this->getSelectedView();

    if (!$this->useDefaultTitle()) {
      $selection = $this->getAdvancedSearch()->getAdvancedSearchFilterSelection();

      if (count($selection) == 2) {
        $title = $this->t('@label1 - @label2 @title', [
          '@label1' => $selection[0]['label'],
          '@label2' => $selection[1]['label'],
          '@title' => $title,
        ]);
      }
      elseif (!empty($selection)) {
        $title = $this->t('@label1 @title', [
          '@label1' => $selection[0]['label'],
          '@title' => $title,
        ]);
      }
    }

    // Add the selected view to the default title if not the default one.
    if ($view !== $this->getDefaultView()) {
      $title = $this->t('@title (@view)', [
        '@title' => $title,
        '@view' => $this->getViewLabel($view),
      ]);
    }

    return $title;
  }

  /**
   * {@inheritdoc}
   */
  public function useDefaultTitle() {
    $skip = TRUE;

    // Skip if there is a search query as there can be infinite variations and
    // it may change completely the meaning of the selected filters.
    if (empty($this->getSearch())) {
      $selection = $this->getAdvancedSearch()->getAdvancedSearchFilterSelection();
      $view = $this->getSelectedView();
      $allowed_types = $this->getAllowedFilterTypesForTitle();
      $excluded_codes = $this->getExcludedFilterCodesForTitle();

      // If there is a view, we only generate a title with the first selection.
      // Otherwise we accept a second selected filter as part of the title.
      // This is to reduce allowed combinations and to avoid weirdness like
      // selected "fee-based" on the Training (Free courses) river.
      $max_allowed_filters = $view !== $this->getDefaultView() ? 1 : 2;

      // Check if we should avoid computing a title and use the default one.
      $skip = empty($selection) ||
        count($selection) > $max_allowed_filters ||
        // Ensure the selection doesn't start with an exlusion filter (without).
        $selection[0]['operator'] !== 'with' ||
        !isset($allowed_types[$selection[0]['type']]) ||
        isset($excluded_codes[$selection[0]['code']]);

      if (isset($selection[1])) {
        $skip = $skip ||
          // We only allow combinations of different filter types, not values.
          $selection[1]['code'] === $selection[0]['code'] ||
          // We only allow simple "A and B" combinations.
          $selection[1]['operator'] !== 'and-with' ||
          !isset($allowed_types[$selection[1]['type']]) ||
          isset($excluded_codes[$selection[1]['code']]);
      }
    }
    return $skip;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedFilterTypesForTitle() {
    // We allow term reference filters and fixed values (like "free") because
    // they have labels that work relatively when combined and have relatively
    // limited number of items limiting the number of combinations.
    return ['reference' => TRUE, 'fixed' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function getExcludedFilterCodesForTitle() {
    // We skip the organization type filter because the terms don't
    // associate well with the river title and also, there is no direct
    // link to the organization type from most pages (sources use a URL
    // with a search parameter).
    return ['OT' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function getCanonicalUrl() {
    $title = '';
    $parameters = [];
    $advanced_search_parameter = $this->getAdvancedSearch()->getParameter();

    // By adding the view to the canonical URL, we can make those variants
    // more easily discoverable.
    $view = $this->getSelectedView();
    if ($view !== $this->getDefaultView()) {
      $title = $this->getPageTitle();
      $parameters['view'] = $view;
    }

    // For some combinations of filters (ex: Afghanistan and Situation Report),
    // we use a custom title and canonical URL so they can be indexed by search
    // engines as their own pages.
    // For other sets of fitlers, we ignore them and use the canonical URL of
    // the page without filters.
    // @see https://developers.google.com/search/blog/2014/02/faceted-navigation-best-and-5-of-worst
    if (!$this->useDefaultTitle()) {
      $title = $this->getPageTitle();
      $parameters['advanced-search'] = $advanced_search_parameter;
      $add_page = TRUE;
    }
    else {
      $add_page = empty($this->getSearch()) && empty($advanced_search_parameter);
    }

    // Apparently, it is recommended to keep the `page` parameter for a
    // paginated sequence so that they have their own canonical URL instead
    // of pointing at the first page of the listing.
    // @see https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading#use-urls-correctly
    if ($add_page) {
      $page = $this->pagerManager->getPager()?->getCurrentPage();
      if (!empty($page)) {
        $parameters['page'] = $page;
      }
    }

    // @todo We may want to add those URLs to the sitemap.
    // @see https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls#sitemap-method
    return static::getRiverUrl($this->getBundle(), $parameters, $title, FALSE, TRUE);
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
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getParameters() {
    if (!isset($this->parameters)) {
      if (!empty($this->originalUrl)) {
        $this->parameters = Parameters::createFromUrl($this->originalUrl);
      }
      else {
        $this->parameters = new Parameters();
      }
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
    $view = $this->getParameters()->getString('view');
    return $this->validateView($view) ?? $this->getDefaultView();
  }

  /**
   * {@inheritdoc}
   */
  public function setSelectedView($view) {
    $view = $this->validateView($view) ?? $this->getSelectedView();
    if ($view === $this->getDefaultView()) {
      $this->getParameters()->remove('view');
    }
    else {
      $this->getParameters()->set('view', $view);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateView($view) {
    $views = $this->getViews();
    return isset($views[$view]) ? $view : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getViewLabel($view) {
    return $this->getViews()[$view] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getSearch() {
    return $this->getParameters()->getString('search');
  }

  /**
   * {@inheritdoc}
   */
  public function setSearch($search) {
    $this->getParameters()->set('search', $search, TRUE);
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
        ], $title);
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
        'tags' => $this->getRiverCacheTags(),
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
        'query' => $this->getParameters()->getAllSorted(['list']),
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
    $parameters = $this->getParameters()->getAllSorted(['list']);
    $search_url = static::getRiverUrl($this->getBundle(), $parameters, '', FALSE, TRUE);
    $options = [
      'query' => [
        'appname' => 'rwint-user-' . $this->currentUser->id(),
        'search-url' => $search_url,
      ],
    ];

    // Use the URL from the config if defined.
    $url = $this->configFactory
      ->get('reliefweb_rivers.settings')
      ->get('search_converter_url');

    if (!empty($url)) {
      return Url::fromUri($url, $options);
    }

    // Otherwise use the search converter route.
    return Url::fromRoute('reliefweb_rivers.search.converter', [], $options);
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
  public function prepareApiRequest($limit = 20, $paginated = TRUE, $view = NULL) {
    $view = $this->validateView($view) ?? $this->getSelectedView();
    $page = $paginated ? $this->pagerParameters->findPage() : 0;
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
    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiData($limit = 20, $paginated = TRUE, array $payload = NULL, $view = NULL) {
    $view = $this->validateView($view) ?? $this->getSelectedView();
    $payload = $payload ?? $this->prepareApiRequest($limit, $paginated, $view);

    // Retrieve the API data.
    $data = $this->requestApi($payload, $paginated);

    // Skip if there is no data.
    if (empty($data)) {
      return [];
    }

    // Initialize the pager.
    if ($paginated) {
      $this->pagerManager->createPager($data['totalCount'] ?? 0, $limit);
    }

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
        'contexts' => [
          'url.query_args:advanced-search',
          'url.query_args:search',
          'url.query_args:view',
          'url.query_args.pagers:0',
        ],
        'tags' => $this->getRiverCacheTags(),
      ],
    ];

    $headers = [
      'Content-Type' => 'application/rss+xml; charset=utf-8',
    ];

    // Add the cache control header.
    $cache_settings = $this->configFactory->get('system.performance')?->get('cache');
    if (!empty($cache_settings['page']['max_age']) && $cache_settings['page']['max_age'] > 0) {
      $headers['Cache-Control'] = 'max-age=' . $cache_settings['page']['max_age'] . ', public';
    }
    else {
      $headers['Cache-Control'] = 'private';
    }

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
  public function getRiverDescription() {
    $title = $this->getDefaultPageTitle();
    $search = $this->getSearch();
    $filters = $this->getAdvancedSearch()->getHumanReadableSelection();

    $view = $this->getSelectedView();
    if ($view !== $this->getDefaultView()) {
      $title = $this->t('@title (@view)', [
        '@title' => $title,
        '@view' => $this->getViewLabel($view),
      ]);
    }

    if (!empty($search) && !empty($filters)) {
      $description = $this->t('@title containing @search and @filters', [
        '@title' => $title,
        '@search' => $search,
        '@filters' => $filters,
      ]);
    }
    elseif (!empty($search)) {
      $description = $this->t('@title containing @search', [
        '@title' => $title,
        '@search' => $search,
      ]);
    }
    elseif (!empty($filters)) {
      $description = $this->t('@title @filters', [
        '@title' => $title,
        '@filters' => $filters,
      ]);
    }
    elseif ($view !== $this->getDefaultView()) {
      $description = $title;
    }
    else {
      $description = $this->getDefaultRiverDescription();
    }

    return $description;
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
  public static function getRiverUrl($bundle, array $parameters = [], $title = '', $partial_title = FALSE, $absolute = FALSE) {
    try {
      $url = Url::fromRoute('reliefweb_rivers.' . $bundle . '.river', []);
    }
    catch (RouteNotFoundException $exception) {
      return '';
    }

    $title = !empty($partial_title) ? static::getRiverUrlTitle($bundle, $title) : $title;
    if (!empty($title)) {
      $parameters = ['list' => $title] + $parameters;
    }

    return $url
      ->setOption('query', $parameters)
      ->setOption('absolute', $absolute)
      ->toString();
  }

  /**
   * {@inheritdoc}
   */
  public static function getRiverUrlTitle($bundle, $prefix) {
    if (!empty($prefix)) {
      switch ($bundle) {
        case 'blog_post':
          return t('@prefix Blog Posts', ['@prefix' => $prefix]);

        case 'country':
          return t('@prefix Countries', ['@prefix' => $prefix]);

        case 'disaster':
          return t('@prefix Disasters', ['@prefix' => $prefix]);

        case 'job':
          return t('@prefix Jobs', ['@prefix' => $prefix]);

        case 'source':
          return t('@prefix Organizations', ['@prefix' => $prefix]);

        case 'topic':
          return t('@prefix Topics', ['@prefix' => $prefix]);

        case 'training':
          return t('@prefix Training Opportunities', ['@prefix' => $prefix]);

        case 'report':
          return t('@prefix Updates', ['@prefix' => $prefix]);
      }
    }
    return '';
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
   * @return \Drupal\reliefweb_rivers\RiverServiceInferface|null
   *   If there is a match, then the service for the corresponding river,
   *   NULL otherwise.
   */
  public static function getRiverServiceFromUrl($url) {
    static $instances = [];
    if (is_string($url)) {
      if (!isset($instances[$url])) {
        $mapping = static::getRiverMapping();
        $path = trim(parse_url($url, PHP_URL_PATH), '/');

        if (isset($mapping[$path]['bundle'])) {
          $data = $mapping[$path];
          $service = static::getRiverService($data['bundle']);

          if (isset($service)) {
            // Create a new copy of the service with the given URL.
            $service = $service->createNewInstanceFromUrl($url);
            // Override the view.
            if (isset($data['view'])) {
              $service->setSelectedView($data['view']);
            }
            $instances[$url] = $service;
          }
        }
      }
      return $instances[$url] ?? NULL;
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverCacheTags() {
    return [
      $this->getEntityTypeId() . '_list:' . $this->getBundle(),
      'taxonomy_term_list',
    ];
  }

}
