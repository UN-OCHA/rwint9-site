<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_files\Plugin\Field\FieldWidget\ReliefWebFile as ReliefWebFileWidget;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile as ReliefWebFileItem;
use Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface;
use Drupal\reliefweb_form\Helpers\FormHelper;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Report form alteration service.
 */
class ReportFormAlter extends EntityFormAlterServiceBase {

  /**
   * The ReliefWeb file duplication service.
   *
   * @var \Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface
   */
  protected $fileDuplication;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The user posting rights manager.
   *
   * @var \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface
   */
  protected $userPostingRightsManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   * @param \Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface $file_duplication
   *   The file duplication service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $user_posting_rights_manager
   *   The user posting rights manager service.
   */
  public function __construct(
    $database,
    $current_user,
    $entity_field_manager,
    $entity_type_manager,
    $state,
    $string_translation,
    ReliefWebFileDuplicationInterface $file_duplication,
    RequestStack $request_stack,
    RendererInterface $renderer,
    MessengerInterface $messenger,
    UserPostingRightsManagerInterface $user_posting_rights_manager,
  ) {
    parent::__construct(
      $database,
      $current_user,
      $entity_field_manager,
      $entity_type_manager,
      $state,
      $string_translation
    );
    $this->fileDuplication = $file_duplication;
    $this->requestStack = $request_stack;
    $this->renderer = $renderer;
    $this->messenger = $messenger;
    $this->userPostingRightsManager = $user_posting_rights_manager;
  }

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
    if (isset($form['field_country'])) {
      $form['field_country']['#attributes']['data-with-autocomplete'] = '';
    }
    if (isset($form['field_primary_country'])) {
      $form['field_primary_country']['#attributes']['data-with-autocomplete'] = 'primary';
    }
    if (isset($form['field_source'])) {
      $form['field_source']['#attributes']['data-with-autocomplete'] = 'sources';
      $form['field_source']['#attributes']['data-selection-messages'] = '';
      $form['field_source']['#attributes']['data-autocomplete-path'] = Url::fromRoute('reliefweb_form.node_form.source_attention_messages', [
        'bundle' => 'report',
      ])->toString();
    }

    // Add an autocomplete widget to the disaster field.
    if (isset($form['field_disaster'])) {
      $form['field_disaster']['#attributes']['data-with-autocomplete'] = 'disasters';
    }

    // Add an autocomplete widget to the tags.
    if (isset($form['field_disaster_type'])) {
      $form['field_disaster_type']['#attributes']['data-with-autocomplete'] = '';
    }
    if (isset($form['field_content_format'])) {
      $form['field_content_format']['#attributes']['data-with-autocomplete'] = '';
    }
    if (isset($form['field_theme'])) {
      $form['field_theme']['#attributes']['data-with-autocomplete'] = '';
    }
    if (isset($form['field_vulnerable_groups'])) {
      $form['field_vulnerable_groups']['#attributes']['data-with-autocomplete'] = '';
    }

    // Add a datepicker widget to the report date field.
    if (isset($form['field_original_publication_date'])) {
      $form['field_original_publication_date']['widget'][0]['value']['#attributes']['data-with-datepicker'] = '';
    }

    // Add PDF formatting widget to the body field.
    if (isset($form['body'])) {
      $form['body']['#attributes']['data-with-formatting'] = 'pdf';
    }

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

    // Remove Complex Emergency (41764) option for disaster type field.
    FormHelper::removeOptions($form, 'field_disaster_type', [41764]);

    $entity = $form_state->getFormObject()?->getEntity();
    // Only keep the "API" origin if the document was submitted via the API.
    if (isset($entity) && $entity->hasField('field_post_api_provider') && !empty($entity->field_post_api_provider?->target_id)) {
      FormHelper::removeOptions($form, 'field_origin', [0, 1, 2]);
    }
    // Otherwise hide it.
    else {
      FormHelper::removeOptions($form, 'field_origin', [3]);
    }

    // Validate the attachments.
    $form['#validate'][] = [$this, 'validateAttachment'];

    // Validate the embargo date.
    $form['#validate'][] = [$this, 'validateEmbargoDate'];

    // Prevent saving from a blocked source.
    $form['#validate'][] = [$this, 'validateBlockedSource'];

