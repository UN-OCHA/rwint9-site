<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Guideline form alteration service.
 */
class GuidelineFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Change the image field wrapper to a fieldset.
    $form['field_images']['widget']['#type'] = 'fieldset';
    $form['field_images']['widget']['#theme_wrappers'] = ['fieldset'];

    // Transform the field selector into a select with autocomplete.
    $form['field_field']['widget']['#attributes']['data-with-autocomplete'] = '';
    $form['field_field']['widget']['#description'] = $this->t('Select the node or term field(s) this guideline is for.');

    // Transform the parent selector into a select with autocomplete.
    $form['relations']['parent']['#attributes']['data-with-autocomplete'] = '';

    // Hide the url alias because we have a special rule to generate alias based
    // on the guideline shortlink to allow easy internal linking.
    $form['path']['#access'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAllowedForms() {
    return ['default', 'add', 'edit'];
  }

}
