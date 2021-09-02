<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Moderation service for the job nodes.
 */
class JobModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'job';
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
    return $this->t('Jobs');
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
        'label' => $this->t('Job'),
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

      // User, source and country info.
      $info = [];
      // User posting rights.
      $info['posting_rights'] = new FormattableMarkup('<span data-user-posting-rights="@right">@right</span>', [
        '@right' => $this->getEntityAuthorPostingRights($entity),
      ]);
      // Author.
      $info['author'] = $this->getEntityAuthorData($entity);
      // Source.
      $sources = [];
      foreach ($entity->field_source as $item) {
        $source_link = $this->getTaxonomyTermLink($item);
        if (!empty($source_link)) {
          $sources[] = $source_link;
        }
      }
      if (!empty($sources)) {
        $info['source'] = $sources;
      }
      // Country.
      $country_link = $this->getTaxonomyTermLink($entity->field_country->first());
      if (!empty($country_link)) {
        $info['country'] = $country_link;
      }
      else {
        $info['country'] = $this->t('Unspecified location');
      }
      $data['info'] = array_filter($info);

      // Details.
      $details = [];
      // Closing date.
      $closing_date = $entity->field_job_closing_date->value;
      if (!empty($closing_date)) {
        $details['closing-date'] = $this->t('Closing date: %date', [
          '%date' => $this->formatDate($closing_date),
        ]);
      }
      $data['details'] = array_filter($details);

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
  public function getStatuses() {
    return [
      'draft' => $this->t('Draft'),
      'pending' => $this->t('Pending'),
      'published' => $this->t('Published'),
      'on_hold' => $this->t('On-hold'),
      'refused' => $this->t('Refused'),
      'duplicate' => $this->t('Duplicate'),
      'expired' => $this->t('Expired'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions($filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
      'country',
      'source',
      'theme',
      'career_categories',
      'job_type',
      'job_experience',
      'organization_type',
      'created',
      'job_closing_date',
      'author',
      'user_role',
      'posting_rights',
      'reviewer',
      'reviewed',
      'title',
      'body',
      'how_to_apply',
    ]);
    return $definitions;
  }

}
