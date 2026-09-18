<?php

namespace Drupal\reliefweb_user_posts\Services;

use Drupal\Core\Entity\EntityInterface;

/**
 * User posts service for training nodes.
 */
class UserPostsTrainingService extends UserPostsDeadlineService {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'training';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeadlineCell(EntityInterface $entity) {
    if ($entity->field_registration_deadline->isEmpty()) {
      return $this->t('Ongoing');
    }
    return $this->formatDate($entity->field_registration_deadline->value);
  }

}
