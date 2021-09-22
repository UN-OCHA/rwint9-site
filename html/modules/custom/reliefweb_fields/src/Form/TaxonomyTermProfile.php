<?php

namespace Drupal\reliefweb_fields\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\TermForm;

/**
 * Taxonomy term profile form handler.
 */
class TaxonomyTermProfile extends TermForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['relations']['#access'] = FALSE;
    $form['revision_log_message']['#access'] = FALSE;
    $form['#title'] = $this->t('<em>Edit Profile of @bundle</em> @title', [
      '@bundle' => $this->getBundleLabel(),
      '@title' => $this->entity->label(),
    ]);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->setRevisionLogMessage(strtr('!bundle profile update', [
      '!bundle' => $this->getBundleLabel(),
    ]));
    parent::save($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addStatus($this->t('@bundle profile successfully updated.', [
      '@bundle' => $this->getBundleLabel(),
    ]));
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
