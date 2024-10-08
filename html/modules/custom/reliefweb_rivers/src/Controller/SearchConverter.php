<?php

namespace Drupal\reliefweb_rivers\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for the reliefweb_rivers.search.converter route.
 */
class SearchConverter extends ControllerBase {

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
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The ReliefWeb API client.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $reliefWebApiClient;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $reliefweb_api_client
   *   The reliefweb api client service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    FormBuilderInterface $form_builder,
    RequestStack $request_stack,
    ReliefWebApiClient $reliefweb_api_client,
  ) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->formBuilder = $form_builder;
    $this->requestStack = $request_stack;
    $this->reliefWebApiClient = $reliefweb_api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('form_builder'),
      $container->get('request_stack'),
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
    $search_url = $this->getSearchUrl();
    $appname = $this->getAppname();

    // We want the editors to be able to bookmark a moderation page with
    // a selection of filters so we set the method as GET.
    $form_state = new FormState();
    $form_state->setMethod('GET');
    $form_state->setProgrammed(TRUE);
    $form_state->setProcessInput(TRUE);
    $form_state->disableCache();
    $form_state->setValue('search-url', $search_url);
    $form_state->setValue('appname', $appname);

    // Build the filters form and remove unecessary elements.
    $form = $this->formBuilder
      ->buildForm('\Drupal\reliefweb_rivers\Form\SearchConverterForm', $form_state);
    foreach ($form_state->getCleanValueKeys() as $key) {
      $form[$key]['#access'] = FALSE;
    }

    // Parse the search URL to retrieve the API resource and payload.
    $data = $this->parseSearchUrl($search_url);
    if (!empty($data)) {
      $query = $this->getQueryString($data['payload']);

      $results = [
        'query' => Markup::create($query),
        'url' => Markup::create($this->getApiUrl($data['resource'], $query, $appname)),
        'payload' => Markup::create($this->getJsonPayload($data['payload'])),
        'php' => Markup::create($this->getPhpPayload($data['payload'])),
        'json_url' => Url::fromRoute('reliefweb_rivers.search.converter.json', [], [
          'query' => [
            'appname' => $appname,
            'search-url' => $search_url,
          ],
        ]),
      ];
    }

    return [
      '#theme' => 'reliefweb_rivers_search_converter',
      '#title' => $this->t('Search to API query converter'),
      '#form' => $form,
      '#results' => $results ?? [],
      '#cache' => [
        'contexts' => [
          'user',
          'url.query_args:appname',
          'url.query_args:search_url',
        ],
      ],
    ];
  }

  /**
   * Get the result of the conversion as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function getJsonResult() {
    $search_url = $this->getSearchUrl();
    $appname = $this->getAppname();
    $result = [
      'input' => [
        'appname' => $appname,
        'search_url' => $search_url,
      ],
    ];

    // Parse the search URL to retrieve the API resource and payload.
    $data = $this->parseSearchUrl($search_url);
    if (!empty($data)) {
      $query = $this->getQueryString($data['payload']);

      $result['output'] = [
        'query' => $query,
        'requests' => [
          'get' => [
            'url' => $this->getApiUrl($data['resource'], $query, $appname),
          ],
          'post' => [
            'url' => $this->getApiUrl($data['resource'], '', $appname),
            'payload' => $data['payload'],
          ],
        ],
      ];
    }

    $headers = [
      'Content-Type' => 'application/json; charset=utf-8',
    ];

    // Add the cache control header.
    $cache_settings = $this->configFactory->get('system.performance')?->get('cache');
    if (!empty($cache_settings['page']['max_age']) && $cache_settings['page']['max_age'] > 0) {
      $headers['Cache-Control'] = 'max-age=' . $cache_settings['page']['max_age'] . ', public';
    }
    else {
      $headers['Cache-Control'] = 'private';
    }

    try {
      return new JsonResponse($result, 200, $headers);
    }
    catch (\Exception $exception) {
      $this->getLogger('reliefweb_river')->error('Unable to generate search conversion JSON result: @error', [
        '@error' => $exception->getMessage(),
      ]);
      return new JsonResponse(['error' => $this->t('Invalid input')], 400, $headers);
    }
  }

  /**
   * Get the appname parameter.
   *
   * @return string
   *   The appname parameter.
   */
  protected function getAppname() {
    $appname = $this->requestStack->getCurrentRequest()->query->get('appname', '');
    $appname = is_string($appname) ? trim($appname) : '';
    return $appname ?: 'rw-user-' . $this->currentUser->id();
  }

  /**
   * Get the search URL parameter.
   *
   * @return string
   *   The search URL parameter.
   */
  protected function getSearchUrl() {
    $search_url = $this->requestStack->getCurrentRequest()->query->get('search-url', '');
    return is_string($search_url) ? trim($search_url) : '';
  }

  /**
   * Parse as search URL.
   *
   * @param string $search_url
   *   Search URL.
   *
   * @return array
   *   Associative array with the API resource and payload for the search URL.
   */
  protected function parseSearchUrl($search_url) {
    if (empty($search_url)) {
      return [];
    }

    $service = RiverServiceBase::getRiverServiceFromUrl($search_url);
    if (empty($service)) {
      return [];
    }

    // Get the payload ready for an API request.
    $payload = $service->prepareApiRequest();

    // Remove the fields and sort option as we, instead, add the preset and
    // profile to the API URL.
    unset($payload['query']['fields']);
    unset($payload['fields']);
    unset($payload['sort']);

    // Sanitize the payload.
    $payload = $this->reliefWebApiClient->sanitizePayload($payload, TRUE);

    // Add the preset and profile.
    $payload['preset'] = 'latest';
    $payload['profile'] = 'list';

    return [
      'resource' => $service->getResource(),
      'payload' => $payload,
    ];
  }

  /**
   * Get the API query string from the API payload.
   *
   * @param array $payload
   *   API payload.
   *
   * @return string
   *   The query string.
   */
  protected function getQueryString(array $payload) {
    if (empty($payload)) {
      return '';
    }

    // Join the full text search query and the filters.
    $filters = array_filter([
      !empty($payload['query']['value']) ? '(' . $payload['query']['value'] . ')' : '',
      !empty($payload['filter']) ? $this->stringifyFilter($payload['filter']) : '',
    ]);

    $query = '';
    if (count($filters) > 1) {
      $query = implode(' AND ', $filters);
    }
    elseif (!empty($filters)) {
      $query = $this->trimFilter(reset($filters));
    }
    return $query;
  }

  /**
   * Get the API url from the generated query string.
   *
   * @param string $resource
   *   API resource.
   * @param string $query
   *   Search query.
   * @param string $appname
   *   Application name.
   *
   * @return string
   *   API URL.
   */
  protected function getApiUrl($resource, $query, $appname) {
    $parameters = [
      'appname' => $appname,
      'profile' => 'list',
      'preset' => 'latest',
      'slim' => 1,
    ];
    if (!empty($query)) {
      $parameters['query']['value'] = $query;
      $parameters['query']['operator'] = 'AND';
    }

    return $this->reliefWebApiClient->buildApiUrl($resource, $parameters, FALSE);
  }

  /**
   * Get the API url from the generated query string.
   *
   * @param array $payload
   *   API payload.
   *
   * @return string
   *   JSON encoded payload.
   */
  protected function getJsonPayload(array $payload) {
    if (empty($payload)) {
      return '';
    }
    $json = json_encode($payload, \JSON_PRETTY_PRINT);
    return $json === FALSE ? '' : preg_replace('/ {4}/', '  ', $json);
  }

  /**
   * Get payload as PHP.
   *
   * @param array $payload
   *   API payload.
   *
   * @return string
   *   PHP payload.
   */
  protected function getPhpPayload(array $payload) {
    $export = var_export($payload, TRUE);
    $patterns = [
      "/array \(/" => '[',
      "/^([ ]*)\)(,?)$/m" => '$1]$2',
      "/=>[ ]?\n[ ]+\[/" => '=> [',
      "/([ ]*)(\'[^\']+\') => ([\[\'])/" => '$1$2 => $3',
    ];

    $export = preg_replace(array_keys($patterns), array_values($patterns), $export);

    return $export;
  }

  /**
   * Remove excessive outter parentheses.
   *
   * @param string $filter
   *   Stringified filter.
   *
   * @return string
   *   Filter with outer parentheses removed.
   */
  protected function trimFilter($filter) {
    return strpos($filter, '(') === 0 ? substr($filter, 1, strlen($filter) - 2) : $filter;
  }

  /**
   * Return the query string representation of an API filter.
   *
   * @param array $filter
   *   API filter.
   *
   * @return string
   *   Query representation of the filter.
   */
  protected function stringifyFilter(array $filter) {
    if (empty($filter)) {
      return '';
    }

    $result = '';
    $operator = ' ' . ($filter['operator'] ?? 'AND') . ' ';

    if (!empty($filter['conditions'])) {
      $group = [];

      foreach ($filter['conditions'] as $condition) {
        $group[] = $this->stringifyFilter($condition);
      }
      $result = '(' . implode($operator, $group) . ')';
    }
    elseif (empty($filter['value'])) {
      $result = '_exists_:' . $filter['field'];
    }
    else {
      $value = $filter['value'];

      if (is_array($value)) {
        // Date.
        if (isset($value['from']) || isset($value['to'])) {
          $from = isset($value['from']) ? substr($value['from'], 0, 10) : '';
          $to = isset($value['to']) ? substr($value['to'], 0, 10) : '';
          if (empty($from)) {
            $value = '<' . $to;
          }
          elseif (empty($to)) {
            $value = '>=' . $from;
          }
          else {
            $value = '[' . $from . ' TO ' . $to . '}';
          }
        }
        // Multiple values.
        else {
          $value = '(' . implode($operator, $value) . ')';
        }
      }

      $result = $filter['field'] . ':' . $value;
    }
    return (!empty($filter['negate']) ? 'NOT ' : '') . $result;
  }

}
