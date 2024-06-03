<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Moderation service for the blog_post nodes.
 */
class BlogPostModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'blog_post';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->t('Blog posts');
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    return [
      'edit' => [
        'label' => '',
      ],
      'data' => [
        'label' => $this->t('Blog post'),
      ],
      'date' => [
        'label' => $this->t('Posted'),
        'type' => 'property',
        'specifier' => 'created',
        'sortable' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(array $results) {
    if (empty($results['entities'])) {
      return [];
    }

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      // Edit link + status cell.
      $cells['edit'] = $this->getEntityEditAndStatusData($entity);

      // Entity data cell.
      $data = [];

      // Title.
      $data['title'] = $entity->toLink()->toString();

      // Author information.
      $info = [];
      $info['author'] = $this->getEntityAuthorData($entity);
      $data['info'] = array_filter($info);

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // Date cell.
      $cells['date'] = [
        'date' => $this->getEntityCreationDate($entity),
      ];

      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function disableNotifications(EntityModeratedInterface $entity, $status) {
    $previous_status = isset($entity->original) ? $entity->original->getModerationStatus() : '';
    // Disable if not published or previously published to avoid resending
    // notifications when making modification to a published report.
    $entity->notifications_content_disable = $status !== 'published' || $previous_status === 'published';
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
      'author',
      'created',
      'user_role',
      'title',
      'body',
    ]);
    return $definitions;
  }

}
