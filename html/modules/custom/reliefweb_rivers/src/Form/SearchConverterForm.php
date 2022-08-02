<?php

namespace Drupal\reliefweb_rivers\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * From to convert a search query to an API query.
 */
class SearchConverterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_converter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['appname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application name'),
      '#default_value' => $form_state->getValue('appname'),
      '#not_required' => TRUE,
    ];

    $form['search-url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search URL'),
      '#default_value' => $form_state->getValue('search-url') ?? '',
      '#not_required' => TRUE,
      '#maxlength' => NULL,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#theme_wrappers' => [
        'fieldset' => [
          '#id' => 'actions',
          '#title' => $this->t('Form actions'),
          '#title_display' => 'invisible',
        ],
      ],
      '#weight' => 99,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => 'submit',
      '#value' => $this->t('Convert'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'submit',
      '#name' => 'reset',
      '#value' => $this->t('Reset'),
      '#submit' => ['::resetForm'],
      '#limit_validation_errors' => [
        ['actions', 'reset'],
      ],
    ];

    // Mark the form for enhancement by the reliefweb_form module.
    $form['#attributes']['data-enhanced'] = '';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Reset the form.
   *
   * @param array $form
   *   Form data.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function resetForm(array $form, FormStateInterface $form_state) {
    $form_state->setProgrammed(FALSE);
    $form_state->setRedirect('<current>');
  }

}
