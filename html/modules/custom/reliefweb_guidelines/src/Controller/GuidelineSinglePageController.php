<?php

namespace Drupal\reliefweb_guidelines\Controller;

use Drupal\Component\Utility\Html;
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
 * Controller for the guidelines.
 */
class GuidelineSinglePageController extends ControllerBase {

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
    $storage = $this->entityTypeManager->getStorage('guideline');

    $ids = $storage
      ->getQuery()
      ->sort('id', 'ASC')
      ->execute();

    /** @var \Drupal\guidelines\Entity\Guideline[] $guidelines */
    $guidelines = $storage->loadMultiple($ids);

    $items = [];

    foreach ($guidelines as $guideline) {
      if ($parents = $guideline->getParentIds()) {
        $items[$parents[0]]['#children'][] = [
          '#theme' => 'reliefweb_guidelines_item',
          '#id' => $guideline->hasField('field_short_link') ? Html::getUniqueId($guideline->field_short_link->value) : Html::getUniqueId($guideline->field_title->value),
          '#title' => $guideline->field_title->value,
          '#description' => $guideline->hasField('field_description') ? check_markup($guideline->field_description->value, $guideline->field_description->format) : '',
        ];
      }
      else {
        $items[$guideline->id()] = [
          '#theme' => 'reliefweb_guidelines_item',
          '#id' => $guideline->hasField('field_short_link') ? Html::getUniqueId($guideline->field_short_link->value) : Html::getUniqueId($guideline->field_title->value),
          '#title' => $guideline->field_title->value,
          '#description' => $guideline->hasField('field_description') ? check_markup($guideline->field_description->value, $guideline->field_description->format) : '',
          '#children' => [],
        ];
      }
    }

    $build = [
      '#theme' => 'reliefweb_guidelines_list',
      '#title' => $this->t('ReliefWeb guidelines'),
      '#guidelines' => $items,
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];

    $build['#attached']['library'][] = 'reliefweb_guidelines/reliefweb-guidelines';

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
    $payload = RiverServiceBase::getRiverApiPayload('report');
    $payload['fields']['exclude'][] = 'file';
    $payload['fields']['exclude'][] = 'body-html';
    $payload['limit'] = $limit;

    return [
      'resource' => 'reports',
      'bundle' => 'report',
      'payload' => $payload,
      'title' => $this->t('Latest Updates'),
      'callback' => [$this, 'parseMostReadApiData'],
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
      $success = TRUE;
    }
    return new Response('', $success ? 200 : 500);
  }

}
