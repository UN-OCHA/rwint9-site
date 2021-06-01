<?php

namespace Drupal\reliefweb_homepage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the homepage route.
 */
class Homepage extends ControllerBase {

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
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $reliefweb_api_client
   *   The reliefweb api client service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ReliefWebApiClient $reliefweb_api_client, StateInterface $state) {
    $this->entityTypeManager = $entity_type_manager;
    $this->reliefWebApiClient = $reliefweb_api_client;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('reliefweb_api.client'),
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

      // Generate the section.
      switch ($index) {
        case 'headlines':
        case 'disasters':
        case 'most_read':
        case 'blog':
          if (empty($result['data'])) {
            continue 2;
          }
          $entities = static::parseReliefWebApiData($query['bundle'], $result, $query['view'] ?? '');
          if (empty($entities)) {
            continue 2;
          }
          $sections[$index] = [
            '#theme' => 'reliefweb_rivers_river',
            '#id' => $index,
            '#title' => $query['title'],
            '#resource' => $query['resource'],
            '#total' => $result['totalCount'],
            '#entities' => $entities,
            '#more' => $query['more'] ?? NULL,
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

    return [
      '#theme' => 'reliefweb_homepage',
      '#title' => $this->t('ReliefWeb'),
      '#sections' => $sections,
    ];
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
    $payload = [
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'country.id',
          'country.iso3',
          'country.name',
          'country.shortname',
          'country.primary',
          'source.id',
          'source.name',
          'source.shortname',
          'language.id',
          'language.name',
          'language.code',
          'format.name',
          'headline',
        ],
      ],
      'sort' => ['score:desc', 'date.created:desc'],
      'limit' => $limit,
    ];

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
      'payload' => $payload,
      'title' => $this->t('Latest Headlines'),
      'callback' => [$this, 'parseHeadlinesApiData'],
      'view' => 'headlines',
      // Link to the headlines river for the entity.
      'more' => [
        'url' => UrlHelper::encodeUrl('/updates?view=headlines'),
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
    $payload = [
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'date.created',
          'date.original',
          'country.id',
          'country.iso3',
          'country.name',
          'country.shortname',
          'country.primary',
          'source.id',
          'source.name',
          'source.shortname',
          'language.id',
          'language.name',
          'language.code',
          'format.name',
        ],
      ],
      'limit' => $limit,
      'sort' => ['date.created:desc'],
    ];

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'payload' => $payload,
      'title' => $this->t('Latest Updates'),
      'callback' => [$this, 'parseMostReadApiData'],
      // Link to the updates river for the entity.
      'more' => [
        'url' => UrlHelper::encodeUrl('/updates'),
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
    $payload = [
      'fields' => [
        'include' => [
          'id',
          'name',
          'status',
          'url_alias',
          'primary_type.code',
        ],
      ],
      'filter' => [
        'field' => 'status',
        'value' => ['alert', 'current'],
      ],
      'sort' => ['id:desc'],
      'limit' => $limit,
    ];

    return [
      'resource' => 'disasters',
      'bundle' => 'disaster',
      'payload' => $payload,
      'title' => $this->t('Recent Disasters'),
      'callback' => [$this, 'parseDisastersApiData'],
      // Link to the disasters river for the entity.
      'more' => [
        'url' => UrlHelper::encodeUrl('/disasters'),
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
    $payload = [
      'query' => [
        'fields' => [
          'title',
          'body',
          'author',
          'tags',
        ],
      ],
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'title',
          'body-html',
          'date',
          'tags',
          'author',
        ],
      ],
      'sort' => ['date.created:desc'],
      'limit' => 1,
    ];

    return [
      'resource' => 'blog',
      'bundle' => 'blog_post',
      'payload' => $payload,
      'title' => $this->t('Latest Blog'),
      'callback' => [$this, 'parseBlogPostApiData'],
      // Link to the blog river for the entity.
      'more' => [
        'url' => UrlHelper::encodeUrl('/blog'),
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
      $river = 'jobs';
    }
    else {
      $title = $this->t('Training programs');
      $resource = 'training';
      $river = 'training';
    }

    return [
      'resource' => $resource,
      'bundle' => $bundle,
      'payload' => $payload,
      'title' => $title,
      // Link to the job/training river for the entity.
      'url' => UrlHelper::encodeUrl('/' . $river),
    ];
  }

  /**
   * Parse the data returned by the ReliefWeb API.
   *
   * @param string $bundle
   *   The entity bundle for the data.
   * @param array $data
   *   The ReliefWeb API data.
   * @param string $view
   *   Current river view.
   *
   * @return array
   *   List of articles to display.
   *
   * @see \Drupal\reliefweb_rivers\Services\RiverInterface.php
   */
  public static function parseReliefWebApiData($bundle, array $data, $view = '') {
    $handler = \Drupal::service('reliefweb_rivers.' . $bundle . '.river');
    return $handler->parseApiData($data, $view);
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
      $image = $node->field_image->field_image_file->entity;

      return [
        '#theme' => 'reliefweb_homepage_announcement',
        '#id' => 'announcement',
        '#url' => $node->field_link->url,
        '#image' => [
          'url' => $image->uri->value,
          'title' => $node->label(),
          'alt' => $node->body->value ?? $node->label(),
          'width' => $image->width,
          'height' => $image->height,
        ],
        '#cache' => [
          'contexts' => ['node_list:announcement'],
        ],
      ];
    }
    return [];
  }

}
