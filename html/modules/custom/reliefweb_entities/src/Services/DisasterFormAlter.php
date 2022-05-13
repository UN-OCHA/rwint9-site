<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_form\Helpers\FormHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Disaster form alteration service.
 */
class DisasterFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state) {
    parent::alterForm($form, $form_state);

    // Restrict the description to the markdown format.
    $form['description']['widget'][0]['#allowed_formats'] = [
      'markdown' => 'markdown',
    ];

    // Hide term relations as they are not used.
    $form['relations']['#access'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Alter the primary country field, ensuring it's using a value among
    // the selected country values.
    $this->alterPrimaryField('field_primary_country', $form, $form_state);

    // Alter the primary disaster type field, ensuring it's using a value among
    // the selected disaster type values.
    $this->alterPrimaryField('field_primary_disaster_type', $form, $form_state);

    // Add a datepicker widget to the disaster date field.
    $form['field_disaster_date']['widget'][0]['value']['#attributes']['data-with-datepicker'] = '';

    // Use an autocomplete widget for the country and disaster type fields.
    $form['field_country']['#attributes']['data-with-autocomplete'] = '';
    $form['field_disaster_type']['#attributes']['data-with-autocomplete'] = '';
    $form['field_primary_country']['#attributes']['data-with-autocomplete'] = 'primary';
    $form['field_primary_disaster_type']['#attributes']['data-with-autocomplete'] = 'primary';

    // Add a checkbox to disable the notifications.
    $this->addDisableNotifications($form, $form_state);

    // Limit form for External disaster managers who are not Editors.
    if (UserHelper::userHasRoles(['external_disaster_manager'])) {
      if (!UserHelper::userHasRoles(['editor'])) {
        $this->restrictFormForExternalDisasterManagers($form, $form_state);
      }
    }
    // Remove Complex Emergency (41764) for non external disaster managers.
    else {
      FormHelper::removeOptions($form, 'field_disaster_type', [41764]);
      FormHelper::removeOptions($form, 'field_primary_disaster_type', [41764]);
    }

    // Validate the disaster GLIDE number and check for duplicates.
    $form['#validate'][] = [$this, 'validateGlidePattern'];
    $form['#validate'][] = [$this, 'validateGlideUniqueness'];
  }

  /**
   * Restrict access to some form fields for External disaster managers.
   */
  protected function restrictFormForExternalDisasterManagers(array &$form, FormStateInterface $form_state) {
    // Hide some fields.
    $form['url_alias']['#access'] = FALSE;
    $form['field_profile']['#access'] = FALSE;

    // Set the timezone for external disasters to 00:00 UTC.
    $form['field_timezone']['widget']['#type'] = 'hidden';
    $form['field_timezone']['widget']['#value'] = '0';

    // Disable the notifications.
    $form['notifications_content_disable']['#type'] = 'hidden';
    $form['notifications_content_disable']['#value'] = 1;
  }

  /**
   * Validate the GLIDE number pattern.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateGlidePattern(array $form, FormStateInterface $form_state) {
    $glide = $form_state->getValue(['field_glide', 0, 'value'], '');

    // Let the rest of the form validation throw an error message if
    // the glide field is empty and is mandatory.
    if (empty($glide)) {
      $triggering_element = $form_state->getTriggeringElement();
      if (!empty($triggering_element['#entity_status']) && $triggering_element['#entity_status'] !== 'draft') {
        $form_state->setErrorByName('field_glide][0][value', $this->t('The GLIDE number is mandatory.'));
      }
      return;
    }

    // Check if the glide number is a valid exception.
    $exceptions = $this->state->get('reliefweb_valid_glide_exceptions', []);
    if (in_array($glide, $exceptions)) {
      return;
    }

    // Disaster type codes from GLIDEnumber.net.
    $codes = [
      'CW', 'CE', 'DR', 'EQ', 'EP', 'EC', 'ET', 'FA', 'FR',
      'FF', 'FL', 'HT', 'IN', 'LS', 'MS', 'OT', 'ST', 'SL',
      'AV', 'SS', 'AC', 'TO', 'TC', 'TS', 'VW', 'VO', 'WV',
      'WF',
    ];

    // Get county ISO3 codes.
    $table = $this->getFieldTableName('taxonomy_term', 'field_iso3');
    $field = $this->getFieldColumnName('taxonomy_term', 'field_iso3', 'value');
    $iso3s = array_map('strtoupper', $this->getDatabase()
      ->select($table, $table)
      ->fields($table, [$field])
      ->condition($table . '.bundle', 'country', '=')
      ->distinct()
      ->execute()
      ?->fetchCol() ?? []);

    // Add "Non-Localized" option.
    $iso3s[] = "---";

    // Some countries have a different ISO3 code that the ones on ReliefWeb
    // so we allow passing those exceptions.
    $exceptions = $this->state->get('reliefweb_iso3_glide_exceptions', []);
    if (!empty($exceptions)) {
      $iso3s = array_unique(array_merge($iso3s, $exceptions));
    }

    $pattern = '/^(' . implode('|', $codes) . ')-\d{4}-\d{6}-(' . implode('|', $iso3s) . ')$/';

    // Validate the Glide number pattern.
    if (preg_match($pattern, $glide) !== 1) {
      $url = Url::fromUri('https://glidenumber.net');
      $link = Link::fromTextAndUrl($this->t('glidenumber.net'), $url)->toString();
      $form_state->setErrorByName('field_glide][0][value', $this->t('Invalid Glide number format, please visit @link', [
        '@link' => $link,
      ]));
    }
  }

  /**
   * Validate the GLIDE number uniqueness.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateGlideUniqueness(array $form, FormStateInterface $form_state) {
    $entity_id = $form_state->getFormObject()->getEntity()->id();
    $glide = $form_state->getValue(['field_glide', 0, 'value'], '');

    if (empty($glide)) {
      return;
    }

    $table = $this->getEntityTypeDataTable('taxonomy_term');
    $id_field = $this->getEntityTypeIdField('taxonomy_term');
    $label_field = $this->getEntityTypeLabelField('taxonomy_term');

    $field_table = $this->getFieldTableName('taxonomy_term', 'field_glide');
    $field_column_name = $this->getFieldColumnName('taxonomy_term', 'field_glide', 'value');

    $query = $this->getDatabase()->select($table, $table);
    $query->fields($table, [$id_field, $label_field]);

    $field_table_alias = $query->innerJoin($field_table, $field_table, "%alias.entity_id = {$table}.{$id_field}");
    $query->condition($field_table_alias . '.' . $field_column_name, $glide, '=');
    $query->condition($field_table_alias . '.bundle', 'disaster', '=');

    if (!empty($entity_id)) {
      $query->condition($field_table_alias . '.entity_id', $entity_id, '<>');
    }

    $links = [];
    foreach ($query->execute() ?? [] as $record) {
      $url = Url::fromUserInput('/taxonomy/term/' . $record->{$id_field});
      $links[] = Link::fromTextAndUrl($record->{$label_field}, $url)->toString();
    }

    if (!empty($links)) {
      // We need the double Markups to avoid the links to be escaped.
      // We also don't pass a field to the setErrorByName so the the message
      // appears at the top.
      $message = $this->t('Disaster(s) with the same glide number already exist: @links', [
        '@links' => Markup::create(implode(', ', $links), []),
      ]);
      $form_state->setErrorByName('', Markup::create($message));
    }
  }

}
