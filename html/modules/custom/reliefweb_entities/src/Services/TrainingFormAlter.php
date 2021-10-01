<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_form\Helpers\FormHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Training form alteration service.
 */
class TrainingFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state) {
    // Add the guidelines.
    $form['#attributes']['data-with-guidelines'] = '';

    // Add a guide on how to populate the field as description to the title
    // field.
    $form['title']['#description'] = $this->t('Should contain only the title of the training. Other information such as location, date and Organization should not be included in this field.');

    // Force shorter titles.
    $form['title']['#maxlength'] = 150;

    // @todo review if that is still needed when introducing the WYSIWYG.
    // Add the WYSIWYG to the body and how to register fields.
    $form['body']['#attributes']['data-with-wysiwyg'] = '';
    $form['field_how_to_register']['#attributes']['data-with-wysiwyg'] = '';

    // Add a datepicker to the training date and registration deadline.
    $form['field_training_date']['#attributes']['data-with-datepicker'] = '';
    $form['field_registration_deadline']['#attributes']['data-with-datepicker'] = '';

    // Add an autocomplete widget to the country and source fields.
    $form['field_country']['#attributes']['data-with-autocomplete'] = '';
    $form['field_source']['#attributes']['data-with-autocomplete'] = 'sources';
    $form['field_source']['#attributes']['data-selection-messages'] = '';
    $form['field_source']['#attributes']['data-autocomplete-path'] = Url::fromRoute('reliefweb_form.node_form.source_attention_messages', [
      'bundle' => 'job',
    ])->toString();

    // Add the fields to a potential new source.
    $this->addPotentialNewSourceFields($form, $form_state);

    // Limit the number of selectable themes and categories.
    $this->addSelectionLimit($form, 'field_theme', 3);
    $this->addSelectionLimit($form, 'field_career_categories', 3);

    // Alter the available options for the theme and advertisement language:
    // - Remove Contributions (Collab #2327).
    // - Remove Logistics and Telecommunications (Trello #G3YgNUF6).
    // - Remove Russian (10906) and Arabic (6876) (Collab #4452001).
    // - Remove Other (31996) language option.
    FormHelper::removeOptions($form, 'field_theme', $this->state->get('reliefweb_remove_themes_training', []));
    FormHelper::removeOptions($form, 'field_language', [
      6876, 10906, 31996,
    ]);
    FormHelper::removeOptions($form, 'field_training_language', [31996]);

    // Re-order the course/event language. Ordering by tid does the trick.
    FormHelper::orderOptionsByValue($form, 'field_training_language');

    // Add the user information block at the top of the form for editors.
    $this->addUserInformation($form, $form_state);

    // Add the terms and conditions block.
    $this->addTermsAndConditions($form, $form_state);

    // Alter the location fields (country, city) with online handling.
    $this->alterTrainingLocationFields($form, $form_state);

    // Alter the date fields with ongoing handling.
    $this->alterTrainingDateFields($form, $form_state);

    // Alter the fee information field to be disabled when cost is 'free'.
    $this->alterTrainingFeeInformationField($form, $form_state);

    // Add a validation callback to handle the altered fields above.
    $form['#validate'][] = [$this, 'validateTrainingEventUrl'];

