<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_utility\Helpers\TaxonomyHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Base service to alter entity forms.
 */
abstract class EntityFormAlterServiceBase implements EntityFormAlterServiceInterface {

  use DependencySerializationTrait;
  use EntityDatabaseInfoTrait;
  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(
    Connection $database,
    AccountProxyInterface $current_user,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state,
    TranslationInterface $string_translation
  ) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  abstract protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state);

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state) {
    // Mark the form for enhancement by the reliefweb_form module.
    $form['#attributes']['data-enhanced'] = '';

    // Get what entity form is being used.
    $operation = $form_state->getFormObject()?->getOperation() ?? 'default';

    // Only apply the form alterations to allowed forms.
    if (!in_array($operation, $this->getAllowedForms())) {
      return;
    }

    // Add the form alterations specific to the bundle.
    $this->addBundleFormAlterations($form, $form_state);

    // Add the guidelines.
    $this->addGuidelineFormAlterations($form, $form_state);

    // Add the moderation form alterations to handle the moderation status.
    // This needs to be added last so that the buttons to save the entity
    // can run all the submit callbacks added by the other form alterations.
    $this->addModerationFormAlterations($form, $form_state);

    // Add the revision form alterations.
    $this->addRevisionFormAlterations($form, $form_state);

    // Force separate display of the URL alias fields.
    if (isset($form['path']['widget'][0])) {
      unset($form['path']['widget'][0]['#group']);
      $form['path']['#type'] = 'fieldset';
      $form['path']['#title'] = $this->t('URL alias');
      $form['path']['widget'][0]['#type'] = 'container';
    }
  }

  /**
   * Get the list of forms that can be altered.
   *
   * @return array
   *   List of form operations.
   */
  protected function getAllowedForms() {
    return ['default', 'edit'];
  }

  /**
   * Add the guidelines form alterations.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function addGuidelineFormAlterations(array &$form, FormStateInterface $form_state) {
    $form['#attributes']['data-with-guidelines'] = '';
  }

  /**
   * Add the moderation form alterations.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function addModerationFormAlterations(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $moderation_service = ModerationServiceBase::getModerationService($entity->bundle());
    if (!empty($moderation_service)) {
      $moderation_service->alterEntityForm($form, $form_state);
    }
  }

  /**
   * Add the revision form alterations.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @todo move the revision service once ported.
   */
  protected function addRevisionFormAlterations(array &$form, FormStateInterface $form_state) {
    $entity_type_id = $form_state->getFormObject()->getEntity()->getEntityTypeId();
    $revision_field = $this->getEntityTypeRevisionLogMessageField($entity_type_id);

    if (!isset($revision_field)) {
      return;
    }

    // Container for the revision log and history.
    unset($form['revision_information']['#group']);
    $form['revision_information']['#type'] = 'fieldset';
    $form['revision_information']['#title'] = $this->t('Revisions');

    // Hide the revision checkbox if defined to force new revisions.
    if (isset($form['revision'])) {
      $form['revision']['#access'] = FALSE;
    }

    // Update the title of the revision log message field.
    if (isset($form[$revision_field]['widget'][0]['value'])) {
      $form[$revision_field]['widget'][0]['value']['#title'] = $this->t('New comment');
      $form[$revision_field]['#group'] = 'revision_information';
    }
  }

  /**
   * Alter a primary field field to add empty value and validation.
   *
   * @param string $field
   *   Field name.
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterPrimaryField($field, array &$form, FormStateInterface $form_state) {
    $widget = &$form[$field]['widget'];
    $options = $widget['#options'];

    // Ensure there is an empty value option available so that we can
    // remove the selected value when modifying the country field.
    if (!isset($options['_none'])) {
      $options = ['_none' => $this->t('- Select a value -')] + $options;
      $widget['#options'] = $options;
    }

    // Add a validation callback to check that the selected value is one of
    // the selected values of the corresponding non primary field (ex: the
    // primary country should match one of the selected countries).
    $widget['#element_validate'][] = [$this, 'validatePrimaryField'];
  }

  /**
   * Validate a primary field.
   *
   * Ensuring the selected value in the primary field is among the selected
   * values of the non primary field.
   *
   * @param array $element
   *   Form element to validate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $form
   *   The complete form.
   */
  public function validatePrimaryField(array &$element, FormStateInterface $form_state, array &$form) {
    $field = $element['#field_name'];
    $non_primary_field = str_replace('_primary', '', $field);
    $key_column = $element['#key_column'];
    $primary_value = $form_state->getValue([$field, 0, $key_column]);

    $found = FALSE;
    if (!empty($primary_value)) {
      foreach ($form_state->getValue($non_primary_field) as $value) {
        // Depending on the order in which the fields are processed the values
        // for entity reference fields can either be scalars with the target id
        // or arrays with the `target_id` property so we need to check.
        if (is_array($value) && isset($value['target_id'])) {
          $non_primary_value = $value['target_id'];
        }
        elseif (is_scalar($value)) {
          $non_primary_value = $value;
        }
        else {
          continue;
        }

        if ($non_primary_value === $primary_value) {
          $found = TRUE;
          break;
        }
      }
    }

    if (!$found) {
      $form_state->setError($element, $this->t('The %primary_field value must be one of the selected %field values', [
        '%primary_field' => $element['#title'],
        '%field' => $form[$non_primary_field]['widget']['#title'],
      ]));
    }
  }

  /**
   * Add the fields to add a potential new source.
   *
   * @param array $form
   *   The form to which add the new source fields.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function addPotentialNewSourceFields(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $bundle = $entity->bundle();

    // The following only applies to job and training nodes.
    if (!in_array($bundle, ['job', 'training'])) {
      return;
    }

    // Main source field element.
    $element = &$form['field_source'];

    // Jobs only accept 1 source.
    $multiple = !empty($element['widget']['#multiple']) && $bundle !== 'job';

    // The job source field only accepts one value. The field storage being
    // used across several content type we need to enforce the cardinality here.
    $element['widget']['#multiple'] = $multiple;

    // Hide the original source field title.
    $element['widget']['#title_display'] = 'invisible';

    // Retrieve the new source information from the previous revision log.
    $new_source = static::retrievePotentialNewSourceInformation($entity);

    // Check if the current user as editorial rights.
    // @todo review permission?
    $is_editor = $this->currentUser
      ->hasPermission('access content moderation features');

    // For editors, we keep the checkbox ticked if there is new source
    // information so they can review it while for normal users we don't to
    // force them to go through the source selection again.
    $element['field_source_none'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I can NOT find my organization in the list above.'),
      '#wrapper_attributes' => ['class' => ['form-wrapper']],
      '#optional' => FALSE,
      '#default_value' => !empty($new_source['name']) && $is_editor ? 1 : 0,
    ];
    $element['field_source_new'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('New organization'),
      '#attributes' => ['class' => ['field-new-source-wrapper']],
      'name' => [
        '#type' => 'textfield',
        '#title' => $this->t('Organization Name'),
        '#default_value' => $new_source['name'] ?? '',
        '#optional' => FALSE,
      ],
      'url' => [
        '#type' => 'textfield',
        '#title' => $this->t('Homepage URL'),
        '#default_value' => $new_source['url'] ?? '',
        '#optional' => FALSE,
      ],
      '#states' => [
        'visible' => [
          ':input[name="field_source_none"]' => ['checked' => TRUE],
        ],
      ],
      '#tree' => TRUE,
    ];

    // @todo this not great notably because it's not translatable.
    $new_source_help = $this->state->get('reliefweb_form_new_source_help_' . $bundle, '');
    if (!empty($new_source_help)) {
      $element['field_source_new']['#description'] = Markup::create($new_source_help);
    }

    // Create a checkbox field with the sources the user is allowed to post for
    // for faster access and to reduce wrong source selection issues.
    $bundle = $entity->bundle();
    $account = $entity instanceof EntityOwnerInterface ? $entity->getOwner() : NULL;
    if (isset($account)) {
      $rights = UserPostingRightsHelper::getUserPostingRights($account);

      if (!empty($rights)) {
        $allowed = [];
        $options = $element['widget']['#options'];

        $allowed_defaults = [];
        $options_defaults = $element['widget']['#default_value'] ?? [];
        $options_defaults = array_combine($options_defaults, $options_defaults);

        // Move sources the users is allowed to post for to the allowed list.
        foreach ($options as $tid => $name) {
          // Extract from the options if the user is allowed to post.
          if (isset($rights[$tid][$bundle]) && $rights[$tid][$bundle] > 1) {
            $allowed[$tid] = $name;
            unset($options[$tid]);

            // Extract from the default values as well.
            if (isset($options_defaults[$tid])) {
              $allowed_defaults[] = $tid;
              unset($options_defaults[$tid]);
            }
          }
        }

        // Add the allowed source field.
        if (!empty($allowed)) {
          // If the field only accept 1 value, then make sure only 1 is
          // selected.
          if (!$multiple) {
            // Keep the first 'other' source.
            if (empty($allowed_defaults)) {
              $options_defaults = array_slice($options_defaults, 0, 1);
              $allowed_defaults = 'other';
            }
            // Else keep the first 'allowed' source.
            else {
              $options_defaults = [];
              $allowed_defaults = $allowed_defaults[0];
            }
          }
          // Otherwise make sure 'other' is selected if there are 'other'
          // sources.
          elseif (!empty($options_defaults)) {
            $options_defaults = array_values($options_defaults);
            $allowed_defaults[] = 'other';
          }

          // Update the source field.
          $element['widget']['#options'] = $options;
          $element['widget']['#default_value'] = $options_defaults;

          // Add "other" to the list of sources to toggle the display of the
          // other source fields.
          $allowed['other'] = $multiple ? $this->t('Other organization(s)') : $this->t('Other organization');

          // Create the field.
          $element['field_source_allowed'] = [
            '#type' => $multiple ? 'checkboxes' : 'radios',
            '#title' => $this->t('Your organizations'),
            '#options' => $allowed,
            '#default_value' => $allowed_defaults,
            '#empty_value' => 'other',
            '#optional' => FALSE,
            '#weight' => -1,
          ];

          // Show the other source fields only if 'other' is selected.
          if ($multiple) {
            $condition = [
              ':input[name="field_source_allowed[other]"]' => ['checked' => TRUE],
            ];
          }
          else {
            $condition = [
              ':input[name="field_source_allowed"]' => ['value' => 'other'],
            ];
          }
          $element['widget']['#states']['visible'] = $condition;
          $element['field_source_none']['#states']['visible'] = $condition;

          // For the new source field, we need to combine the condition
          // on the no source field and the selection of 'other'.
          $element['field_source_new']['#states']['visible'] += $condition;
        }
      }
    }

    // Add a reminder to fill in the potential new source.
    $new_source_reminder = $this->state->get('reliefweb_form_new_source_reminder_' . $bundle, '');
    if (!empty($new_source_reminder)) {
      $form['field_source_reminder'] = [
        '#title' => Markup::create($new_source_reminder),
        '#type' => 'checkbox',
        '#states' => [
          'visible' => [
            ':input[name="field_source_none"]' => ['checked' => TRUE],
          ],
          'required' => [
            ':input[name="field_source_none"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // Validate the source fields.
    $form['#validate'][] = [$this, 'validatePotentialNewSourceFields'];

    // Add a submit handler to update the entity revision log message with the
    // new source information.
    $form['#submit'][] = [$this, 'handlePotentialNewSourceSubmission'];
  }

  /**
   * Validate the source fields.
   *
   * @param array $form
   *   The field form widget.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validatePotentialNewSourceFields(array $form, FormStateInterface $form_state) {
    $multiple = !empty($form['field_source']['#multiple']);

    // We need to populate the values for the source field here to be able
    // to validate it and pass the correct data to the later functions like
    // submit, presave etc.
    //
    // Combine the sources selected fron the allowed sources field with
    // the normal source field.
    $allowed = $form_state->getValue('field_source_allowed', []);
    if (!empty($allowed)) {
      // Convert to array if necessary.
      $allowed = is_array($allowed) ? $allowed : [$allowed];
      // Extract valid and selected sources (exclude 'other' and value = 0).
      $allowed = array_values(array_filter($allowed, function ($source) {
        return $source != 0 && is_numeric($source);
      }));
      if (!empty($allowed)) {
        // If multiple values are permitted, merge the allowed and normal
        // sources.
        if ($multiple) {
          // Get the sources from the normal source field.
          $sources = array_filter(array_map(function ($source) {
            return $source['target_id'];
          }, $form_state->getValue('field_source', [])));
          // Combine the sources.
          $sources = array_unique(array_merge($allowed, $sources));
        }
        // Otherwise only keep the first allowed source.
        else {
          $sources = array_slice($allowed, 0, 1);
        }
        // Update the source field.
        $form_state->setValue('field_source', array_map(function ($source) {
          return ['target_id' => $source];
        }, $sources));
      }
    }

    $message = $this->t('Organization field is required. If your organization is NOT in the list, please select "I can NOT find my organization in the list above" and provide the name and URL. If you see your organization in the list, please select it while making sure the box "I can NOT find my organization..." is unchecked.');

    // Error if there is no source and "no source found" is not checked.
    if ($form_state->isValueEmpty('field_source_none')) {
      if ($form_state->isValueEmpty('field_source')) {
        $form_state->setErrorByName('field_source', $message);
      }
    }
    // Error if "no source found" is selected but there is a source selected.
    // Only applies to entities that can have only 1 source.
    elseif (!$multiple && !$form_state->isValueEmpty('field_source')) {
      $form_state->setErrorByName('field_source', $message);
    }
    // Error if "no source found" is selected but no source was entered.
    elseif ($form_state->isValueEmpty(['field_source_new', 'name'])) {
      $form_state->setErrorByName('field_source_new][name', $message);
      $form_state->setErrorByName('field_source_new][url', '');
    }

    // Ensure the new source URL if defined is a valid external URL.
    if (!$form_state->isValueEmpty(['field_source_new', 'url'])) {
      $url = $form_state->getValue(['field_source_new', 'url']);
      if (!UrlHelper::isValid($url, TRUE)) {
        $form_state->setErrorByName('field_source_new][url', $this->t('The organization URL must a valid web address starting with https:// or http://'));
      }
    }

    // Ensure the reminder is checked.
    if (!$form_state->isValueEmpty('field_source_none') && isset($form['field_source_reminder']) && $form_state->isValueEmpty('field_source_reminder')) {
      $form_state->setErrorByName('field_source_reminder', $this->t('Please check the reminder regarding the information to send about your organization.'));
    }
  }

  /**
   * Update the revision log message with the potential new source info.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function handlePotentialNewSourceSubmission(array $form, FormStateInterface $form_state) {
    $entity_type = $form_state
      ?->getFormObject()
      ?->getEntity()
      ?->getEntityType();

    if (!empty($entity_type) && $entity_type instanceof ContentEntityTypeInterface) {
      $revision_log_field = $entity_type->getRevisionMetadataKey('revision_log_message');

      $log = $form_state->getValue([$revision_log_field, 0, 'value'], '');
      $status = $form_state->getValue(['moderation_state', 0, 'value']);

      // Add the information about potential new source and update the status.
      if (!$form_state->isValueEmpty('field_source_none') &&
          !$form_state->isValueEmpty(['field_source_new', 'name'])) {

        $source = '**' . $form_state->getValue(['field_source_new', 'name']) . '**';
        if (!$form_state->isValueEmpty(['field_source_new', 'url'])) {
          $source .= ' (' . $form_state->getValue(['field_source_new', 'url']) . ')';
        }
        $log = 'Potential new source: ' . $source . '. ' . $log;
        $form_state->setValue([$revision_log_field, 0, 'value'], $log);

        // If the status is "published" or "pending", change as appropriate.
        if ($status === 'published' || $status === 'pending') {
          $status = $this->state->get('reliefweb_no_source_status', 'pending');
          $form_state->setValue(['moderation_state', 0, 'value'], $status);
        }
      }
    }
  }

  /**
   * Get the potential new source information from the entity's last revision.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   The entity this field is attached to.
   *
   * @return array
   *   If the information was found, then return an array containing the source
   *   name and URL.
   */
  public static function retrievePotentialNewSourceInformation(EntityModeratedInterface $entity) {
    $pattern = '/Potential new source: \*\*(?<name>[^*]+)\*\*( \((?<url>[^)]+)\).)?/';
    $log = $entity->getOriginalRevisionLogMessage();
    if (!empty($log) && preg_match($pattern, $log, $matches) === 1) {
      return [
        'name' => $matches['name'],
        'url' => $matches['url'] ?? '',
      ];
    }
    return [];
  }

  /**
   * Add the user information to a job/training node form.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function addUserInformation(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $entity_id = $entity->id();
    $bundle = $entity->bundle();

    // It's only for jobs and training.
    if (!in_array($bundle, ['job', 'training'])) {
      return;
    }

    // Only when editing a node and for editors only.
    // @todo replace with permission.
    if (empty($entity_id) || !UserHelper::userHasRoles(['editor'])) {
      return;
    }

    // Thank you Drupal...
    $bundle_label = $this->getEntityTypeManager()
      ->getStorage($entity->getEntityType()->getBundleEntityType())
      ->load($entity->bundle())
      ->label();

    $posting_rights = [
      ['type' => 'unverified', 'label' => $this->t('Unverified')],
      ['type' => 'blocked', 'label' => $this->t('Blocked')],
      ['type' => 'allowed', 'label' => $this->t('Allowed')],
      ['type' => 'trusted', 'label' => $this->t('Trusted')],
    ];

    $build = [
      '#theme' => 'reliefweb_entities_form_user_information',
      '#entity' => [
        'type' => $bundle_label,
        'id' => $entity->id(),
        'url' => $entity->toUrl()->toString(),
        'date' => $entity->getCreatedTime(),
      ],
    ];

    // User information.
    $author = $entity->getOwner();
    if (!empty($author) && !$author->isAnonymous()) {
      $build['#author'] = [
        'name' => $author->getDisplayName(),
        'mail' => $author->getEmail(),
        'url' => $author->toUrl()->toString(),
      ];

      // Get the list of source and the author posting rights for them.
      $sources = [];
      foreach ($entity->get('field_source') as $item) {
        if (!empty($item->target_id)) {
          $sources[] = $item->target_id;
        }
      }

      $rights = UserPostingRightsHelper::getUserPostingRights($author, $sources);
      foreach (TaxonomyHelper::getSourceShortnames(array_keys($rights)) as $tid => $name) {
        $build['#sources'][$tid] = [
          'url' => Url::fromRoute('entity.taxonomy_term.canonical', [
            'taxonomy_term' => $tid,
          ])->toString(),
          'name' => $name,
          'right' => $posting_rights[$rights[$tid][$bundle]],
        ];
      }
    }

    // Check if a potential new source was selected.
    $new_source = static::retrievePotentialNewSourceInformation($entity);
    if (!empty($new_source)) {
      $build['#new_source'] = [
        'name' => $new_source['name'],
        'url' => $new_source['url'],
        'right' => $posting_rights[0],
      ];
    }

    $form['user_information'] = $build;
  }

  /**
   * Add a terms and conditions checkbox to a form.
   *
   * The checkbox must be checked when saving the document for the first time
   * and cannot be unchecked afterwards.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function addTermsAndConditions(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $entity_id = $entity->id();

    // Link to the terms and conditions page for the content type.
    $url = Url::fromUserInput('/' . $entity->bundle() . '-terms', [
      'attributes' => [
        'target' => '_blank',
        'rel' => 'noopener',
      ],
    ]);
    $link = Link::fromTextAndUrl($this->t('Terms & Conditions'), $url)->toString();

    // Add terms and conditions checkbox. Must be checked when submitting
    // initially and cannot be unchecked when editing.
    $form['legal'] = [
      '#title' => $this->t('I have read the @terms', [
        '@terms' => $link,
      ]),
      '#type' => 'checkbox',
      '#required' => TRUE,
      '#default_value' => !empty($entity_id),
      '#disabled' => !empty($entity_id),
    ];
  }

  /**
   * Add a selection limit to a checkboxes form element.
   *
   * @param array $form
   *   Form to alter.
   * @param string $field
   *   Name of the field to alter.
   * @param int $limit
   *   Maximum number of selectable options.
   */
  protected function addSelectionLimit(array &$form, $field, $limit) {
    // We only deal with checkboxes elements and a limit > 0.
    if (empty($limit) || !isset($form[$field]['widget']['#type']) || $form[$field]['widget']['#type'] !== 'checkboxes') {
      return;
    }

    $element = &$form[$field]['widget'];

    // If we accept only 1 element then we simply change the type to 'radios'.
    if ($limit === 1) {
      $element['#type'] = 'radios';
      if (isset($element['#default_value'])) {
        if (is_array($element['#default_value'])) {
          $element['#default_value'] = reset($element['#default_value']);
        }
        // A FALSE default_value is expected for radios when no value is
        // selected.
        // @see \Drupal\Core\Render\Element\Radios
        if (empty($element['#default_value'])) {
          $element['#default_value'] = FALSE;
        }
      }
    }
    // Else we add parameters that will be used to handle the selection limit
    // client side via the reliefweb_form.main.js script.
    // @see reliefweb_form/widget.selectionlimit library.
    else {
      $title_suffix = $this->t('(up to @limit)', ['@limit' => $limit]);
      if (isset($element['#title'])) {
        $element['#title'] .= ' ' . $title_suffix;
      }
      $element['#attributes']['data-with-selection-limit'] = $limit;
    }

    // Add validation.
    $element['#element_validate'][] = [$this, 'validateSelectionLimit'];
  }

  /**
   * Validate a field with a selection limit.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateSelectionLimit(array $element, FormStateInterface $form_state) {
    if (isset($element['#attributes']['data-with-selection-limit'])) {
      $values = $form_state->getValue($element['#parents'], []);
      $limit = $element['#attributes']['data-with-selection-limit'];
      if (count($values) > $limit) {
        $form_state->setError($element, $this->t('Only up to @limit values can be selected.', [
          '@limit' => $limit,
        ]));
      }
    }
  }

  /**
   * Check if the form action is to show the preview.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return bool
   *   True if the preview button was clicked.
   */
  protected function isPreviewRequested(FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    return isset($triggering_element['#parents']) && end($triggering_element['#parents']) === 'preview';
  }

  /**
   * Get the entity moderation status from the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return string
   *   Moderation status.
   */
  protected function getEntityModerationStatus(FormStateInterface $form_state) {
    return $form_state->getValue(['moderation_state', 0, 'value']);
  }

  /**
   * Update the entity moderation status in the form state.
   *
   * @param string $status
   *   Moderation status.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return string
   *   Moderation status.
   */
  protected function setEntityModerationStatus($status, FormStateInterface $form_state) {
    return $form_state->setValue(['moderation_state', 0, 'value'], $status);
  }

  /**
   * Redirect to the entity page.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function redirectToEntityPage(array $form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()?->getEntity();
    if (!empty($entity) && empty($entity->in_preview) && $entity->id() !== NULL) {
      $form_state->setRedirectUrl($entity->toUrl());
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getFormAlterService($bundle) {
    try {
      return \Drupal::service('reliefweb_entities.' . $bundle . '.form_alter');
    }
    catch (ServiceNotFoundException $exception) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function alterEntityForm(array &$form, FormStateInterface $form_state) {
    $form_object = $form_state->getFormObject();
    if (isset($form_object) && $form_object instanceof ContentEntityForm) {
      $service = static::getFormAlterService($form_object->getEntity()->bundle());
      if (!empty($service)) {
        $service->alterForm($form, $form_state);
      }
    }
  }

}
