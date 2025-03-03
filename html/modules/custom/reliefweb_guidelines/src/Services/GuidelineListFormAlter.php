<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_form\Helpers\FormHelper;

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

    // Only keep allowed user roles as selectable roles.
    $roles_to_remove = array_diff_key(user_role_names(), reliefweb_guidelines_get_user_roles());
    FormHelper::removeOptions($form, 'field_role', array_keys($roles_to_remove));
  }

  /**
   * {@inheritdoc}
   */
  protected function getAllowedForms() {
    return ['default', 'add', 'edit'];
  }

}
