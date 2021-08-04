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
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->setRevisionLogMessage(strtr('!type profile update', [
      '!type' => ucfirst($this->entity->bundle()),
    ]));
    parent::save($form, $form_state);
  }

}
