<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Guideline form alteration service.
 */
class GuidelineFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Hide the status field.
    $form['status']['#access'] = FALSE;

    // Force the new revision.
    $form['new_revision']['#default_value'] = TRUE;
    $form['new_revision']['#access'] = FALSE;

    // More space for the description.
    $form['field_description']['widget'][0]['#rows'] = 20;

    // Change the image field wrapper to a fieldset.
    $form['field_images']['widget']['#type'] = 'fieldset';
    $form['field_images']['widget']['#theme_wrappers'] = ['fieldset'];

    // Transform the field selector into a select with autocomplete.
    $form['field_field']['widget']['#attributes']['data-with-autocomplete'] = '';
    $form['field_field']['widget']['#description'] = $this->t('Select the node or term field(s) this guideline is for.');

    // Url to create a new guideline list.
    $new_list_url = Url::fromRoute('entity.guideline.add_form', [
      'guideline_type' => 'guideline_list',
    ], [
      'attributes' => [
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ]);

    // @todo limit the list to guideline lists and make it mandotory.
    $form['relations']['#type'] = 'fieldset';
    $form['relations']['#title_display'] = 'invisible';
    $form['relations']['parent']['#title'] = $this->t('Guideline list');
    $form['relations']['parent']['#description'] = $this->t('Select the list this guideline belongs to. @new_list_link.', [
      '@new_list_link' => Link::fromTextAndUrl($this->t('Create a new list'), $new_list_url)->toString(),
    ]);
    $form['relations']['parent']['#attributes']['data-with-autocomplete'] = '';
    $form['relations']['weight']['#description'] = $this->t('Guidelines are displayed in ascending order by weight.');

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