    // Prevent saving if user is blocked for a source.
    $form['#validate'][] = [$this, 'validatePostingRightsBlockedSource'];

    // Check for duplicate files and display warning message.
    $this->checkForDuplicateFiles($form, $form_state);

    $this->addRoleFormAlterations($form, $form_state);
  }

  /**
   * Alter the form based on the current user role.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function addRoleFormAlterations(array &$form, FormStateInterface $form_state): void {
    if ($this->currentUser->hasRole('editor')) {
      // Nothing to do. The form is already configured for that role.
      return;
    }
    elseif ($this->currentUser->hasRole('contributor')) {
      // Tweak the form for contributors.
      $this->alterFieldsForContributors($form, $form_state);
    }
    elseif ($this->currentUser->hasRole('submitter')) {
      // Tweak the form for submitters.
      $this->alterFieldsForSubmitters($form, $form_state);
    }
  }

  /**
   * Make alterations for Contributor role.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterFieldsForContributors(array &$form, FormStateInterface $form_state) {
    // Default to submit for new documents otherwise preserve the value, for
    // example when editing a report created by an editor.
    if ($form_state->getFormObject()?->getEntity()?->isNew() === TRUE) {
      $form['field_origin']['widget']['#default_value'] = '1';
    }
    // Change the field to 'hidden' to hide it while perserving its value so
    // that the alteration and validation of the origin notes field still work.
    // @see ::alterOriginFields()
    $form['field_origin']['widget']['#type'] = 'hidden';

    // Remove autocomplete path to get attention messages for sources.
    unset($form['field_source']['#attributes']['data-autocomplete-path']);

    // Hide fields.
    $form['field_embargo_date']['#access'] = FALSE;
    $form['field_ocha_product']['#access'] = FALSE;
    $form['field_feature']['#access'] = FALSE;
    $form['field_notify']['#access'] = FALSE;

    $form['field_headline']['#access'] = FALSE;
    $form['field_headline_title']['#access'] = FALSE;
    $form['field_headline_summary']['#access'] = FALSE;
    $form['field_headline_image']['#access'] = FALSE;
  }

  /**
   * Make alterations for Submitter role.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterFieldsForSubmitters(array &$form, FormStateInterface $form_state) {
    $new = $form_state->getFormObject()?->getEntity()?->isNew() === TRUE;

    // Retrieve the form settings.
    $settings = $this->state->get('reliefweb_users_submitter_form_settings', []);

    // Add the instructions at the top and bottom of the form.
    if (!empty($settings['instructions']['header']['value'])) {
      $form['header_instructions'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'rw-form-instructions',
            'rw-form-instructions--header',
          ],
        ],
        'text' => [
          '#type' => 'processed_text',
          '#text' => $settings['instructions']['header']['value'],
          '#format' => $settings['instructions']['header']['format'] ?? 'markdown_editor',
        ],
      ];
    }
    if (!empty($settings['instructions']['footer']['value'])) {
      $form['footer_instructions'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'rw-form-instructions',
            'rw-form-instructions--footer',
          ],
        ],
        'text' => [
          '#type' => 'processed_text',
          '#text' => $settings['instructions']['footer']['value'],
          '#format' => $settings['instructions']['footer']['format'] ?? 'markdown_editor',
        ],
      ];
    }

    // Indicate that we are using the submitter form.
    $form['#attributes']['class'][] = 'rw-entity-form--report--submitter';

    // Simplify title.
    $form['title']['widget'][0]['value']['#rows'] = 1;
    unset($form['title']['widget'][0]['value']['#attributes']['data-with-formatting']);

    // Do not restrict the values selectable for the primary country if the
    // country field is not there otherwise the user cannot select anything.
    if (isset($form['field_primary_country']) && !isset($form['field_country'])) {
      $form['field_primary_country']['#attributes']['data-with-autocomplete'] = '';
    }

    // Default to submit for new documents otherwise preserve the value, for
    // example when editing a report created by an editor.
    if (isset($form['field_origin'])) {
      if ($new) {
        $form['field_origin']['widget']['#default_value'] = '1';
      }
      // Hide the origin field.
      $form['field_origin']['widget']['#type'] = 'hidden';
    }

    if (isset($form['field_origin_notes'])) {
      $form['field_origin_notes']['widget'][0]['value']['#title'] = $this->t('Origin URL');
    }

    // Make the attachment field mandatory.
    if (isset($form['field_file'])) {
      $form['field_file']['widget']['#element_validate'][] = [$this, 'validateMandatoryFileField'];
      $form['field_file']['widget']['#required'] = TRUE;
    }

    if (isset($form['field_file']['widget']['add_more']['files'])) {
      $form['field_file']['#process'][] = [$this, 'fileFieldUpdateValidators'];
    }

    if (isset($form['field_notify'])) {
      // Populate the notify field with the submitter email address so that
      // they can be notified when their submission is published.
      // This only applies if the document goes through the AI processing.
      if ($new && $this->currentUser->hasPermission('apply ocha content classification to node report')) {
        $form['field_notify']['widget'][0]['value']['#default_value'] = $this->currentUser->getEmail();
      }
      // Hide the notify field.
      $form['field_notify']['widget']['#type'] = 'hidden';
    }

    // Improve labels and descriptions.
    if (isset($form['field_source'])) {
      $form['field_source']['widget']['#title'] = $this->t('Source(s)');
    }
    if (isset($form['field_language'])) {
      $form['field_language']['widget']['#title'] = $this->t('Language(s)');
    }
    if (isset($form['field_primary_country'])) {
      $form['field_primary_country']['widget']['#description'] = $this->t('For global content, select World.');
    }

    // Remove autocomplete path to get attention messages for sources.
    unset($form['field_source']['#attributes']['data-autocomplete-path']);

    // Disallow selecting a publication date in the future.
    if (isset($form['field_original_publication_date']['widget'][0]['value'])) {
      $form['field_original_publication_date']['widget'][0]['#element_validate'][] = [
        $this,
        'validateDateNotInFuture',
      ];

      // Change format.
      // @see RW-1231
      $form['field_original_publication_date']['widget'][0]['value']['#attributes']['data-date-format'] = 'DD-MM-YYYY';
    }

    // Set the custom field descriptions.
    foreach ($settings['fields'] ?? [] as $field => $field_instructions) {
      if (isset($form[$field]['widget']) && !empty($field_instructions['value'])) {
        $field_description = check_markup($field_instructions['value'], $field_instructions['format']);
        $form[$field]['widget']['#description'] = $field_description;
      }
    }

    // Set the custom save buttons descriptions.
    if ($new && !empty($settings['buttons']['create']['value'])) {
      $buttons_description = check_markup($settings['buttons']['create']['value'], $settings['buttons']['create']['format']);
    }
    elseif (!$new && !empty($settings['buttons']['update']['value'])) {
      $buttons_description = check_markup($settings['buttons']['update']['value'], $settings['buttons']['update']['format']);
    }
    if (!empty($buttons_description)) {
      // We are not adding a `$form['actions']['#description]` because, first
      // it's removed by the moderation service when adding the buttons and,
      // secondly, it's not displayed because the actions use a `container`
      // theme wrapper.
      $form['buttons_description'] = [
        '#type' => 'item',
        '#description_display' => 'after',
        '#description' => $buttons_description,
      ];
    }
  }

  /**
   * Process callback for the file field to update validation error messages.
   *
   * Check that there is at least one attachment.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form element.
   */
  public function fileFieldUpdateValidators(array $element, FormStateInterface $form_state): array {
    if (!isset($element['widget'])) {
      return $element;
    }

    // Retrieve the "add more" and existing "replace" file form elements.
    $file_elements = [];
    foreach (Element::Children($element['widget']) as $key) {
      $child = $element['widget'][$key];
      if (isset($child['files']['#upload_validators'])) {
        $file_elements[] = &$element['widget'][$key]['files'];
      }
      elseif (isset($child['operations']['file']['#upload_validators'])) {
        $file_elements[] = &$element['widget'][$key]['operations']['file'];
      }
    }

    if (empty($file_elements)) {
      return $element;
    }

    // Retrieve the form settings.
    $settings = $this->state->get('reliefweb_users_submitter_form_settings', []);

    // Add the custom file size limit error message.
    if (!empty($settings['errors']['file_too_large']['value'])) {
      $message = (string) check_markup($settings['errors']['file_too_large']['value'], $settings['errors']['file_too_large']['format']);

      foreach ($file_elements as &$file_element) {
        $file_element['#upload_validators']['FileSizeLimit']['maxFileSizeMessage'] = $message;
      }
    }
    // Add the custom file duplicate error.
    if (!empty($settings['errors']['file_duplicate']['value'])) {
      $message = (string) check_markup($settings['errors']['file_duplicate']['value'], $settings['errors']['file_duplicate']['format']);
      foreach ($file_elements as &$file_element) {
        $file_element['#upload_validators']['ReliefWebFileHash']['duplicateFileFormError'] = $message;
      }
    }

    return $element;
  }

  /**
   * Validate the file field.
   *
   * Check that there is at least one attachment.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateMandatoryFileField(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValue('field_file', []);
    unset($values['add_more']);
    if (empty($values)) {
      $form_state->setErrorByName('field_file', $this->t('At least one attachment is required.'));
    }
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
    if (!isset($form['field_source'])) {
      return;
    }
    $ids = [];
    foreach ($form_state->getValue('field_source', []) as $item) {
      if (!empty($item['target_id'])) {
        $ids[] = $item['target_id'];
      }
    }

    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight($this->currentUser, 'report', $ids);
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
    if (!isset($form['field_embargo_date'])) {
      return;
    }
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
    $condition = [
      ':input[name="field_headline[value]"]' => ['checked' => TRUE],
    ];

    if (isset($form['field_headline_title'])) {
      // Mark the headline title field as mandatory when headline is checked.
      if (isset($form['field_headline'])) {
        $form['field_headline_title']['widget'][0]['value']['#states']['required'] = $condition;
      }
    }

    if (isset($form['field_headline_summary'])) {
      $form['field_headline_summary']['widget'][0]['value']['#rows'] = 3;
      // Add the length checker widget to the summary field. As of December
      // 2019, the average length for the headline summary is 182 characters
      // with the majority of the summaries between 160 and 200 characters.
      $form['field_headline_summary']['#attributes']['data-with-lengthchecker'] = '160-200';

      // Mark the headline summary field as mandatory when headline is checked.
      if (isset($form['field_headline'])) {
        $form['field_headline_summary']['widget'][0]['value']['#states']['required'] = $condition;
      }
    }

    // Validate headline title.
    if (isset($form['field_headline'])) {
      $form['#validate'][] = [$this, 'validateHeadlineFields'];
    }
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
      if (isset($form['field_headline_title'])) {
        $headline_title = $form_state
          ->getValue(['field_headline_title', 0, 'value']);
        if (empty($headline_title)) {
          $form_state->setErrorByName('field_headline_title][0][value', $this->t('You must enter a headline title if you set this document as a headline.'));
        }
      }

      // Check the summary.
      if (isset($form['field_headline_summary'])) {
        $headline_summary = $form_state
          ->getValue(['field_headline_summary', 0, 'value']);
        if (empty($headline_summary)) {
          $form_state->setErrorByName('field_headline_summary][0][value', $this->t('You must enter a headline summary if you set this document as a headline.'));
        }
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
    if (isset($form['field_origin'], $form['field_origin_notes'])) {
      // Make the origin notes field mandatory when URL is selected as origin.
      $condition = [
        ':input[name="field_origin"]' => ['value' => '0'],
      ];

      $form['field_origin_notes']['widget'][0]['value']['#states']['required'] = $condition;

      // Validate the origin notes field when URL is selected as origin.
      $form['#validate'][] = [$this, 'validateOriginFields'];
    }
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
    if (!isset($form['field_origin']) || !isset($form['field_origin_notes'])) {
      return;
    }
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
      // Submit or API.
      elseif ($origin === '1' || $origin === '3') {
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
    if (isset($form['field_ocha_product'])) {
      $widget = &$form['field_ocha_product']['widget'];

      // Remove "Press Review" (12351) OCHA product option if not selected as
      // it's not to be used anymore but we still want to preserve the docs
      // already tagged with it. (https://trello.com/c/kTiP7N2E)
      if (empty($widget['#default_value']) || $widget['#default_value'] == 12351) {
        FormHelper::removeOptions($form, 'field_ocha_product', [12351]);
      }

      // Remove the empty option.
      unset($widget['#options']['_none']);

      // Add a validation callback to ensure that an OCHA product is selected
      // when OCHA is selected as source.
      $form['#validate'][] = [$this, 'validateOchaProductField'];
    }
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
    if (!isset($form['field_ocha_product'])) {
      return;
    }
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
    if (!isset($form['field_file'])) {
      return;
    }
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
    if (!isset($form['field_embargo_date'])) {
      return;
    }
    $embargo_date = $form_state->getValue(['field_embargo_date', 0, 'value']);
    // If there is a valid date (with year, month etc.), then $embargo_date
    // is a \Drupal\Core\Datetime\DrupalDateTime instance...
    if (!empty($embargo_date) && $embargo_date instanceof DrupalDateTime && $embargo_date->getTimestamp() < time()) {
      $form_state->setErrorByName('field_embargo_date][0][value', $this->t('The embargo date cannot be in the past.'));
    }
  }

  /**
   * Check for duplicate files and display warning message.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function checkForDuplicateFiles(array &$form, FormStateInterface $form_state) {
    // Check if the user has permission to see duplicate file warnings.
    if (!$this->currentUser->hasPermission('check for duplicate files')) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();

    // Skip if the form is not a GET request, for example when the form
    // is being submitted or is an AJAX request.
    if ($request->getMethod() !== 'GET' || $request->isXmlHttpRequest()) {
      return;
    }

    $entity = $form_state->getFormObject()?->getEntity();
    if (!$entity instanceof Report) {
      return;
    }

    // Only check for existing reports (not new ones).
    if ($entity->isNew()) {
      return;
    }

    // Check if the report has files.
    if (!$entity->hasField('field_file') || $entity->field_file->isEmpty()) {
      return;
    }

    $all_duplicates = [];
    $bundle = $entity->bundle();
    $entity_id = $entity->id();

    // Retrieve the field_file widget.
    $widget = $form_state
      ->getFormObject()
      ?->getFormDisplay($form_state)
      ?->getRenderer('field_file');
    if (empty($widget) || !($widget instanceof ReliefWebFileWidget)) {
      return;
    }

    // Process all files attached to the report.
    foreach ($entity->field_file as $field_item) {
      // Skip if this is not a ReliefWeb file field item.
      if (!$field_item instanceof ReliefWebFileItem) {
        continue;
      }

      // Extract text from the file.
      $extracted_text = $field_item->extractText();
      if (empty($extracted_text)) {
        continue;
      }

      $duplicates = $this->fileDuplication->findSimilarDocuments(
        $extracted_text,
        $bundle,
        !empty($entity_id) ? [$entity_id] : [],
        $widget->getDuplicateMaxDocumentsSetting(),
        $widget->getDuplicateMinimumShouldMatchSetting(),
        $widget->getDuplicateMaxFilesSetting(),
        $widget->getDuplicateSkipAccessCheckSetting(),
      );

      if (!empty($duplicates)) {
        $all_duplicates = array_merge($all_duplicates, $duplicates);
      }
    }

    // Skip if no duplicates are found.
    if (empty($all_duplicates)) {
      return;
    }

    // Ensure the uniqueness of the duplicates based on document ID, keeping
    // the highest similarity.
    $unique_duplicates = [];
    foreach ($all_duplicates as $duplicate) {
      if (empty($duplicate['id'])) {
        continue;
      }
      $id = $duplicate['id'];
      if (!isset($unique_duplicates[$id]) || $duplicate['similarity'] > $unique_duplicates[$id]['similarity']) {
        $unique_duplicates[$id] = $duplicate;
      }
    }

    // Sort by similarity score (highest first).
    usort($unique_duplicates, function ($a, $b) {
      return $b['similarity'] <=> $a['similarity'];
    });

    // Limit to top duplicates.
    $unique_duplicates = array_slice($unique_duplicates, 0, $widget->getDuplicateMaxDocumentsSetting());

    // Display duplicate warning message if duplicates are found.
    $warning_message = $widget->buildDuplicateMessage($unique_duplicates);
    $rendered_message = $this->renderer->render($warning_message);
    $this->messenger->addWarning($rendered_message);
  }

}
