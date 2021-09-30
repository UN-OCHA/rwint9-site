<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_form\Helpers\FormHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Report form alteration service.
 */
class ReportFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state) {
    // Add the guidelines.
    $form['#attributes']['data-with-guidelines'] = '';

    // Use a small textarea to handle long report titles.
    if (isset($form['title'])) {
      $form['title']['widget'][0]['value']['#type'] = 'textarea';
      $form['title']['widget'][0]['value']['#rows'] = 2;
      $form['title']['widget'][0]['value']['#attributes']['maxlength'] = $form['title']['#maxlength'] ?? 255;
      $form['title']['widget'][0]['value']['#resizable'] = FALSE;
      // Add formatting widget to the title field.
      $form['title']['widget'][0]['value']['#attributes']['data-with-formatting'] = 'text';
    }

    // Add an autocomplete widget to the country and source fields.
    $form['field_country']['#attributes']['data-with-autocomplete'] = '';
    $form['field_primary_country']['#attributes']['data-with-autocomplete'] = 'primary';
    $form['field_source']['#attributes']['data-with-autocomplete'] = 'sources';
    $form['field_source']['#attributes']['data-selection-messages'] = '';
    $form['field_source']['#attributes']['data-autocomplete-path'] = Url::fromRoute('reliefweb_form.node_form.source_attention_messages', [
      'bundle' => 'report',
    ])->toString();

    // Add an autocomplete widget to the disaster field.
    // @todo review the javascript.
    $form['field_disaster']['#attributes']['data-with-autocomplete'] = '';

    // Add an autocomplete widget to the tags.
    $form['field_disaster_type']['#attributes']['data-with-autocomplete'] = '';
    $form['field_content_format']['#attributes']['data-with-autocomplete'] = '';
    $form['field_theme']['#attributes']['data-with-autocomplete'] = '';
    $form['field_vulnerable_groups']['#attributes']['data-with-autocomplete'] = '';

    // Add a datepicker widget to the report date field.
    $form['field_original_publication_date']['#attributes']['data-with-datepicker'] = '';

    // Add PDF formatting widget to the body field.
    $form['body']['#attributes']['data-with-formatting'] = 'pdf';

    // Alter the primary country field, ensuring it's using a value among
    // the selected country values.
    $this->alterPrimaryField('field_primary_country', $form, $form_state);

    // Alter the headline fields.
    $this->alterHeadlineFields($form, $form_state);

    // Alter the origin fields, setting the origin notes as mandatory when
    // 'URL' is selected.
    $this->alterOriginFields($form, $form_state);

    // Alter the OCHA product field, ensuring only 1 is selectable and making
    // mandatory when OCHA is selected as source or hidden otherwise.
    $this->alterOchaProductField($form, $form_state);

    // Remove data (9420) option for content format field (Collab #3679).
    FormHelper::removeOptions($form, 'field_content_format', [9420]);

    // Remove Key document (2) option for feature field.
    FormHelper::removeOptions($form, 'field_feature', [2]);

    // Remove Other language (31996) option for language field.
    FormHelper::removeOptions($form, 'field_language', [31996]);

    // Remove Complex Emergency (41764) option for disaster type field.
    FormHelper::removeOptions($form, 'field_disaster_type', [41764]);

    // Add validation callbacks for the file, source and embargo date fields.
    $form['#validate'][] = [$this, 'validateAttachment'];
    $form['#validate'][] = [$this, 'validateSource'];
    $form['#validate'][] = [$this, 'validateEmbargoDate'];

