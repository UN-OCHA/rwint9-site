<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

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
   * {@inheritdoc}
   */
  public function getRiverContent() {
    $page = $this->pagerParameters->findPage();
    $offset = $page * $this->limit;
    $totalCount = 0;

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'topic')
      ->condition('status', 1)
      ->sort('created', 'DESC');

    $totalCount = $query->count()->execute();

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'topic')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range($offset, $this->limit)
      ->execute();

    $topics = \Drupal\node\Entity\Node::loadMultiple($nids);

    $reliefweb_topics = [];
    foreach ($topics as $nid => $topic) {
      $key = $topic->field_featured->value . '_' . $nid;
      $topic = array(
        'title' => $topic->title->value,
        'url' => $topic->toUrl()->toString(),
        'summary' => HtmlSummarizer::summarize($topic->body->value, 300),
        'featured' => !empty($topic->field_featured->value),
        'icon' => array(
          'url' => $topic->icon,
        ),
      );
      $reliefweb_topics[$key] = $topic;
    }
    // Sort by most recent (nid descending) but prioritizing featured topics.
    krsort($reliefweb_topics, SORT_NATURAL);

    // Get the community topics.
    $community_topics = [];
/*
    foreach (variable_get('reliefweb_topics_community_topics_links', []) as $data) {
      $topic = array(
        'title' => $data['title'],
        'url' => static::encodeUrl($data['url']),
        'summary' => $data['description'],
      );
      $community_topics[] = static::wrapEntity($bundle, $topic, $labels);
    }
*/

    // Initialize the pager.
    $this->pagerManager->createPager($totalCount ?? 0, $this->limit);

    return [
      '#theme' => 'reliefweb_rivers_river',
      '#id' => 'river-list',
      '#title' => $this->t('List'),
      '#results' => $this->getRiverResults(count($reliefweb_topics)),
      '#entities' => $reliefweb_topics,
      '#pager' => $this->getRiverPager(),
      '#empty' => $this->t('No results found. Please modify your search or filter selection.'),
    ];

    // Should ideally return both.
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
      'reliefweb' => [
        '#theme' => 'reliefweb_rivers_river',
        '#id' => 'river-list',
        '#title' => $this->t('List'),
        '#results' => $this->getRiverResults(count($community_topics)),
        '#entities' => $community_topics,
        '#empty' => $this->t('No results found. Please modify your search or filter selection.'),
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
