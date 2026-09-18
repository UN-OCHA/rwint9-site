<?php

namespace Drupal\reliefweb_user_posts\Services;

/**
 * User posts service for report nodes.
 */
class UserPostsReportService extends UserPostsServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'report';
  }

  /**
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'draft' => $this->t('Draft'),
      'pending' => $this->t('Pending'),
      'on-hold' => $this->t('On-hold'),
      'to-review' => $this->t('To review'),
      'published' => $this->t('Published'),
      'refused' => $this->t('Refused'),
      'embargoed' => $this->t('Embargoed'),
      'reference' => $this->t('Reference'),
      'archive' => $this->t('Archived'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefaultStatuses() {
    $statuses = $this->getFilterStatuses();
    unset($statuses['archive']);
    unset($statuses['refused']);
    return array_keys($statuses);
  }

}
