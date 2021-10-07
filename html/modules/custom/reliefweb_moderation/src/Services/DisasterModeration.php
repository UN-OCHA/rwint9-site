<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityModeratedInterface;
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

    /** @var \Drupal\Core\Entity\EntityInterface[] $entities */
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
      foreach ($entity->field_country as $item) {
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
      'draft_archive' => $this->t('Draft Archive'),
      'alert_archive' => $this->t('Alert Archive'),
      'external' => $this->t('External'),
      'external_archive' => $this->t('External Archive'),
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
      unset($statuses['external_archive']);
    }
    return $statuses;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    $buttons = [];

    // @todo replace with permission.
    if (UserHelper::userHasRoles(['editor'])) {
      $buttons['draft'] = [
        '#value' => $this->t('Draft'),
      ];
      $buttons['alert'] = [
        '#value' => $this->t('Alert'),
      ];
      $buttons['current'] = [
        '#value' => $this->t('Ongoing'),
      ];
    }

    // @todo replace with permission.
    if (UserHelper::userHasRoles(['external_disaster_manager'])) {
      $buttons['external'] = [
        '#value' => $this->t('External'),
      ];
    }

    // Existing disaster.
    if (!empty($status)) {
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
  protected function initFilterDefinitions($filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
      'country',
      'disaster_type',
      'glide',
      'disaster_date',
      'name',
      'profile',
      'author',
    ]);

    $definitions['author']['join_callback'] = 'joinTaxonomyAuthor';
    return $definitions;
  }

}
