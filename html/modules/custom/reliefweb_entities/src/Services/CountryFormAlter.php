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
  public function alterForm(array &$form, FormStateInterface $form_state) {
    parent::alterForm($form, $form_state);

    // Restrict the description to the markdown format.
    $form['description']['widget'][0]['#allowed_formats'] = [
      'markdown' => 'markdown',
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
