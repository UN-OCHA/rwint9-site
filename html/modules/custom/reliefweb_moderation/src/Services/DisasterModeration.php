<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Moderation service for the disaster terms.
 */
class DisasterModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'disaster';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return 'taxonomy_term';
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->t('Disasters');
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
        'label' => $this->t('Disaster'),
        'type' => 'property',
        'specifier' => 'name',
        'sortable' => TRUE,
      ],
      'date' => [
        'label' => $this->t('Disaster date'),
        'type' => 'field',
        'specifier' => [
          'field' => 'field_disaster_date',
          'column' => 'value',
        ],
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

      // Glide and country info.
      $info = [];
      // Glide number.
      $glide = $entity->field_glide->value;
      if (!empty($glide)) {
        $info['glide'] = $glide;
      }
      // Country.
      $countries = [];
      foreach ($this->sortTaxonomyTermFieldItems($entity->field_country) as $item) {
        $country_link = $this->getTaxonomyTermLink($item);
        if (!empty($country_link)) {
          $countries[] = $country_link;
        }
      }
      if (!empty($countries)) {
        $info['country'] = $countries;
      }
      $data['info'] = array_filter($info);

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // Date cell.
      $cells['date'] = [
        'date' => $entity->field_disaster_date->value ?: $this->getEntityCreationDate($entity),
      ];

      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'draft' => $this->t('Draft'),
      'alert' => $this->t('Alert'),
      'ongoing' => $this->t('Ongoing'),
      'past' => $this->t('Past Disaster'),
      'draft-archive' => $this->t('Draft Archive'),
      'alert-archive' => $this->t('Alert Archive'),
      'external' => $this->t('External'),
      'external-archive' => $this->t('External Archive'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterStatuses() {
    $statuses = parent::getFilterStatuses();
    // @todo replace with permission.
    if (!UserHelper::userHasRoles(['external_disaster_manager'])) {
      unset($statuses['external']);
      unset($statuses['external-archive']);
    }
    return $statuses;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    $buttons = [];
    $new = empty($status) || $entity->isNew();

    // @todo replace with permission.
    if (UserHelper::userHasRoles(['editor'])) {
      $buttons['draft'] = [
        '#value' => $this->t('Draft'),
      ];
      $buttons['alert'] = [
        '#value' => $this->t('Alert'),
      ];
      $buttons['ongoing'] = [
        '#value' => $this->t('Ongoing'),
      ];
    }

    // Allow to save new disasters as past disasters directly.
    if ($new) {
      $buttons['past'] = [
        '#value' => $this->t('Past disaster'),
      ];
    }

    // @todo replace with permission.
    if (UserHelper::userHasRoles(['external_disaster_manager'])) {
      $buttons['external'] = [
        '#value' => $this->t('External'),
      ];
    }

    // Use a catch all button to archive existing disasters.
    // @see ::alterSubmittedEntityStatus()
    if (!$new) {
      $buttons['archive'] = [
        '#value' => $this->t('Archive'),
      ];
    }

    return $buttons;
  }

  /**
   * {@inheritdoc}
   */
  public function alterSubmittedEntityStatus($status, FormStateInterface $form_state) {
    // Compute the real status when saving as "archive".
    if ($status === 'archive') {
      $current_status = $form_state
        ?->getFormObject()
        ?->getEntity()
        ?->getModerationStatus();

      switch ($current_status) {
        case 'draft':
        case 'draft-archive':
          $status = 'draft-archive';
          break;

        case 'alert':
        case 'alert-archive':
          $status = 'alert-archive';
          break;

        case 'external':
        case 'external-archive':
          $status = 'external-archive';
          break;

        case 'current':
        case 'ongoing':
        case 'past':
          $status = 'past';
          break;

        // Compatibility with previous archive status.
        default:
          $status = 'alert-archive';
      }
    }
    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function isViewableStatus($status, ?AccountInterface $account = NULL) {
    $account = $account ?: $this->currentUser;
    // External disasters are only viewable by "External disaster managers".
    if ($status === 'external' || $status === 'external-archive') {
      return UserHelper::userHasRoles(['external_disaster_manager'], $account);
    }
    // Editors can view any disaster.
    elseif (UserHelper::userHasRoles(['editor'], $account)) {
      return TRUE;
    }
    return parent::isViewableStatus($status, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function isPublishedStatus($status) {
    return in_array($status, ['alert', 'current', 'ongoing', 'past']);
  }

  /**
   * {@inheritdoc}
   */
  public function isEditableStatus($status, ?AccountInterface $account = NULL) {
    $account = $account ?: $this->currentUser;
    if (!$account->hasPermission('edit terms in disaster')) {
      return FALSE;
    }
    // External disasters are only editable by "External disaster managers".
    if ($status === 'external' || $status === 'external-archive') {
      return UserHelper::userHasRoles(['external_disaster_manager'], $account);
    }
    // Only Editors are allowed to edit disasters.
    return UserHelper::userHasRoles(['editor'], $account);
  }

  /**
   * {@inheritdoc}
   */
  public function hasStatus($status) {
    return $status === 'archive' || parent::hasStatus($status);
  }

  /**
   * {@inheritdoc}
   */
  public function disableNotifications(EntityModeratedInterface $entity, $status) {
    if (empty($entity->notifications_content_disable)) {
      $allowed_statuses = ['alert', 'current', 'ongoing'];
      $entity->notifications_content_disable = !in_array($status, $allowed_statuses);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
      'country',
      'disaster_type',
      'glide',
      'disaster_date',
      'name',
      'profile',
      'author',
      'created',
    ]);

    $definitions['author']['join_callback'] = 'joinTaxonomyAuthor';
    $definitions['created']['label'] = $this->t('Creation date');
    return $definitions;
  }

}
