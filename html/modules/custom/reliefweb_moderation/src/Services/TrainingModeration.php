<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityModeratedInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_utility\Helpers\TaxonomyHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

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
        '@right' => UserPostingRightsHelper::getEntityAuthorPostingRights($entity),
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
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    $buttons = [];
    $new = empty($status) || $status === 'draft';

    // Only show save as draft for non-published but editable documents.
    if ($new || in_array($status, ['draft', 'pending', 'on-hold'])) {
      $buttons['draft'] = [
        '#value' => $this->t('Save as draft'),
      ];
    }

    // Editors can publish, put on hold or refuse a document.
    // @todo use permission.
    if (UserHelper::userHasRoles(['Editor'])) {
      $buttons['published'] = [
        '#value' => $this->t('Publish'),
      ];
      $buttons['on-hold'] = [
        '#value' => $this->t('On hold'),
      ];
      $buttons['duplicate'] = [
        '#value' => $this->t('Duplicate'),
      ];
      $buttons['refused'] = [
        '#value' => $this->t('Refuse'),
      ];
    }
    // Other users can submit for review (or publish directly if trusted).
    else {
      $buttons['pending'] = [
        '#value' => $new ? $this->t('Submit') : $this->t('Submit changes'),
      ];

      // Add confirmation when attempting to change published document.
      if ($status === 'published' || $status === 'expired') {
        $message = $this->t('Press OK to submit the changes for review by the ReliefWeb editors. The training may be set as pending.');
        $buttons['pending']['#attributes']['onclick'] = 'return confirm("' . $message . '")';
      }
    }

    // Warning message when saving as a draft.
    if (isset($buttons['draft'])) {
      $message = $this->t('You are saving this document as a draft. It will not be visible to visitors. If you wish to proceed with the publication kindly click on @buttons instead.', [
        '@buttons' => implode(' or ', array_map(function ($item) {
          return $item['#value'];
        }, array_slice($buttons, 1))),
      ]);
      $buttons['draft']['#attributes']['onclick'] = 'return confirm("' . $message . '")';
    }

    // Add a button to close (set as expired) a published job.
    if ($status === 'published' || $status === 'expired') {
      $buttons['expired'] = [
        '#value' => $this->t('Close Training'),
      ];
    }

    return $buttons;
  }

  /**
   * {@inheritdoc}
   */
  public function alterSubmittedEntityStatus($status, FormStateInterface $form_state) {
    // For non editors, we determine the real status based on the user
    // posting rights for the selected sources.
    if (!UserHelper::userHasRoles(['Editor']) && $status === 'pending') {
      // Retrieve the list of sources and check the user rights.
      if (!$form_state->isValueEmpty('field_source')) {
        // Extract source ids.
        $sources = array_filter(array_map(function ($source) {
          return $source['target_id'];
        }, $form_state->getValue('field_source')));

        // Get the user's posting right for the document.
        $right = UserPostingRightsHelper::getUserConsolidatedPostingRight($user, 'training', $sources);

        // Update the status based on the user's right.
        // Note: we don't use `t()` because those are log messages for editors.
        switch ($right['name']) {
          // Unverified for some sources => pending + flag.
          case 'unverified':
            $status = 'pending';
            $message = strtr('Unverified user for @sources.', [
              '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($right['sources'])),
            ]);
            break;

          // Blocked for some sources => refused + flag.
          case 'blocked':
            $status = 'refused';
            $message = strtr('Blocked user for @sources.', [
              '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($right['sources'])),
            ]);
            break;

          // Allowed for all sources => pending.
          case 'allowed':
            $status = 'pending';
            break;

          // Trusted for all the sources => published.
          case 'trusted':
            $status = 'published';
            break;
        }

        // Update the log message.
        if (!empty($message)) {
          $revision_log_field = $form_state
            ?->getFormObject()
            ?->getEntity()
            ?->getEntityType()
            ?->getRevisionMetadataKey('revision_log_message');

          if (!empty($revision_log_field)) {
            $log = $form_state->getValue([$revision_log_field, 0, 'value'], '');
            $log = $message . (!empty($log) ? ' ' . $log : '');
            $form_state->setValue([$revision_log_field, 0, 'value'], $log);
          }
        }
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
