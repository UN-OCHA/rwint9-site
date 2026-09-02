<?php

namespace Drupal\reliefweb_user_posts\Services;

use Drupal\Core\Entity\EntityInterface;

/**
 * Base user posts service for bundles with a deadline column.
 */
abstract class UserPostsDeadlineService extends UserPostsServiceBase {

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
      'duplicate' => $this->t('Duplicate'),
      'expired' => $this->t('Expired'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefaultStatuses() {
    $statuses = $this->getFilterStatuses();
    unset($statuses['expired']);
    unset($statuses['duplicate']);
    return array_keys($statuses);
  }

  /**
   * {@inheritdoc}
   */
  protected function hasDeadlineColumn(): bool {
    return TRUE;
  }

  /**
   * Build the deadline cell value for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   Formatted deadline.
   */
  abstract protected function getDeadlineCell(EntityInterface $entity);

}
