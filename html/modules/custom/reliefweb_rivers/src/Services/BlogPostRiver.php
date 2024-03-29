<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve blog post resource for the blog rivers.
 */
class BlogPostRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'blog';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'blog';

  /**
   * {@inheritdoc}
   */
  protected $entityTypeId = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'blog_post';

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
    return $this->t('Blog');
  }

  /**
   * {@inheritdoc}
   */
  public function getViews() {
    return [];
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
  public function getApiPayload($view = '') {
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
    ];

    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiData(array $api_data, $view = '') {
    // Retrieve the API data (with backward compatibility).
    $items = $api_data['items'] ?? $api_data['data'] ?? [];

    // Parse the entities retrieved from the API.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];

      // Title.
      $title = $fields['title'];

      // Summary.
      $summary = '';
      if (!empty($fields['body-html'])) {
        $body = HtmlSanitizer::sanitize($fields['body-html']);
        $summary = HtmlSummarizer::summarize($body, 400, FALSE);
      }

      // Tags.
      $tags = [];
      foreach ($fields['tags'] ?? [] as $tag) {
        $tags[] = [
          'name' => $tag['name'],
          'url' => static::getRiverUrl($this->bundle, [
            'search' => 'tags.exact:"' . $tag['name'] . '"',
          ]),
        ];
      }

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
        'title' => $title,
        'summary' => $summary,
        'author' => $fields['author'] ?? 'ReliefWeb',
        'tags' => ['tag' => $tags],
      ];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::getAliasFromPath('/node/' . $item['id']);
      }

      // Image.
      if (!empty($fields['image']['url-small'])) {
        $data['image'] = [
          'url' => UrlHelper::stripDangerousProtocols($fields['image']['url-small']),
          'caption' => $fields['image']['caption'] ?? '',
        ];
      }

      // Dates.
      if (isset($fields['date']['created'])) {
        $data['posted'] = static::createDate($fields['date']['created']);
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiPayloadForRss($view = '') {
    $payload = $this->getApiPayload($view);
    $payload['fields']['include'][] = 'image';
    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiDataForRss(array $data, $view = '') {
    // Retrieve the API data (with backward compatibility).
    $items = $data['items'] ?? $data['data'] ?? [];

    // Parse the entities retrieved from the API.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
      ];

      // Title.
      $data['title'] = $fields['title'];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::getAliasFromPath('/node/' . $item['id']);
      }

      // Dates.
      $data['date'] = static::createDate($fields['date']['created']);

      // Author.
      $data['author'] = $fields['author'] ?? 'ReliefWeb';

      // Body.
      $data['body'] = $fields['body-html'] ?? '';

      // Categories.
      $categories = [
        'tags' => [$this->t('Tag'), $this->t('Tags')],
      ];
      foreach ($categories as $category => $labels) {
        if (!empty($fields[$category])) {
          $data['categories'][$category] = [
            'label' => $labels[count($fields[$category]) > 1 ? 1 : 0],
            'values' => array_map(function ($term) {
              return $term['name'];
            }, $fields[$category]),
          ];
        }
      }

      // Media: image.
      if (isset($fields['image']['url'])) {
        $image = $fields['image'];
        $copyright = trim($image['copyright'] ?? '');
        if (!empty($copyright) && mb_strpos($copyright, '©') === FALSE) {
          $copyright = '© ' . $copyright;
        }
        $data['media'][] = [
          'url' => $image['url'],
          'filesize' => $image['filesize'] ?? 0,
          'type' => $image['mimetype'] ?? '',
          'medium' => 'image',
          'expression' => 'full',
          'height' => $image['height'] ?? 0,
          'width' => $image['width'] ?? 0,
          'thumbnail' => $image['url-thumb'] ?? '',
          'title' => $image['caption'] ?? '',
          'copyright' => $copyright,
        ];
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRiverDescription() {
    return $this->t("A look at the ideas and projects we're working on as we strive to grow and improve ReliefWeb.");
  }

  /**
   * {@inheritdoc}
   */
  public function getRiverCacheTags() {
    return [
      $this->getEntityTypeId() . '_list:' . $this->getBundle(),
      'taxonomy_term_list:tag',
    ];
  }

}
