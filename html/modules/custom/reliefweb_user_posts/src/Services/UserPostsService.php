<?php

namespace Drupal\reliefweb_user_posts\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Moderation service for the report nodes.
 */
class UserPostsService extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return [
      'job',
      'training',
    ];
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
    return $this->t('Topics');
  }

  /**
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'draft' => $this->t('draft'),
      'pending' => $this->t('pending'),
      'published' => $this->t('published'),
      'on_hold' => $this->t('on-hold'),
      'refused' => $this->t('refused'),
      'expired' => $this->t('expired'),
      'duplicate' => $this->t('duplicate'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    return [
      'id' => [
        'label' => $this->t('Id'),
      ],
      'type' => [
        'label' => $this->t('Type'),
      ],
      'status' => [
        'label' => $this->t('Status'),
      ],
      'poster' => [
        'label' => $this->t('Poster'),
      ],
      'source' => [
        'label' => $this->t('Source'),
      ],
      'title' => [
        'label' => $this->t('Title'),
      ],
      'date' => [
        'label' => $this->t('Posted'),
        'type' => 'property',
        'specifier' => 'created',
        'sortable' => TRUE,
      ],
      'deadline' => [
        'label' => $this->t('Deadline'),
        'type' => 'property',
        'specifier' => 'deadline',
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
      $cells['id'] = $entity->id();
      $cells['type'] = $entity->bundle();
      $cells['status'] = $entity->getModerationStatusLabel();
      $cells['poster'] = $entity->getOwner()->label();
      $cells['source'] = '';
      $cells['title'] = $entity->toLink()->toString();
      $cells['date'] = $this->getEntityCreationDate($entity);
      $cells['deadline'] = $entity->field_registration_deadline ? $entity->field_registration_deadline->value : '';
      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function entityAccess(EntityModeratedInterface $entity, $operation = 'view', ?AccountInterface $account = NULL) {
    // Trello #y0B0wxLi: allow beta tested to view unpublished topics.
    if ($operation === 'view' && UserHelper::userHasRoles(['beta_tester'], $account)) {
      return AccessResult::allowed();
    }
    return parent::entityAccess($entity, $operation, $account);
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
      'type',
      'poster',
      'source',
    ]);
    return $definitions;
  }

}
