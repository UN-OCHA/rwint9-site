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
  protected $entityType = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'blog_post';

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
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
    return [];
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
          'url' => UrlHelper::encodeUrl('/' . $this->river . '?search=tags.exact:"' . $tag['name'] . '"'),
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
        $data['url'] = UrlHelper::encodeUrl('node/' . $item['id'], FALSE);
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

}
