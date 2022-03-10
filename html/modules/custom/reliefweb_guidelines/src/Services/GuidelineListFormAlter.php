<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Guideline list form alteration service.
 */
class GuidelineListFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Hide the url alias as it's not used for guideline lists.
    $form['path']['#access'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAllowedForms() {
    return ['default', 'add', 'edit'];
  }

}
