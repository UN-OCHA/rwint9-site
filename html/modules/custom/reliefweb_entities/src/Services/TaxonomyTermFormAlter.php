<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Default taxonomy term form alteration service.
 */
class TaxonomyTermFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state) {
    parent::alterForm($form, $form_state);

    // Restrict the description to the plain_text format.
    $form['description']['widget'][0]['#format'] = 'plain_text';
    $form['description']['widget'][0]['#allowed_formats'] = [
      'plain_text' => 'plain_text',
    ];

    // Hide term relations as they are not used.
    $form['relations']['#access'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // No customizations.
  }

}
