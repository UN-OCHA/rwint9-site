<?php

namespace Drupal\reliefweb_fields\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\TermForm;

/**
 * Taxonomy term domain posting rights form handler.
 */
class TaxonomyTermDomainPostingRights extends TermForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['relations']['#access'] = FALSE;
    $form['revision_log_message']['#access'] = FALSE;
    $form['#title'] = $this->t('<em>Edit Domain Posting Rights for</em> @title', [
      '@bundle' => $this->getBundleLabel(),
      '@title' => $this->entity->label(),
    ]);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state) {
    $element = parent::actions($form, $form_state);
    if (isset($element['submit'])) {
      $element['submit']['#value'] = $this->t('Save changes');
    }
    if (isset($element['delete'])) {
      $element['delete']['#access'] = FALSE;
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->setRevisionUserId($this->currentUser()->id());
    $this->entity->setRevisionCreationTime(time());
    $this->entity->setRevisionLogMessage('Domain posting rights update');
    return parent::save($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addStatus($this->t('Domain posting rights successfully updated.'));
    parent::submitForm($form, $form_state);
  }

  /**
   * Get the bundle label.
   *
   * @return string
   *   Bundle label.
   */
  protected function getBundleLabel() {
    $bundle_key = $this->entity->getEntityType()->getKey('bundle');
    return $this->entity->get($bundle_key)->entity->label();
  }

}
