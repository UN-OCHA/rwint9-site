<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Country form alteration service.
 */
class CountryFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Hide term relations as they are not used.
    $form['relations']['#access'] = FALSE;
  }

}