    // Let the base service add additional alterations.
    parent::alterForm($form, $form_state);
  }

  /**
   * Modify the headline fields.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterHeadlineFields(array &$form, FormStateInterface $form_state) {
    $form['field_headline_summary']['widget'][0]['value']['#rows'] = 3;
    // Add the length checker widget to the summary field. As of December 2019,
    // the average length for the headline summary is 182 characters with the
    // majority of the summaries between 160 and 200 characters.
    $form['field_headline_summary']['#attributes']['data-with-lengthchecker'] = '160-200';

    // Mark the headline fields as mandatory when headline is checked.
    $condition = [
      ':input[name="field_headline[value]"]' => ['checked' => TRUE],
    ];
    $form['field_headline_title']['widget'][0]['value']['#states']['required'] = $condition;
    $form['field_headline_summary']['widget'][0]['value']['#states']['required'] = $condition;

    // Validate headline title.
    $form['#validate'][] = [$this, 'validateHeadlineFields'];
  }

  /**
   * Validate the headline fields.
   *
   * Make sure the headline title and summary are not empty if headline checkbox
   * is unchecked.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateHeadlineFields(array $form, FormStateInterface $form_state) {
    $headline = $form_state->getValue(['field_headline', 'value']);
    if (!empty($headline)) {
      // Check the title.
      $headline_title = $form_state
        ->getValue(['field_headline_title', 0, 'value']);
      if (empty($headline_title)) {
        $form_state->setErrorByName('field_headline_title][0][value', $this->t('You must enter a headline title if you set this document as a headline.'));
      }

      // Check the summary.
      $headline_summary = $form_state
        ->getValue(['field_headline_summary', 0, 'value']);
      if (empty($headline_summary)) {
        $form_state->setErrorByName('field_headline_summary][0][value', $this->t('You must enter a headline summary if you set this document as a headline.'));
      }
    }
  }

  /**
   * Modify the origin fields.
   *
   * Make the origin notes field mandatory when URL is selected as origin.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterOriginFields(array &$form, FormStateInterface $form_state) {
    // Make the origin notes field mandatory when URL is selected as origin.
    $condition = [
      ':input[name="field_origin"]' => ['value' => '0'],
    ];
    $form['field_origin_notes']['widget'][0]['value']['#states']['required'] = $condition;

    // Validate the origin notes field when URL is selected as origin.
    $form['#validate'][] = [$this, 'validateOriginFields'];
  }

  /**
   * Validate the origin and origin notes fields.
   *
   * Ensure there is a valid value for the origin notes if the origin is a URL.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateOriginFields(array $form, FormStateInterface &$form_state) {
    $origin = $form_state->getValue(['field_origin', 0, 'value']);

    // The origin field is mandatory so if it's not set, then an error will be
    // displayed by the normal validation callbacks. When set, check the origin
    // notes.
    if (isset($origin)) {
      $notes = $form_state->getValue(['field_origin_notes', 0, 'value']);
      if (!empty($notes)) {
        $notes = trim($notes);
      }

      // For the values, see reliefweb_reports.features.field_base.inc.
      // We use strict comparison against strings as the int 0 has a different
      // meaning.
      if ($origin === '0') {
        if (empty($notes) || !UrlHelper::isValid($notes, TRUE)) {
          $form_state->setErrorByName('field_origin_notes][0][value', $this->t('Identify the origin of this report (URL starting with https or http).'));
        }
      }
      elseif ($origin === '1') {
        if (!empty($notes) && !UrlHelper::isValid($notes, TRUE)) {
          $form_state->setErrorByName('field_origin_notes][0][value', $this->t('Invalid origin notes. It must be empty or the origin URL of the document (starting with https or http).'));
        }
      }
      else {
        if (!empty($notes)) {
          $form_state->setErrorByName('field_origin_notes][0][value', $this->t('Invalid origin notes. It must be empty.'));
        }
      }
    }
  }

  /**
   * Modify the OCHA product field.
   *
   * Ensure only 1 OCHA product is selectable and that it's mandatory when OCHA
   * is selected as source or hidden otherwise.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterOchaProductField(array &$form, FormStateInterface $form_state) {
    $widget = &$form['field_ocha_product']['widget'];

    // Remove "Press Review" (12351) OCHA product option if not selected as it's
    // not to be used anymore but we still want to preserve the docs already
    // tagged with it. (https://trello.com/c/kTiP7N2E)
    if (empty($widget['#default_value']) || $widget['#default_value'] == 12351) {
      FormHelper::removeOptions($form, 'field_ocha_product', [12351]);
    }

    // Only display the OCHA product when OCHA is selected.
    $condition = [
      'select[name="field_source[]"]' => ['value' => ['1503']],
    ];
    $widget['#states']['visible'] = $condition;
    $widget['#states']['required'] = $condition;

    // For the above to work we need the drupal states extension.
    // @todo remove if the equivalent is added to core.
    // @see https://www.drupal.org/project/drupal/issues/1149078
    $form['#attached']['library'][] = 'reliefweb_form/drupal.states';

    // Add a validation callback to ensure that an OCHA product is selected
    // when OCHA is selected as source.
    $form['#validate'][] = [$this, 'validateOchaProductField'];
  }

  /**
   * Validate the OCHA product field.
   *
   * OCHA product must be selected when OCHA is selected as source
   * (trello #KYPCnkXd).
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateOchaProductField(array $form, FormStateInterface &$form_state) {
    $ocha_product = $form_state->getValue(['field_ocha_product', 0, 'target_id']);

    // Check if OCHA (id: 1503) is selected.
    $selected = FALSE;
    foreach ($form_state->getValue('field_source', []) as $item) {
      // We don't use a strict equality as tid may be a numeric string...
      if (isset($item['target_id']) && $item['target_id'] == 1503) {
        $selected = TRUE;
        break;
      }
    }

    // The OCHA product is mandatory when OCHA is selected.
    if ($selected && empty($ocha_product)) {
      $form_state->setErrorByName('field_ocha_product', $this->t('OCHA Product is mandatory when OCHA is selected as source.'));
    }
    // Remove the ocha product otherwise.
    elseif (!$selected && !empty($ocha_product)) {
      $form_state->getValue(['field_ocha_product', 0, 'target_id'], NULL);
    }
  }

  /**
   * Validate the file field.
   *
   * Ensure there is a file attachment with "show preview" selected when the
   * format is Map (Id: 12), Infographic (Id: 12570) or Interactive (Id: 38974).
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateAttachment(array $form, FormStateInterface &$form_state) {
    $visual_formats = [
      '12' => 'Map',
      '12570' => 'Infographic',
      '38974' => 'Interactive',
    ];

    $content_format = $form_state
      ->getValue(['field_content_format', 0, 'target_id']);

    // File and its preview are mandatory for visual content when publishing.
    if (isset($visual_formats[$content_format])) {
      // @todo add file check - RW-138.
    }
  }

  /**
   * Validate the file field.
   *
   * Prevent saving a document from a blocked source.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @todo Extends that to jobs and training.
   */
  public function validateSource(array $form, FormStateInterface &$form_state) {
    $ids = [];
    foreach ($form_state->getValue('field_source', []) as $item) {
      if (!empty($item['target_id'])) {
        $ids[] = $item['target_id'];
      }
    }

    if (!empty($ids)) {
      $entity_type_manager = $this->getEntityTypeManager();

      $taxonomy_term_entity_type = $entity_type_manager
        ->getStorage('taxonomy_term')
        ->getEntityType();

      $table = $taxonomy_term_entity_type->getDataTable();
      $id_field = $taxonomy_term_entity_type->getKey('id');
      $label_field = $taxonomy_term_entity_type->getKey('label');

      $query = $this->getDatabase()()->select($table, $table);
      $query->fields($table, [$label_field]);
      $query->condition($table . '.' . $id_field, $ids, 'IN');

      // Join the moderation status table to check the status.
      $status_table = $entity_type_manager
        ->getStorage('content_moderation_state')
        ->getEntityType()
        ->getDataTable();

      $status_alias = $query->innerJoin($status_table, $status_table, "%alias.content_entity_id = {$table}.{$id_field}");
      $query->condition($status_alias . '.content_entity_type_id', 'taxonomy_term', '=');
      $query->condition($status_alias . '.moderation_state', 'blocked', '=');

      $sources = $query->execute()?->fetchCol() ?? [];

      if (!empty($sources)) {
        $form_state->setErrorByName('field_source', $this->t('Publications from "@sources" are not allowed.', [
          '@sources' => implode('", "', $sources),
        ]));
      }
    }
  }

  /**
   * Validate the source field.
   *
   * Embargo date cannot be in the past as that would not make sense.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateEmbargoDate(array $form, FormStateInterface &$form_state) {
    $embargo_date = $form_state->getValue(['field_embargo_date', 0, 'value']);
    if (!empty($embargo_date) && $embargo_date->getTimestamp() < time()) {
      $form_state->setErrorByName('field_embargo_date][0][value', $this->t('The embargo date cannot be in the past.'));
    }
  }

}
