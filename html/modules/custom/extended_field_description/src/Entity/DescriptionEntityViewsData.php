<?php

namespace Drupal\extended_field_description\Entity;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for Description entity entities.
 */
class DescriptionEntityViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    // Additional information for Views integration, such as table joins, can be
    // put here.
    return $data;
  }

}
