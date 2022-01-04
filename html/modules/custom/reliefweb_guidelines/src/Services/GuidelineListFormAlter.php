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
    // Hide the status field.
    $form['status']['#access'] = FALSE;

    // Force the new revision.
    $form['new_revision']['#default_value'] = TRUE;
    $form['new_revision']['#access'] = FALSE;

    // Disallow selecting parents for guideline lists.
    $form['relations']['#access'] = FALSE;

    // Hide the url alias.
    $form['path']['#access'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAllowedForms() {
    return ['default', 'add', 'edit'];
  }

}
