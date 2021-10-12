<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\TaxonomyHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

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

      // User, source and country info.
      $info = [];
      // User posting rights.
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
  public function isEditableStatus($status, ?AccountInterface $account = NULL) {
    return in_array($status, [
      'draft',
      'pending',
      'on-hold',
      'published',
      'expired',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function entityPresave(EntityModeratedInterface $entity) {
    // Set the status of the entity as expired if past the deadline.
    $status = $entity->getModerationStatus();

    if ($status === 'published') {
      $time = gmmktime(0, 0, 0);
      $date = DateHelper::getDateTimeStamp($entity->field_job_closing_date->value);

      // Set status to expired if the current date is past the deadline.
      if (!empty($date) && $date < $time) {
        $entity->setModerationStatus('expired');
      }
    }

    parent::entityPresave($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function entityAccess(EntityModeratedInterface $entity, $operation = 'view', ?AccountInterface $account = NULL) {
    $account = $account ?: $this->currentUser;

    $access_result = parent::entityAccess($entity, $operation, $account);
    $access = $access_result->isAllowed();

    // Allow deletion of draft, pending and on-hold only or of any documents
    // for editors.
    if ($operation === 'delete') {
      $statuses = ['draft', 'pending', 'on-hold'];
      $access = $account->hasPermission('bypass node access') ||
                $account->hasPermission('administer nodes') ||
                ($access && in_array($entity->getModerationStatus(), $statuses));
    }

    return $access ? AccessResult::allowed() : AccessResult::forbidden();
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
    if (UserHelper::userHasRoles(['editor'])) {
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
        $message = $this->t('Press OK to submit the changes for review by the ReliefWeb editors. The job may be set as pending.');
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
        '#value' => $this->t('Close Job'),
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
    if (!UserHelper::userHasRoles(['editor']) && $status === 'pending') {
      // Retrieve the list of sources and check the user rights.
      if (!$form_state->isValueEmpty('field_source')) {
        // Extract source ids.
        $sources = array_filter(array_map(function ($source) {
          return $source['target_id'];
        }, $form_state->getValue('field_source')));

        // Get the user's posting right for the document.
        $right = UserPostingRightsHelper::getUserConsolidatedPostingRight($user, 'job', $sources);

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
  protected function initFilterDefinitions(array $filters = []) {
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
