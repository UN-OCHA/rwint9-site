<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Guideline node form alteration service.
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

    // Transform the guideline list selector into a select with autocomplete.
    $form['field_guideline_list']['widget']['#attributes']['data-with-autocomplete'] = '';

    // Link to create a new list in a separate tab; reload the form after.
    $new_list_url = Url::fromRoute('entity.taxonomy_term.add_form', [
      'taxonomy_vocabulary' => 'guideline_list',
    ], [
      'attributes' => [
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ]);
    $form['field_guideline_list']['widget']['#description'] = $this->t('Select the list this guideline belongs to. @new_list_link (and reload the form after).', [
      '@new_list_link' => Link::fromTextAndUrl($this->t('Create a new list'), $new_list_url)->toString(),
    ]);

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
