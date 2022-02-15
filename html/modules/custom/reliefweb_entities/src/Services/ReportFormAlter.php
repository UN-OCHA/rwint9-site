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
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
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
    $form['field_disaster']['#attributes']['data-with-autocomplete'] = 'disasters';

    // Add an autocomplete widget to the tags.
    $form['field_disaster_type']['#attributes']['data-with-autocomplete'] = '';
    $form['field_content_format']['#attributes']['data-with-autocomplete'] = '';
    $form['field_theme']['#attributes']['data-with-autocomplete'] = '';
    $form['field_vulnerable_groups']['#attributes']['data-with-autocomplete'] = '';

    // Add a datepicker widget to the report date field.
    $form['field_original_publication_date']['widget'][0]['value']['#attributes']['data-with-datepicker'] = '';

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

    // Add validation callbacks for the file and embargo date fields.
    $form['#validate'][] = [$this, 'validateAttachment'];
    $form['#validate'][] = [$this, 'validateEmbargoDate'];

    // Prevent saving from a blocked source.
    $form['#validate'][] = [$this, 'validateBlockedSource'];
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
    // We put the visibility state on the container to avoid styling issues with
    // the surrounding elements but we need to to put the required state on the
    // widget element for it to work properly.
    $form['field_ocha_product']['#states']['visible'] = $condition;
    $widget['#states']['required'] = $condition;

    // Remove the empty option.
    unset($widget['#options']['_none']);

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
      $form_state->setValue(['field_ocha_product', 0, 'target_id'], NULL);
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

    $formats = $form_state->getValue(['field_content_format']);
    if (empty($formats)) {
      return;
    }

    // Normally there is only 1 content format allowed.
    foreach ($formats as $item) {
      $format = $visual_formats[$item['target_id']] ?? NULL;
      // File and its preview are mandatory for visual content when publishing.
      if (isset($format)) {
        $file = $form_state->getValue(['field_file', 0, 'uuid']);
        // #4L7i0wbW - File is mandatory.
        if (empty($file)) {
          $status = $this->getEntityModerationStatus($form_state);
          if (in_array($status, ['to-review', 'published'])) {
            $form_state->setErrorByName('field_file][add_more', $this->t('The content format is %format. You must attach a file.', [
              '%format' => $format,
            ]));
          }
        }
        // Preview is also mandatory.
        else {
          $preview_file = $form_state->getValue([
            'field_file', 0, 'preview_uuid',
          ]);
          $preview_page = $form_state->getValue([
            'field_file', 0, 'preview_page',
          ]);
          if (empty($preview_file) || empty($preview_page)) {
            $form_state->setErrorByName('field_file][0][preview_page', $this->t('The content format is %format. You must enable the "preview" for the attachment.', [
              '%format' => $format,
            ]));
          }
        }
      }
      break;
    }
  }

  /**
   * Validate the embargo date field.
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
