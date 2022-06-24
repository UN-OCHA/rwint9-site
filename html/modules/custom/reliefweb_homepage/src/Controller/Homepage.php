<?php

namespace Drupal\reliefweb_homepage\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\MediaHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the homepage route.
 */
class Homepage extends ControllerBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The ReliefWeb API client.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $reliefWebApiClient;

  /**
   * The drupal renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $reliefweb_api_client
   *   The reliefweb api client service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    ReliefWebApiClient $reliefweb_api_client,
    RendererInterface $renderer,
    StateInterface $state
  ) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->reliefWebApiClient = $reliefweb_api_client;
    $this->renderer = $renderer;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('reliefweb_api.client'),
      $container->get('renderer'),
      $container->get('state')
    );
  }

  /**
   * Get the page content.
   *
   * @return array
   *   Render array for the homepage.
   */
  public function getPageContent() {
    $sections = [];
    $sections['announcement'] = $this->getLatestAnnouncement();

    // API queries.
    $queries = [
      'headlines' => $this->getHeadlinesApiPayload(),
      'disasters' => $this->getDisastersApiPayload(),
      'most_read' => $this->getMostReadApiPayload(),
      'blog' => $this->getBlogPostApiPayload(),
      'jobs' => $this->getOpportuntiesTotalApiPayload('job'),
      'training' => $this->getOpportuntiesTotalApiPayload('training'),
    ];

    // Get the API data.
    $results = $this->reliefWebApiClient
      ->requestMultiple(array_filter($queries), TRUE);

    // Parse the API results, building the page sections data.
    foreach ($results as $index => $result) {
      $query = $queries[$index];
      $cache_tags = [
        $query['entity_type'] . '_list:' . $query['bundle'],
        'taxonomy_term_list',
      ];

      // Generate the section.
      switch ($index) {
        case 'headlines':
          $cache_tags[] = 'reliefweb:headlines';
        case 'disasters':
        case 'most_read':
        case 'blog':
          if (empty($result['data'])) {
            continue 2;
          }

          $bundle = $query['bundle'];
          $view = $query['view'] ?? '';
          $exclude = $query['exclude'] ?? [];

          $entities = RiverServiceBase::getRiverData($bundle, $result, $view, $exclude);
          if (empty($entities)) {
            continue 2;
          }

          $sections[$index] = [
            '#theme' => 'reliefweb_rivers_river',
            '#id' => $index,
            '#title' => $query['title'],
            '#resource' => $query['resource'],
            '#entities' => $entities,
            '#more' => $query['more'] ?? NULL,
            '#cache' => [
              'tags' => $cache_tags,
            ],
          ];
          break;

        case 'jobs':
        case 'training':
          if (empty($result['totalCount'])) {
            continue 2;
          }
          if (!isset($sections['opportunities'])) {
            $sections['opportunities'] = [
              '#theme' => 'reliefweb_homepage_opportunities',
              '#id' => 'opportunities',
              '#cache' => [
                'tags' => [
                  'node_list:job',
                  'node_list:training',
                  'taxonomy_term_list',
                ],
              ],
            ];
          }
          $sections['opportunities']['#opportunities'][] = [
            'type' => $query['bundle'],
            'total' => $result['totalCount'],
            'title' => $query['title'],
            'url' => $query['url'],
          ];
          break;
      }
    }

    $build = [
      '#theme' => 'reliefweb_homepage',
      '#title' => $this->t('ReliefWeb'),
      '#sections' => $sections,
      '#cache' => [
        // Necessary because the headlines widget is added depending on the user
        // pemissions.
        'contexts' => ['user.permissions'],
      ],
    ];

    // Add the headlines widget.
    if ($this->currentUser->hasPermission('edit homepage headlines')) {
      $build['#attached']['library'][] = 'reliefweb_homepage/headlines-widget';
    }

    return $build;
  }

  /**
   * Get the API payload to get the latest headlines.
   *
   * @param int $limit
   *   Number of headlines to return.
   *
   * @return array
   *   API Payload.
   */
  public function getHeadlinesApiPayload($limit = 8) {
    $payload = RiverServiceBase::getRiverApiPayload('report', 'headlines');
    $payload['fields']['exclude'][] = 'file';
    $payload['fields']['include'][] = 'headline.image';
    $payload['sort'] = ['score:desc', 'date.created:desc'];
    $payload['limit'] = $limit;

    // Get the list of manually selected headlines.
    $selected = $this->state->get('reliefweb_headline_selection', []);
    $selected = array_slice($selected, 0, $limit);

    // Build the search query, boosting the manually selected headlines.
    // As the headlines are ordered by position (priority) we reverse the
    // list so that we can use the index as base for the boost (higher boost =
    // higher priority).
    $query = ['_exists_:headline.title'];
    foreach (array_reverse($selected) as $index => $id) {
      $query[] = 'id:' . $id . '^' . ($index + 1) * 10;
    }
    $payload['query']['value'] = implode(' OR ', $query);

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'entity_type' => 'node',
      'payload' => $payload,
      'exclude' => ['posted', 'published', 'format'],
      'title' => $this->t('Latest Headlines'),
      'callback' => [$this, 'parseHeadlinesApiData'],
      'view' => 'headlines',
      // Link to the headlines river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report', [
          'view' => 'headlines',
        ]),
        'label' => $this->t('View all headlines'),
      ],
    ];
  }

  /**
   * Get the API payload to get the most read reports.
   *
   * @param int $limit
   *   Number of documents to return.
   *
   * @return array
   *   API Payload.
   *
   * @todo This currently returns the latest updates, not the most read.
   * Refactor after add back redording of most read content.
   */
  public function getMostReadApiPayload($limit = 2) {
    // Load the most-read data. This file is generated via a drush command,
    // usually every day as the query to get the 5 most read reports is very
    // heavy.
    $handle = @fopen('public://most-read/most-read.csv', 'r');
    if ($handle === FALSE) {
      return [];
    }

    // Find the line corresponding to the entity id.
    while (($row = fgetcsv($handle, 100)) !== FALSE) {
      if (count($row) === 2 && $row[0] == 'front') {
        $ids = array_slice(explode(',', $row[1]), 0, $limit);
        break;
      }
    }

    // Close the file.
    if (is_resource($handle)) {
      @fclose($handle);
    }

    // Generate the query with the most read report ids.
    if (empty($ids)) {
      return [];
    }

    // We reverse the ids to add the boost (higher boost = higher view count).
    foreach (array_reverse($ids) as $index => $id) {
      $ids[$index] = $id . '^' . ($index * 10);
    }

    $payload = RiverServiceBase::getRiverApiPayload('report');
    $payload['fields']['exclude'][] = 'file';
    $payload['fields']['exclude'][] = 'body-html';
    $payload['query']['value'] = 'id:' . implode(' OR id:', $ids);
    $payload['limit'] = $limit;
    $payload['sort'] = ['score:desc', 'date.created:desc'];

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'entity_type' => 'node',
      'payload' => $payload,
      // Link to the updates river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('report'),
        'label' => $this->t('View all updates'),
      ],
    ];
  }

  /**
   * Get the API payload to get the latest disasters.
   *
   * @param int $limit
   *   Number of disasters to return.
   *
   * @return array
   *   API Payload.
   */
  public function getDisastersApiPayload($limit = 5) {
    $payload = RiverServiceBase::getRiverApiPayload('disaster', 'ongoing');
    $payload['fields']['exclude'][] = 'country';
    $payload['fields']['exclude'][] = 'type';
    $payload['fields']['exclude'][] = 'date';
    $payload['sort'] = ['date.created:desc'];
    $payload['limit'] = $limit;

    return [
      'resource' => 'disasters',
      'bundle' => 'disaster',
      'entity_type' => 'taxonomy_term',
      'payload' => $payload,
      'title' => $this->t('Recent Disasters'),
      'callback' => [$this, 'parseDisastersApiData'],
      // Link to the disasters river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('disaster'),
        'label' => $this->t('View all disasters'),
      ],
    ];
  }

  /**
   * Get the API payload to get the latest blog post.
   *
   * @return array
   *   API Payload.
   */
  public function getBlogPostApiPayload() {
    $payload = RiverServiceBase::getRiverApiPayload('blog_post');
    $payload['limit'] = 1;

    return [
      'resource' => 'blog',
      'bundle' => 'blog_post',
      'entity_type' => 'node',
      'payload' => $payload,
      'title' => $this->t('Latest Blog'),
      'callback' => [$this, 'parseBlogPostApiData'],
      // Link to the blog river for the entity.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl('blog_post'),
        'label' => $this->t('View all blog posts'),
      ],
    ];
  }

  /**
   * Get the API payload to get the number of job or training.
   *
   * @param string $bundle
   *   Entity bundle: job or training.
   *
   * @return array
   *   API Payload.
   */
  public function getOpportuntiesTotalApiPayload($bundle) {
    $payload = [
      // We just want the total count.
      'limit' => 0,
    ];

    if ($bundle === 'job') {
      $title = $this->t('Open jobs');
      $resource = 'jobs';
    }
    else {
      $title = $this->t('Training programs');
      $resource = 'training';
    }

    return [
      'resource' => $resource,
      'bundle' => $bundle,
      'entity_type' => 'node',
      'payload' => $payload,
      'title' => $title,
      // Link to the job/training river for the entity.
      'url' => RiverServiceBase::getRiverUrl($bundle),
    ];
  }

  /**
   * Get the latest announcement to feature on the homepage.
   *
   * @return array
   *   Render array for the announcement section.
   *
   * @todo Use a announcement node view mode for that?
   */
  public function getLatestAnnouncement() {
    $storage = $this->entityTypeManager
      ->getStorage('node');

    $nids = $storage
      ->getQuery()
      ->condition('type', 'announcement')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    $nodes = $storage->loadMultiple($nids);

    if (!empty($nodes)) {
      $node = reset($nodes);

      if ($node->hasField('field_image') && !$node->field_image->isEmpty()) {
        $image = MediaHelper::getImage($node->field_image);

        return [
          '#theme' => 'reliefweb_homepage_announcement',
          '#id' => 'announcement',
          '#url' => $node->field_link->uri,
          '#image' => [
            'url' => $image['uri'],
            'title' => $node->label(),
            'alt' => $node->body->value ?? $node->label(),
            'width' => $image['width'],
            'height' => $image['height'],
          ],
          '#cache' => [
            'tags' => ['node_list:announcement'],
          ],
        ];
      }
    }
    return [];
  }

  /**
   * Ajax callback to retrieve the list of selected headlines.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Rendered headline list.
   */
  public function retrieveHeadlines() {
    // Get the latest 24 headlines.
    $query = $this->getHeadlinesApiPayload(24);

    // Get the API data.
    $results = $this->reliefWebApiClient
      ->requestMultiple(['headlines' => $query]);

    $build = [];
    if (!empty($results['headlines']['data'])) {
      $result = $results['headlines'];

      // Sort the headlines by id DESC to have the most recent first.
      uasort($result['data'], function ($a, $b) {
        return $b['id'] <=> $a['id'];
      });

      $bundle = $query['bundle'];
      $view = $query['view'] ?? '';
      $exclude = $query['exclude'] ?? [];

      $entities = RiverServiceBase::getRiverData($bundle, $result, $view, $exclude);
      if (!empty($entities)) {
        $build = [
          '#theme' => 'reliefweb_rivers_river',
          '#id' => '#headlines',
          '#title' => $query['title'],
          '#resource' => $query['resource'],
          '#entities' => $entities,
          '#cache' => [
            'max-age' => 0,
          ],
        ];
      }
    }

    return new Response($this->renderer->render($build));
  }

  /**
   * Ajax callback to update the list of selected headlines.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   200 if the update was successful, 500 otherwise.
   */
  public function updateHeadlines() {
    $success = FALSE;
    // Limit to 10,000 bytes (should never be reached).
    $data = json_decode(file_get_contents('php://input', FALSE, NULL, 0, 10000) ?? '', TRUE);
    if (!empty($data) && is_array($data)) {
      $selection = array_slice(array_filter($data, 'is_int'), 0, 8);
      $this->state->set('reliefweb_headline_selection', $selection);
      Cache::invalidateTags(['reliefweb:headlines']);
      $success = TRUE;
    }
    return new Response('', $success ? 200 : 500);
  }

}
