<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Service class to retrieve job resource for the job rivers.
 */
class TopicRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'topics';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'topics';

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'topic';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Topics');
  }

  /**
   * {@inheritdoc}
   */
  public function getViews() {
    return [
      'all' => $this->t('All Topics'),
    ];
  }

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(PagerManagerInterface $pager_manager, PagerParametersInterface $pager_parameters, ReliefWebApiClient $api_client, TranslationInterface $string_translation, EntityTypeManagerInterface $entity_type_manager) {
    $this->pagerManager = $pager_manager;
    $this->pagerParameters = $pager_parameters;
    $this->apiClient = $api_client;
    $this->stringTranslation = $string_translation;
    $this->url = static::getRiverUrl($this->bundle);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverContent() {
    $page = $this->pagerParameters->findPage();
    $offset = $page * $this->limit;
    $totalCount = 0;

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'topic')
      ->condition('status', 1)
      ->sort('created', 'DESC');
    $totalCount = $query->count()->execute();

    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'topic')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range($offset, $this->limit)
      ->execute();
    $topics = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    $reliefweb_topics = [];
    foreach ($topics as $nid => $topic) {
      $key = $topic->field_featured->value . '_' . $nid;
      $topic = [
        'title' => $topic->title->value,
        'url' => $topic->toUrl()->toString(),
        'summary' => HtmlSummarizer::summarize($topic->body->value, 300),
        'featured' => !empty($topic->field_featured->value),
        'icon' => [
          'url' => $topic->icon,
        ],
      ];
      $reliefweb_topics[$key] = $topic;
    }
    // Sort by most recent (nid descending) but prioritizing featured topics.
    krsort($reliefweb_topics, SORT_NATURAL);

    // Initialize the pager.
    $this->pagerManager->createPager($totalCount ?? 0, $this->limit);

    // Get the community topics.
    $community_topics = [];
    foreach (reliefweb_topics_get_all() as $data) {
      $community_topics[] = array(
        'title' => $data->title,
        'url' => \Drupal\Core\Url::fromUri($data->url),
        'summary' => $data->description,
      );
    }

    return [
      'reliefweb' => [
        '#theme' => 'reliefweb_rivers_river',
        '#id' => 'river-list',
        '#title' => $this->t('List'),
        '#results' => $this->getRiverResults(count($reliefweb_topics)),
        '#entities' => $reliefweb_topics,
        '#pager' => $this->getRiverPager(),
        '#empty' => $this->t('No results found. Please modify your search or filter selection.'),
      ],
      'community_topics' => [
        '#theme' => 'links',
        '#attributes' => [
          'class' => [
            'links--community-topics',
          ],
        ],
        '#heading' => [
          'text' => $this->t('Community topics'),
        ],
        '#links' => $community_topics,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterSample() {
    return $this->t('(Topics ...)');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiPayload($view = '') {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiData(array $api_data, $view = '') {
    // Not used.
  }

}
