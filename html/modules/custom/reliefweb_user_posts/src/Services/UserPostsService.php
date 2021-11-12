<?php

namespace Drupal\reliefweb_user_posts\Services;

use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\Component\Render\FormattableMarkup;

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
    return $this->t('My posts');
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
        'type' => 'property',
        'specifier' => 'nid',
        'sortable' => TRUE,
      ],
      'type' => [
        'label' => $this->t('Type'),
        'type' => 'property',
        'specifier' => 'type',
        'sortable' => TRUE,
      ],
      'status' => [
        'label' => $this->t('Status'),
        'type' => '',
        'specifier' => 'moderation_state',
        'sortable' => TRUE,
      ],
      'poster' => [
        'label' => $this->t('Poster'),
      ],
      'source' => [
        'label' => $this->t('Source'),
      ],
      'title' => [
        'label' => $this->t('Title'),
        'type' => 'property',
        'specifier' => 'title',
        'sortable' => TRUE,
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
      $cells['status'] = new FormattableMarkup('<div class="rw-moderation-status" data-moderation-status="@status">@label</div>', [
        '@status' => $entity->getModerationStatus(),
        '@label' => $entity->getModerationStatusLabel(),
      ]);
      $cells['poster'] = $this->currentUser->id() == $entity->getOwner()->id() ? $this->t('me') : $this->t('other');
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
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'title',
      'status',
      'created',
      'source',
      'author',
    ]);

    $definitions['bundle'] = [
      'type' => 'property',
      'field' => 'type',
      'label' => $this->t('Type'),
      'shortcut' => 'ty',
      'form' => 'other',
      'values' => [
        'job' => $this->t('Job'),
        'training' => $this->t('Training'),
      ],
    ];

    $definitions['author2'] = [
      'type' => 'property',
      'field' => 'uid',
      'label' => $this->t('Posted by'),
      'shortcut' => 'a',
      'form' => 'other',
      'join_callback' => 'joinPoster',
      'operator' => 'OR',
      'values' => [
        'me' => $this->t('Me'),
        'other' => $this->t('Other'),
      ],
    ];

    return $definitions;
  }

}