    // Let the base service add additional alterations.
    parent::alterForm($form, $form_state);
  }

  /**
   * Modify the country, city and format fields, adding online course handling.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterTrainingLocationFields(array &$form, FormStateInterface $form_state) {
    // Make the country field non required. We'll check that it has a value
    // when 'on-site' is selected in the validation callback.
    $form['field_country']['widget']['#required'] = FALSE;

    // Show the country and city fields only when 'on-site' (4606) is selected.
    $condition = [
      ':input[name="field_training_format[4606]"]' => ['checked' => FALSE],
    ];
    $form['field_country']['#states']['invisible'] = $condition;
    $form['field_country']['#states']['disabled'] = $condition;
    $form['field_country']['#states']['optional'] = $condition;
    $form['field_city']['#states']['invisible'] = $condition;
    $form['field_city']['#states']['disabled'] = $condition;

    // Add a validation callback to add 'World' as country if 'online' is
    // selected and remove other countries and empty the city field if 'on-site'
    // is not selected.
    $form['#validate'][] = [$this, 'validateTrainingLocationFields'];
  }

  /**
   * Validate the training location fields.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateTrainingLocationFields(array $form, FormStateInterface $form_state) {
    $onsite = FALSE;
    foreach ($form_state->getValue('field_training_format') as $value) {
      if (isset($value['target_id'])) {
        // No strict equality as it can be a numeric string.
        if ($value['target_id'] == 4606) {
          $onsite = TRUE;
        }
      }
    }

    $country = $form_state->getValue(['field_country', 0, 'target_id']);
    // Country is mandatory when 'on-site' is selected.
    if ($onsite === TRUE && empty($country)) {
      $form_state->setErrorByName('field_country', $this->t('Country field is required when "on-site" is selected.'));
    }
    // Silenty empty the country and city fields if 'on-site' is not selected.
    elseif ($onsite === FALSE) {
      $form_state->setValue('field_country', []);
      $form_state->setValue('field_city', []);
    }
  }

  /**
   * Modify the date fields adding ongoing courses handling.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterTrainingDateFields(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $entity_id = $entity->id();

    $ongoing = FALSE;
    // Retrieve the current state of the ongoing field.
    if ($form_state->hasValue('ongoing')) {
      $ongoing = $form_state->getValue('ongoing') === 'ongoing';
    }
    // Or for the initial build of the form for existing nodes, check if the
    // training date is empty which means ongoing was previously selected.
    elseif (!empty($entity_id)) {
      $ongoing = $entity->field_training_date->isEmpty();
    }

    // Add a field to select whether the training has fixed dates or is ongoing.
    $form['ongoing'] = [
      '#type' => 'radios',
      '#options' => [
        'fixed' => $this->t('Fixed dates'),
        'ongoing' => $this->t('Ongoing (recurrent/always available)'),
      ],
      '#default_value' => $ongoing ? 'ongoing' : 'fixed',
      '#attributes' => ['class' => ['form-wrapper']],
    ];

    // Do not mark the date fields as optional.
    $form['field_training_date']['widget']['#optional'] = FALSE;
    $form['field_registration_deadline']['widget']['#optional'] = FALSE;

    // Hide the date fields when ongoing is selected.
    $condition = [
      ':input[name="ongoing"]' => ['value' => 'ongoing'],
    ];
    $form['field_registration_deadline']['#states']['invisible'] = $condition;
    $form['field_training_date']['#states']['invisible'] = $condition;

    // Add a validation callback to empty the dates.
    $form['#validate'][] = [$this, 'validateTrainingDateFields'];
  }

  /**
   * Validate and update the training dates based on the ongoing field.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateTrainingDateFields(array $form, FormStateInterface $form_state) {
    $preview = $this->isPreviewRequested($form_state);
    $status = $this->getEntityModerationStatus($form_state);
    $ongoing = $form_state->getValue('ongoing');
    // Validate the dates if it's not an ongoing course.
    // We display 1 message at at a time.
    if ($ongoing !== 'ongoing') {
      // Extract the dates and convert them to timestamps
      // as the amazing Date module give us inconsistent data.
      $dates['start'] = $this->getDateTimeStamp($form_state->getValue([
        'field_training_date', 0, 'value',
      ]));
      $dates['end'] = $this->getDateTimeStamp($form_state->getValue([
        'field_training_date', 0, 'end_value',
      ]));
      $dates['deadline'] = $this->getDateTimeStamp($form_state->getValue([
        'field_registration_deadline', 0, 'value',
      ]));

      // Check deadline is set.
      if (empty($dates['deadline'])) {
        $form_state->setErrorByName('field_registration_deadline][0][value', $this->t('The registration date is required.'));
      }
      // Check start date.
      elseif (empty($dates['start'])) {
        $form_state->setErrorByName('field_training_date][0][value', $this->t('The training start date is required.'));
      }
      // Check end date.
      elseif (empty($dates['end'])) {
        $form_state->setErrorByName('field_training_date][0][end_value', $this->t('The training end date is required.'));
      }
      // Check end date > start date.
      elseif ($dates['end'] < $dates['start']) {
        $form_state->setErrorByName('field_training_date][0][end_value', $this->t('The training End date must be after the Start date.'));
      }
      // Check start date > deadline date.
      elseif ($dates['deadline'] > $dates['end']) {
        $form_state->setErrorByName('field_registration_deadline][0][value', $this->t('The Registration deadline can not be after the End date.'));
      }
      // Make sure the dates are in the future for non editors.
      // We also do the validation for the preview to help spot issues.
      elseif ($preview || (!UserHelper::userHasRoles(['Editor']) && ($status === 'pending' || $status === 'published'))) {
        $time = gmmktime(0, 0, 0);

        if ($dates['deadline'] < $time) {
          $form_state->setErrorByName('field_registration_deadline][0][value', $this->t('The Registration deadline can not be in the past.'));
        }
        elseif ($dates['start'] < $time) {
          $form_state->setErrorByName('field_training_date][0][value', $this->t('The training Start date can not be in the past.'));
        }
        elseif ($dates['end'] < $time) {
          $form_state->setErrorByName('field_training_date][0][end_value', $this->t('The training End date can not be in the past.'));
        }
      }
    }
    // Otherwise empty the date fields.
    else {
      $form_state->setValue(['field_training_date', 0, 'value'], []);
      $form_state->setValue(['field_training_date', 0, 'end_value'], []);
      $form_state->setValue(['field_registration_deadline', 0, 'value'], []);
    }
  }

  /**
   * Modify the date fields, regrouping them and handling ongoing courses.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterTrainingFeeInformationField(array &$form, FormStateInterface $form_state) {
    // Make the fee information mandatory by default.
    $form['field_fee_information']['widget'][0]['value']['#optional'] = FALSE;

    // Hide the fee information if 'free' is selected.
    $condition = [
      ':input[name="field_cost"]' => ['value' => 'fee-based'],
    ];
    $form['field_fee_information']['#states']['visible'] = $condition;
    $form['field_fee_information']['#states']['required'] = $condition;

    $form['#validate'][] = [$this, 'validateTrainingFeeInformationField'];
  }

  /**
   * Validate fee information for training form.
   *
   * Ensure there is fee information when 'fee-based' is selected otherwise
   * empty the field.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateTrainingFeeInformationField(array $form, FormStateInterface $form_state) {
    $cost = $form_state->getValue('field_cost');
    // Validate the fee information if fee-based is selected.
    if ($cost === 'fee-based') {
      $fee_information = $form_state->getValue(['fee_information', 0, 'value']);
      if (empty($fee_information) || trim($fee_information) === '') {
        $form_state->setErrorByName('field_fee_information', $this->t('The fee information is required.'));
      }
    }
    // Otherwise empty the fee information.
    else {
      $form_state->setValue('fee_information', []);
    }
  }

  /**
   * Validate event urls, as the link module doesn't do this properly.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @see https://www.drupal.org/node/2247261
   */
  public function validateTrainingEventUrl(array $form, FormStateInterface $form_state) {
    $url = $form_state->getValue(['field_link', 0, 'uri']);
    $url = trim($url);
    // Field is mandatory.
    if (empty($url)) {
      $form_state->setErrorByName('field_link][0][uri', $this->t('The Event URL is mandatory.'));
    }
    elseif (mb_strpos($url, 'http://') !== 0 && mb_strpos($url, 'https://') !== 0) {
      $form_state->setErrorByName('field_link][0][uri', $this->t('The Event URL must start with http:// or https://.'));
    }
    elseif (UrlHelper::isValid($url, TRUE) == FALSE) {
      $form_state->setErrorByName('field_link][0][uri', $this->t('The Event URL "@url" is not a valid web address.', [
        '@url' => $url,
      ]));
    }
  }

}
