<?php

namespace Drupal\guidelines\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class GuidelineTypeForm.
 */
class GuidelineTypeForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $guideline_type = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $guideline_type->label(),
      '#description' => $this->t("Label for the Guideline type."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $guideline_type->id(),
      '#machine_name' => [
        'exists' => '\Drupal\guidelines\Entity\GuidelineType::load',
      ],
      '#disabled' => !$guideline_type->isNew(),
    ];

    /* You will need additional form elements for your custom properties. */

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $guideline_type = $this->entity;
    $status = $guideline_type->save();

    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addMessage($this->t('Created the %label Guideline type.', [
          '%label' => $guideline_type->label(),
        ]));
        break;

      default:
        $this->messenger()->addMessage($this->t('Saved the %label Guideline type.', [
          '%label' => $guideline_type->label(),
        ]));
    }
    $form_state->setRedirectUrl($guideline_type->toUrl('collection'));
  }

}
