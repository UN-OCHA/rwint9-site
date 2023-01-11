<?php

namespace Drupal\reliefweb_bookmarks\Controller;

/**
 * @file
 * Contains \Drupal\reliefweb_bookmarks\Controller\UserBookmarksController.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A UserBookmarksController controller.
 */
class UserBookmarksController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Config factory..
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The ReliefWeb API client.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $reliefWebApiClient;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account, ConfigFactoryInterface $config_factory, Connection $database, ReliefWebApiClient $reliefweb_api_client, PagerManagerInterface $pager_manager) {
    $this->account = $account;
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->reliefWebApiClient = $reliefweb_api_client;
    $this->pagerManager = $pager_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('database'),
      $container->get('reliefweb_api.client'),
      $container->get('pager.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function bookmarks(UserInterface $user) {
    $config = $this->configFactory->get('reliefweb_bookmarks.settings');
    $uid = $user->id();

    $entity_types = [
      'node' => $config->get('node') ?? [],
      'taxonomy_term' => $config->get('taxonomy_term') ?? [],
    ];

    $queries = [];
    foreach ($entity_types as $entity_type => $bundles) {
      foreach ($bundles as $bundle => $enabled) {
        if (empty($enabled)) {
          continue;
        }

        // Get the river service for the bundle.
        $service = RiverServiceBase::getRiverService($bundle);
        if (empty($service)) {
          continue;
        }

        // Get any bookmarked ids for the bundle.
        // Limit to 5 items for the preview.
        $ids = $this->getEntityIds($entity_type, $bundle, $uid, 0, 5);
        if (empty($ids)) {
          continue;
        }

        // Get the ReliefWeb API payload for the bundle.
        $payload = $service->getApiPayload();

        // Filter on the ids.
        $filter = [
          'field' => 'id',
          'value' => $ids,
        ];
        if (isset($payload['filter'])) {
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

        // Sort by id to have the most recent first.
        $payload['sort'] = 'id:desc';

        // Add the API request to the list.
        $queries[$bundle] = [
          'resource' => $service->getResource(),
          'title' => $service->getPageTitle(),
          'payload' => $payload,
          'entity_type' => $entity_type,
        ];
      }
    }

    $sections = [];
    if (!empty($queries)) {
      // Get the API data.
      $results = $this->reliefWebApiClient
        ->requestMultiple(array_filter($queries), TRUE);

      // Prepare the sections.
      foreach ($results as $bundle => $result) {
        $query = $queries[$bundle];

        $entities = RiverServiceBase::getRiverData($bundle, $result);
        if (empty($entities)) {
          continue;
        }

        $sections[$bundle] = [
          '#theme' => 'reliefweb_rivers_river',
          '#id' => $bundle,
          '#title' => $query['title'],
          '#resource' => $query['resource'],
          '#entities' => $entities,
          '#more' => [
            'url' => '/user/' . $uid . '/bookmarks/' . $query['entity_type'] . '/' . $bundle,
            'label' => $this->t('View your @count bookmarked @resource', [
              '@count' => $this->getEntityCount($query['entity_type'], $bundle, $uid),
              '@resource' => strtr($query['resource'], '_', ' '),
            ]),
          ],
          '#cache' => [
            'contexts' => [
              'user',
            ],
            'tags' => [
              'reliefweb_bookmarks:user:' . $uid,
              'reliefweb_bookmarks:' . $query['entity_type'],
              $query['entity_type'] . '_list:' . $bundle,
            ],
          ],
        ];
      }
    }

    return [
      '#theme' => 'reliefweb_bookmarks',
      '#tabs' => $this->getNavigationTabs($user),
      '#sections' => $sections,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function bookmarksByType(UserInterface $user, $entity_type, $bundle) {
    $config = $this->configFactory->get('reliefweb_bookmarks.settings');
    $uid = $user->id();

    // Number of items per page.
    $limit = 10;

    $entity_types = [
      'node' => $config->get('node') ?? [],
      'taxonomy_term' => $config->get('taxonomy_term') ?? [],
    ];

    if (empty($entity_types[$entity_type][$bundle])) {
      throw new NotFoundHttpException();
    }

    // Get the river service for the bundle.
    $service = RiverServiceBase::getRiverService($bundle);
    if (empty($service)) {
      throw new NotFoundHttpException();
    }

    // Get the total number of bookmarked items for this bundle and user.
    $count = $this->getEntityCount($entity_type, $bundle, $uid);
    if ($count < $limit) {
      $currentPage = 0;
    }
    else {
      $currentPage = $this->pagerManager->createPager($count, $limit)->getCurrentPage();
    }

    // Get the section content.
    $entities = [];
    if ($count > 0) {
      // Get the ids of the bookmarked items for the current page.
      $ids = $this->getEntityIds($entity_type, $bundle, $uid, $currentPage * $limit, $limit);
      if (!empty($ids)) {
        // Get the ReliefWeb API payload for the bundle.
        $payload = $service->getApiPayload();

        // Filter on the ids.
        $filter = [
          'field' => 'id',
          'value' => $ids,
        ];
        if (isset($payload['filter'])) {
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

        // Sort by id to have the most recent first.
        $payload['sort'] = 'id:desc';

        // Ensure we retrieve the expected amount of items.
        $payload['limit'] = $limit;

        // Get the API data.
        $result = $this->reliefWebApiClient
          ->request($service->getResource(), $payload);

        // Prepare the data from the API.
        $entities = RiverServiceBase::getRiverData($bundle, $result);
      }
    }

    $section = [
      '#theme' => 'reliefweb_rivers_river',
      '#id' => $bundle,
      '#title' => $service->getPageTitle(),
      '#resource' => $service->getResource(),
      '#entities' => $entities,
      '#results' => [
        '#theme' => 'reliefweb_rivers_results',
        '#total' => $count,
        '#start' => ($currentPage * $limit) + 1,
        '#end' => ($currentPage * $limit) + count($entities),
      ],
      '#pager' => [
        '#type' => 'pager',
      ],
      '#empty' => $this->t('No bookmarked @resource.', [
        '@resource' => $service->getResource(),
      ]),
      '#cache' => [
        'contexts' => [
          'user',
        ],
        'tags' => [
          'reliefweb_bookmarks:user:' . $uid,
          'reliefweb_bookmarks:' . $entity_type,
          $entity_type . '_list:' . $bundle,
        ],
      ],
    ];

    return [
      '#theme' => 'reliefweb_bookmarks',
      '#tabs' => $this->getNavigationTabs($user, $bundle),
      '#sections' => [$bundle => $section],
      '#link' => [
        'url' => '/user/' . $uid . '/bookmarks',
        'label' => $this->t('All bookmarks'),
      ],
    ];
  }

  /**
   * Get the navigation tabs for the bookmarks.
   *
   * @param \Drupal\user\UserInterface $user
   *   User.
   * @param string|null $selected_bundle
   *   Currently selected bookmark type.
   *
   * @return array
   *   Render array for the bookmark navigation tabs.
   */
  protected function getNavigationTabs(UserInterface $user, $selected_bundle = NULL) {
    $config = $this->configFactory->get('reliefweb_bookmarks.settings');
    $tabs = [];

    $tabs['overview'] = [
      'url' => Url::fromRoute('reliefweb_bookmarks.user', [
        'user' => $user->id(),
      ])->toString(),
      'title' => $this->t('Overview'),
      'selected' => empty($selected_bundle),
    ];

    $entity_types = [
      'node' => $config->get('node') ?? [],
      'taxonomy_term' => $config->get('taxonomy_term') ?? [],
    ];

    foreach ($entity_types as $entity_type => $bundles) {
      foreach ($bundles as $bundle => $enabled) {
        if (empty($enabled)) {
          continue;
        }

        // Get the river service for the bundle.
        $service = RiverServiceBase::getRiverService($bundle);
        if (empty($service)) {
          continue;
        }

        $tabs[$service->getRiver()] = [
          'url' => Url::fromRoute('reliefweb_bookmarks.user.type', [
            'user' => $user->id(),
            'entity_type' => $entity_type,
            'bundle' => $bundle,
          ])->toString(),
          'title' => $service->getPageTitle(),
          'selected' => $bundle === $selected_bundle,
        ];
      }
    }

    return [
      '#theme' => 'reliefweb_rivers_views',
      '#title' => $this->t('Bookmark types'),
      '#views' => $tabs,
    ];
  }

  /**
   * Get the bookmarked entity ids for the given entity type, bundle and uid.
   *
   * @param string $entity_type
   *   Entity type.
   * @param string $bundle
   *   Entity bundle.
   * @param int $uid
   *   User id.
   * @param int $offset
   *   Number of items to skip.
   * @param int $limit
   *   Number of items to return.
   *
   * @return array
   *   Entity Ids.
   */
  protected function getEntityIds($entity_type, $bundle, $uid, $offset = 0, $limit = 10) {
    $query = $this->database->select('reliefweb_bookmarks', 'rb');
    $query->fields('rb', ['entity_id']);
    $query->condition('rb.entity_type', $entity_type);
    $query->condition('rb.uid', $uid);
    $query->orderBy('rb.entity_id', 'DESC');
    $query->range($offset, $limit);

    if ($entity_type === 'node') {
      $query->innerJoin('node_field_data', 'n', 'n.nid = rb.entity_id');
      $query->condition('n.type', $bundle, '=');
      $query->condition('n.status', 1, '=');
    }
    elseif ($entity_type === 'taxonomy_term') {
      $query->innerJoin('taxonomy_term_field_data', 't', 't.tid = rb.entity_id');
      $query->condition('t.vid', $bundle, '=');
      $query->condition('t.status', 1, '=');
    }
    else {
      return [];
    }

    $records = $query->execute();

    $ids = [];
    if (!empty($records)) {
      foreach ($records as $record) {
        $ids[] = (int) $record->entity_id;
      }
    }

    return $ids;
  }

  /**
   * Get the count of bookmarked entities for entity type, bundle and uid.
   *
   * @param string $entity_type
   *   Entity type.
   * @param string $bundle
   *   Entity bundle.
   * @param int $uid
   *   User id.
   *
   * @return int
   *   Count.
   */
  protected function getEntityCount($entity_type, $bundle, $uid) {
    $query = $this->database->select('reliefweb_bookmarks', 'rb');
    $query->fields('rb', ['entity_id']);
    $query->condition('rb.entity_type', $entity_type);
    $query->condition('rb.uid', $uid);

    if ($entity_type === 'node') {
      $query->innerJoin('node_field_data', 'n', 'n.nid = rb.entity_id');
      $query->condition('n.type', $bundle, '=');
      $query->condition('n.status', 1, '=');
    }
    elseif ($entity_type === 'taxonomy_term') {
      $query->innerJoin('taxonomy_term_field_data', 't', 't.tid = rb.entity_id');
      $query->condition('t.vid', $bundle, '=');
      $query->condition('t.status', 1, '=');
    }
    else {
      return 0;
    }

    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Check the access to the page.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account to check access for.
   * @param \Drupal\user\UserInterface $user
   *   User account for the user posts page.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkUserAccess(AccountInterface $account, UserInterface $user) {
    if ($account->id() == $user->id()) {
      return AccessResult::allowedIf($account->hasPermission('bookmark content'));
    }
    return AccessResult::allowedIf($account->hasPermission('see other bookmarks'));
  }

  /**
   * Redirect the current user to the its bookmarks page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirection response.
   */
  public function currentUserBookmarksPage() {
    return $this->redirect('reliefweb_bookmarks.user', [
      'user' => $this->currentUser()->id(),
    ], [], 301);
  }

}
