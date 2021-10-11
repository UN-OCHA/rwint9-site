<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_form\Helpers\FormHelper;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Job form alteration service.
 */
class JobFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Add a guide on how to populate the field as description to the title
    // field.
    $form['title']['#description'] = $this->t('The best job titles are brief and specific. Please refrain from indicating location, salary and other details in the title, if possible.');

    // Force shorter titles.
    $form['title']['#maxlength'] = 150;

    // @todo review if that is still needed when introducing the WYSIWYG.
    // Add the WYSIWYG to the body and how to apply fields.
    $form['body']['#attributes']['data-with-wysiwyg'] = '';
    $form['field_how_to_apply']['#attributes']['data-with-wysiwyg'] = '';

    // Add a datepicker to the job closing date.
    $form['field_job_closing_date']['#attributes']['data-with-datepicker'] = '';

    // Add an autocomplete widget to the country and source fields.
    $form['field_country']['#attributes']['data-with-autocomplete'] = '';
    $form['field_source']['#attributes']['data-with-autocomplete'] = 'sources';
    $form['field_source']['#attributes']['data-selection-messages'] = '';
    $form['field_source']['#attributes']['data-autocomplete-path'] = Url::fromRoute('reliefweb_form.node_form.source_attention_messages', [
      'bundle' => 'job',
    ])->toString();

    // Add the fields to a potential new source.
    $this->addPotentialNewSourceFields($form, $form_state);

    // Alter and regroup the location fields, adding the possibility to select
    // an unspecified location.
    $this->alterJobLocationFields($form, $form_state);

    // Change to radios as we only accept 1 value. We need to do that here
    // because the field is shared with training nodes that accept several
    // value (Trello #Bsh2rhuv). This is done in the `addSelectionLimit` when
    // the limit is 1.
    $this->addSelectionLimit($form, 'field_career_categories', 1);

    // Disable themes for some career categories and limit selection.
    $this->alterJobThemeField($form, $form_state);

    // Alter the available options for the theme and country fields:
    // - Remove Contributions (Collab #2327).
    // - Remove Humanitarian Financing (Trello #OnXq5cCC).
    // - Remove Logistics and Telecommunications (Trello #G3YgNUF6).
    // - Remove World (254) (Trello #DI9bxljg).
    FormHelper::removeOptions($form, 'field_theme', $this->state->get('reliefweb_remove_themes_jobs', []));
    FormHelper::removeOptions($form, 'field_country', [254]);

    // Fix the years of experience ordering. Ordering by tid does the trick.
    FormHelper::orderOptionsByValue($form, 'field_job_experience');

    // Add the user information block at the top of the form for editors.
    $this->addUserInformation($form, $form_state);

    // Add the terms and conditions block.
    $this->addTermsAndConditions($form, $form_state);

    // Validate that the closing date is not in the past.
    $form['#validate'][] = [$this, 'validateJobDateField'];
  }

  /**
   * Regroup the country, city and format fields and add online course handling.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterJobLocationFields(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $entity_id = $entity->id();

    // Add a field no mark the location as unspecified.
    $unspecified_default_value = FALSE;
    // If the node is edited (i.e. not new, i.e. it has a node id), then check
    // if it has no country which means it has an unspecified location.
    if (!empty($entity_id)) {
      $unspecified_default_value = $entity->get('field_country')->isEmpty();
    }

    $form['unspecified_location'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Unspecified (Remote location, roster/roving, location to be determined)'),
      '#default_value' => $unspecified_default_value ? 1 : 0,
      // @todo review if that's necessary.
      '#wrapper_attributes' => ['class' => ['form-wrapper']],
    ];

    // Make the country field non optional.
    // @todo review.
    $form['field_country']['widget']['#optional'] = FALSE;

    // Hide the country and city field when "unspecified" is selected.
    $condition = [
      ':input[name="unspecified_location"]' => ['checked' => TRUE],
    ];
    $form['field_country']['#states']['invisible'] = $condition;
    $form['field_city']['#states']['invisible'] = $condition;

    // Add a validation callback to remove the country and city information if
    // unspecified location is checked.
    $form['#validate'][] = [$this, 'validateJobLocationFields'];
  }

  /**
   * Validate and update the country location.
   *
   * Remove country and city information if unspecified location is checked.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateJobLocationFields(array $form, FormStateInterface $form_state) {
    if (!$form_state->isValueEmpty('unspecified_location')) {
      // Empty the country and city fields.
      $form_state->setValue('field_country', []);
      $form_state->setValue('field_city', []);
    }
    elseif ($form_state->isValueEmpty('field_country')) {
      $form_state->setErrorByName('field_country', $this->t('Please select a relevant Job Location.'));
    }
  }

  /**
   * Alter theme field.
   *
   * Limit the selection and disable the theme when some career categories are
   * selected.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterJobThemeField(array &$form, FormStateInterface $form_state) {
    // Limit the number of selectable themes.
    $this->addSelectionLimit($form, 'field_theme', 3);

    // Disable the selection of themes for some career categories.
    $themeless = $this->getJobThemelessCategories();
    if (!empty($themeless)) {
      $conditions = [];
      foreach ($themeless as $id) {
        $conditions[':input[name="field_career_categories"]'][] = ['value' => $id];
      }

      $form['field_theme']['field_theme_irrelevant'] = [
        '#type' => 'item',
        'message' => [
          '#prefix' => '<p class="field-theme-irrelevant-message">',
          '#suggix' => '</p>',
          '#markup' => $this->t('Not required for career category selected'),
        ],
        '#states' => [
          'visible' => [$conditions],
        ],
      ];

      $form['field_theme']['widget']['#states']['disabled'] = [$conditions];

      $form['#validate'][] = [$this, 'validateJobThemeField'];
    }
  }

  /**
   * Validate and update the job themes.
   *
   * Remove themes if certain categories are chosen.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateJobThemeField(array $form, FormStateInterface $form_state) {
    $themeless = $this->getJobThemelessCategories();
    $categories = [];
    foreach ($form_state->getValue('field_career_categories') as $item) {
      if (!empty($item['target_id'])) {
        $categories[] = (int) $item['target_id'];
      }
    }
    if (count(array_intersect($themeless, $categories)) > 0) {
      $form_state->setValue('field_theme', []);
    }
  }

  /**
   * Validate the job closing date, ensuring it's not in the past.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateJobDateField(array $form, FormStateInterface $form_state) {
    $preview = $this->isPreviewRequested($form_state);
    $status = $this->getEntityModerationStatus($form_state);
    // Make sure the closing date is in the future for non editors.
    // We also do the validation for the preview to help spot issues.
    if ($preview || (!UserHelper::userHasRoles(['editor']) && ($status === 'pending' || $status === 'published'))) {
      $time = gmmktime(0, 0, 0);
      $date = DateHelper::getDateTimeStamp($form_state->getValue([
        'field_job_closing_date', 0, 'value',
      ]));
      if (empty($date) || ($date < $time)) {
        $form_state->setErrorByName('field_job_closing_date][0][value', $this->t('The closing date can not be in the past.'));
      }
    }
  }

  /**
   * Get the list of job categories for which themes are irrelevant.
   *
   * @return array
   *   List of theme term ids.
   */
  protected function getJobThemelessCategories() {
    // Disable the themes for some career categories (Trello #RfWgIdwA):
    // - Human Resources (6863).
    // - Administration/Finance (6864).
    // - Information and Communications Technology (6866).
    // - Donor Relations/Grants Management (20966).
    $default = [6863, 6864, 6866, 20966];
    return $this->state->get('reliefweb_jobs_themeless_categories', $default);
  }

}
