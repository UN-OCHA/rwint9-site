<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_rivers\Parameters;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Symfony\Component\HttpFoundation\RequestStack;

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
  protected $entityTypeId = 'node';

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
    return $this->getDefaultPageTitle();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultPageTitle() {
    return $this->t('Topics');
  }

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    $river = Html::getId($this->getRiver());

    return [
      '#theme' => 'reliefweb_rivers_page__' . $river,
      '#river' => $river,
      '#title' => $this->getPageTitle(),
      '#content' => $this->getRiverContent(),
    ];
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
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
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct(
      $config_factory,
      $current_user,
      $language_manager,
      $pager_manager,
      $pager_parameters,
      $api_client,
      $request_stack,
      $renderer,
      $string_translation
    );
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function createNewInstanceFromUrl($url = NULL) {
    $service = new static(
      $this->configFactory,
      $this->currentUser,
      $this->languageManager,
      $this->pagerManager,
      $this->pagerParameters,
      $this->apiClient,
      $this->requestStack,
      $this->renderer,
      $this->stringTranslation,
      $this->entityTypeManager
    );
    $service->setParameters(Parameters::createFromUrl($url));
    return $service;
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverContent() {
    $storage = $this->entityTypeManager
      ->getStorage('node');

    $query = $storage->getQuery()
      ->condition('type', 'topic')
      ->condition('status', 1);

    // Exclude buried topics.
    $group = $query->orConditionGroup()
      ->notExists('field_bury')
      ->condition('field_bury', 1, '<>');
    $query->condition($group);

    $nids = $query->execute();

    $topics = $storage->loadMultiple($nids);

    $reliefweb_topics = [];
    foreach ($topics as $nid => $topic) {
      // Prefix the array key with the featured value this will results in
      // featured topics to appear first and the node id part will ensure
      // the topics are sorted by creation date.
      $featured = $topic->field_featured->value ?? 0;
      $key = $featured . '_' . $nid;

      // Convert the body to HTML.
      $body = check_markup($topic->body->value, $topic->body->format);

      $reliefweb_topics[$key] = [
        'title' => $topic->title->value,
        'url' => $topic->toUrl()->toString(),
        'bundle' => 'topic',
        'summary' => HtmlSummarizer::summarize($body, 300),
        'featured' => !empty($featured),
        'icon' => $topic->getIcon(),
      ];
    }
    // Sort by most recent (nid descending) but prioritizing featured topics.
    krsort($reliefweb_topics, SORT_NATURAL);

    // Get the community topics.
    $community_topics = [];
    foreach (reliefweb_topics_get_all_community_topics() as $data) {
      $data = is_array($data) ? (object) $data : $data;
      if (!empty($data->title) && !empty($data->url)) {
        $community_topics[] = [
          'title' => $data->title,
          'url' => $data->url,
          'summary' => $data->description ?? '',
        ];
      }
    }

    return [
      'reliefweb_topics' => [
        '#theme' => 'reliefweb_rivers_river',
        '#id' => 'reliefweb-topics',
        '#title' => $this->t('ReliefWeb Topics'),
        '#entities' => $reliefweb_topics,
        '#cache' => [
          'tags' => [
            $this->getEntityTypeId() . '_list:' . $this->getBundle(),
          ],
        ],
      ],
      'community_topics' => [
        '#theme' => 'reliefweb_rivers_river',
        '#id' => 'community-topics',
        '#title' => $this->t('Community Topics'),
        '#entities' => $community_topics,
        '#cache' => [
          'tags' => [
            'reliefweb_community_topics',
          ],
        ],
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
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRiverDescription() {
    return $this->t('Curated pages dedicated to humanitarian themes and specific humanitarian crises.');
  }

}
