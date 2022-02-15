<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Announcement form alteration service.
 */
class AnnouncementFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    $form['title']['widget'][0]['value']['#description'] = $this->t('Title will show up in tooltip when you hover over the banner.');
  }

}
