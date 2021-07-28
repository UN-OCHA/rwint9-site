<?php

namespace Drupal\reliefweb_bookmarks\Controller;

/**
 * @file
 * Contains \Drupal\reliefweb_bookmarks\Controller\UserBookmarksController.
 */


use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Database\Connection;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_rivers\RiverServiceBase;

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
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account, ConfigFactoryInterface $config_factory, Connection $database, ReliefWebApiClient $reliefweb_api_client,) {
    $this->account = $account;
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->reliefWebApiClient = $reliefweb_api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('database'),
      $container->get('reliefweb_api.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function content(UserInterface $user) {
    $config = $this->configFactory->get('reliefweb_bookmarks.settings');
    $uid = $this->account->id();

    $entity_types = [
      'node' => $config->get('content_types') ?? [],
      'taxonomy_term' => $config->get('vocabularies') ?? [],
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
          'payload' => $payload,
        ];
      }
    }

    if (empty($queries)) {
      return [];
    }

    // Get the API data.
    $results = $this->reliefWebApiClient
      ->requestMultiple(array_filter($queries), TRUE);

    // Prepare the sections.
    $sections = [];
    foreach ($results as $bundle => $result) {
      $query = $queries[$bundle];

      $entities = RiverServiceBase::getRiverData($bundle, $result);
      if (empty($entities)) {
        continue;
      }

      $sections[$bundle] = [
        '#theme' => 'reliefweb_rivers_river',
        '#id' => $bundle,
        '#title' => ucfirst(strtr($query['resource'], '_', ' ')),
        '#resource' => $query['resource'],
        '#entities' => $entities,
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
    }

    return [
      '#theme' => 'reliefweb_bookmarks',
      '#sections' => $sections,
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
      $query->innerJoin('node', 'n', 'n.nid = rb.entity_id');
      $query->condition('n.type', $bundle, '=');
    }
    elseif ($entity_type === 'taxonomy_term') {
      $query->innerJoin('taxonomy_term_data', 'td', 'td.tid = rb.entity_id');
      $query->condition('td.vid', $bundle, '=');
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

}
