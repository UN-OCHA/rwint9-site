<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Moderation service for the training nodes.
 */
class TrainingModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'training';
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
    return $this->t('Training');
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
        'label' => $this->t('Training'),
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
      // @todo use a template instead?
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
        $info['country'] = $this->t('Online');
      }
      $data['info'] = array_filter($info);

      // Details.
      $details = [];
      // Registration deadline.
      $registration_deadline = $entity->field_registration_deadline->value;
      if (!empty($registration_deadline)) {
        $details['registration-deadline'] = $this->t('Registration deadline: %date', [
          '%date' => $this->formatDate($registration_deadline),
        ]);
      }
      // Training dates (start and end).
      $training_date_start = $entity->field_training_date->value;
      $training_date_end = $entity->field_training_date->end_value;
      if (!empty($training_date_start)) {
        if ($training_date_start === $training_date_end) {
          $training_date = $this->formatDate($training_date_start);
        }
        else {
          $training_date = $this->t('@start to @end', [
            '@start' => $this->formatDate($training_date_start),
            '@end' => $this->formatDate($training_date_end),
          ]);
        }
      }
      else {
        $training_date = $this->t('ongoing');
      }
      $details['training-date'] = $this->t('Training date: %date', [
        '%date' => $training_date,
      ]);
      // Cost.
      $cost = $entity->field_cost->value;
      if (!empty($cost)) {
        $details['cost'] = $this->t('Cost: %cost', ['%cost' => $cost]);
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
      'training_type',
      'training_format',
      'language',
      'organization_type',
      'created',
      'registration_deadline',
      'author',
      'user_role',
      'posting_rights',
      'reviewer',
      'reviewed',
      'title',
      'body',
      'ongoing',
      'cost',
    ]);
    $definitions['career_categories']['label'] = $this->t('Professional function');
    $definitions['training_type']['label'] = $this->t('Category');
    $definitions['training_format']['label'] = $this->t('Format');
    return $definitions;
  }

}
