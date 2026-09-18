<?php

namespace Drupal\reliefweb_user_posts\Services;

use Drupal\Core\Entity\EntityInterface;

/**
 * User posts service for job nodes.
 */
class UserPostsJobService extends UserPostsDeadlineService {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'job';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeadlineCell(EntityInterface $entity) {
    return $this->formatDate($entity->field_job_closing_date->value);
  }

}
