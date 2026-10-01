<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_rivers\RiverServiceBase;

/**
 * Bundle class for blog_post nodes.
 */
class BlogPost extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'blog';
  }

  /**
   * {@inheritdoc}
   */
  public static function addFieldConstraints(&$fields) {
    // No specific constraints.
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityMeta() {
    $tags = [];
    foreach ($this->field_tags->referencedEntities() as $entity) {
      $tags[] = [
        'name' => $entity->label(),
        'url' => RiverServiceBase::getRiverUrl($this->bundle(), [
          'search' => 'tags.exact:"' . $entity->label() . '"',
        ]),
      ];
    }

    return [
      'author' => $this->field_author->value ?? 'ReliefWeb',
      'posted' => $this->createDate($this->getCreatedTime()),
      'tags' => $tags,
    ];
  }

  /**
   * Get the latest blog posts.
   *
   * @param int $limit
   *   Number of latest blog posts to return.
   *
   * @return array
   *   Render array for the latest blog posts river.
   */
  public function getLatestBlogPosts($limit = 8) {
    $payload = RiverServiceBase::getRiverApiPayload('blog_post');
    $payload['fields']['exclude'][] = 'body-html';
    $payload['limit'] = $limit;

    // Exlcude the current blog post.
    $payload['filter'] = [
      'field' => 'id',
      'value' => $this->id(),
      'negate' => TRUE,
    ];

    // Retrieve the data from the API.
    $cacheability = new CacheableMetadata();
    $data = \Drupal::service('reliefweb_api.client')
      ->request($this->getApiResource(), $payload, cacheability: $cacheability, request_id: 'blog.latest');
    if (!is_array($data) || empty($data)) {
      $build = [];
      $cacheability->applyTo($build);
      return $build;
    }

    $entities = RiverServiceBase::getRiverData('blog_post', $data, '', [
      'author',
      'tags',
    ]);
    if (empty($entities)) {
      $build = [];
      $cacheability->applyTo($build);
      return $build;
    }

    $build = [
      '#theme' => 'reliefweb_rivers_river__blog_post',
      '#id' => 'latest-blog-posts',
      '#title' => $this->t('Latest blog posts'),
      '#resource' => 'blog',
      '#entities' => $entities,
      '#more' => [
        'url' => RiverServiceBase::getRiverUrl('blog_post'),
        'label' => $this->t('View all blog posts'),
      ],
      '#cache' => [
        'tags' => [
          'node_list:blog_post',
        ],
      ],
    ];
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    // Set the creation date to the changed date when publishing the blog
    // post from an unpublished state.
    $original_entity = $this->getOriginal();
    if (
      isset($original_entity) &&
      $this->getModerationStatus() === 'published' &&
      $original_entity->getModerationStatus() !== 'published'
    ) {
      $this->setCreatedTime($this->getChangedTime());
    }
  }

}
