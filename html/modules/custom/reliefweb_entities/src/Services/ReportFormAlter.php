<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_form\Helpers\FormHelper;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
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

    // Alter the embargo field.
    $this->alterEmbargoDateField($form, $form_state);

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

    // Remove interactive (38974) format if the report is not tagged with it.
    // @see https://humanitarian.atlassian.net/browse/RW-1077
    $report = $form_state?->getFormObject()?->getEntity();
    if ($report instanceof Report && $report?->field_content_format?->target_id != 38974) {
      FormHelper::removeOptions($form, 'field_content_format', [38974]);
    }

    // Remove Key document (2) option for feature field.
    FormHelper::removeOptions($form, 'field_feature', [2]);

    // Remove Other language (31996) option for language field.
    FormHelper::removeOptions($form, 'field_language', [31996]);

    // Remove Complex Emergency (41764) option for disaster type field.
    FormHelper::removeOptions($form, 'field_disaster_type', [41764]);

    // Change the description of the file field to indicate that only PDF files
    // are accepted in terms of editorial guidance.
    if (isset($form['field_file']['widget']['add_more']['files']['#description'])) {
      $description = $form['field_file']['widget']['add_more']['files']['#description'];
      $form['field_file']['widget']['add_more']['files']['#description'] = $this->t(
        'PDF only. Max file size: %max_filesize.',
        $description->getArguments(),
        $description->getOptions()
      );
    }

    // Special tweaks for contributors.
    if ($this->currentUser->hasRole('contributor')) {
      $this->alterFieldsForContributors($form, $form_state);
    }

    // Validate the attachments.
    $form['#validate'][] = [$this, 'validateAttachment'];

    // Validate the embargo date.
    $form['#validate'][] = [$this, 'validateEmbargoDate'];

    // Prevent saving from a blocked source.
    $form['#validate'][] = [$this, 'validateBlockedSource'];

    // Prevent saving if user is blocked for a source.
    $form['#validate'][] = [$this, 'validatePostingRightsBlockedSource'];
  }

  /**
   * Prevent saving if user is blocked for a source.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validatePostingRightsBlockedSource(array $form, FormStateInterface &$form_state) {
    $ids = [];
    foreach ($form_state->getValue('field_source', []) as $item) {
      if (!empty($item['target_id'])) {
        $ids[] = $item['target_id'];
      }
    }

    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight($this->currentUser, 'report', $ids);
    // Blocked for at least one source.
    if (!empty($rights) && isset($rights['code']) && $rights['code'] == 1) {
      $sources = $this->getEntityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadMultiple($rights['sources']);

      /** @var \Drupal\taxonomy\Entity\Term $term */
      array_walk($sources, function (&$term) {
        $term = $term->label();
      });

      $form_state->setErrorByName('field_source', $this->t('Publications from "@sources" are not allowed.', [
        '@sources' => implode('", "', $sources),
      ]));
    }
  }

  /**
   * Modify the embargo date field.
   *
   * Set a proper range for the year widget (current year up to 1 year after).
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @see https://www.drupal.org/project/drupal/issues/2836054
   */
  protected function alterEmbargoDateField(array &$form, FormStateInterface $form_state) {
    $min_year = gmdate('Y');
    $max_year = $min_year + 1;

    $form['field_embargo_date']['widget'][0]['value']['#date_year_range'] = $min_year . ':' . $max_year;
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
   * Note: the OCHA product field is only displayed when OCHA is selected, in
   * which case the field is also marked as required. This is handled by the
   * `reliefweb_form/widget.autocomplete` library in the `handleSources()`
   * function because Drupal form states don't provide a way to apply a state
   * when a value is among a list of selected values. Only exact matchs are
   * supported in Drupal 10.2.5.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @see https://www.drupal.org/project/drupal/issues/1149078
   * @see https://humanitarian.atlassian.net/browse/RW-934
   * @see reliefweb_form/widget.autocomplete
   */
  protected function alterOchaProductField(array &$form, FormStateInterface $form_state) {
    $widget = &$form['field_ocha_product']['widget'];

    // Remove "Press Review" (12351) OCHA product option if not selected as it's
    // not to be used anymore but we still want to preserve the docs already
    // tagged with it. (https://trello.com/c/kTiP7N2E)
    if (empty($widget['#default_value']) || $widget['#default_value'] == 12351) {
      FormHelper::removeOptions($form, 'field_ocha_product', [12351]);
    }

    // Remove the empty option.
    unset($widget['#options']['_none']);

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
        $files = $form_state->getValue(['field_file']);
        // #4L7i0wbW - File is mandatory.
        if (empty($files[0]['uuid'])) {
          $status = $this->getEntityModerationStatus($form_state);
          if (in_array($status, ['to-review', 'published'])) {
            $form_state->setErrorByName('field_file', $this->t('The content format is %format. You must attach a file.', [
              '%format' => $format,
            ]));
          }
        }
        // Preview is also mandatory.
        else {
          // Sort by weight.
          usort($files, function ($a, $b) {
            return SortArray::sortByKeyInt($a, $b, '_weight');
          });

          if (empty($files[0]['preview_uuid']) || empty($files[0]['preview_page'])) {
            $form_state->setErrorByName('field_file', $this->t('The content format is %format. The first attachment must have a "preview".', [
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
    // If there is a valid date (with year, month etc.), then $embargo_date
    // is a \Drupal\Core\Datetime\DrupalDateTime instance...
    if (!empty($embargo_date) && $embargo_date instanceof DrupalDateTime && $embargo_date->getTimestamp() < time()) {
      $form_state->setErrorByName('field_embargo_date][0][value', $this->t('The embargo date cannot be in the past.'));
    }
  }

  /**
   * Make alterations for Contributor role.
   *
   * Embargo date cannot be in the past as that would not make sense.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterFieldsForContributors(array &$form, FormStateInterface $form_state) {
    // Default to submit.
    $form['field_origin']['widget']['#default_value'] = 1;

    // Hide fields.
    $form['field_origin']['#access'] = FALSE;
    $form['field_origin_notes']['#access'] = FALSE;

    $form['field_bury']['#access'] = FALSE;
    $form['field_feature']['#access'] = FALSE;
    $form['field_notify']['#access'] = FALSE;

    $form['field_headline']['#access'] = FALSE;
    $form['field_headline_title']['#access'] = FALSE;
    $form['field_headline_summary']['#access'] = FALSE;
    $form['field_headline_image']['#access'] = FALSE;
  }

}
