<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Base for the moderation services.
 */
abstract class ModerationServiceBase implements ModerationServiceInterface {

  use DependencySerializationTrait;
  use EntityDatabaseInfoTrait;
  use StringTranslationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

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
   * The pager manager servie.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * The pager parameters service.
   *
   * @var \Drupal\Core\Pager\PagerParametersInterface
   */
  protected $pagerParameters;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Filters definition for the filter block on the moderation page.
   *
   * @var array
   */
  protected $filterDefinitions;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager service.
   * @param \Drupal\Core\Pager\PagerParametersInterface $pager_parameters
   *   The pager parameter service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    Connection $database,
    DateFormatterInterface $date_formatter,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    PagerManagerInterface $pager_manager,
    PagerParametersInterface $pager_parameters,
    RequestStack $request_stack,
    TranslationInterface $string_translation
  ) {
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->pagerManager = $pager_manager;
    $this->pagerParameters = $pager_parameters;
    $this->request = $request_stack->getCurrentRequest();
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getBundle();

  /**
   * {@inheritdoc}
   */
  abstract public function getEntityTypeId();

  /**
   * {@inheritdoc}
   */
  abstract public function getTitle();

  /**
   * {@inheritdoc}
   */
  abstract public function getHeaders();

  /**
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'draft' => $this->t('Draft'),
      'published' => $this->t('Published'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterStatuses() {
    return $this->getStatuses();
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefaultStatuses() {
    return array_keys($this->getFilterStatuses());
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    return [
      'draft' => [
        '#value' => $this->t('Save as draft'),
      ],
      'published' => [
        '#value' => $this->t('Publish'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isViewableStatus($status, ?AccountInterface $account = NULL) {
    return $status === 'published';
  }

  /**
   * {@inheritdoc}
   */
  public function isEditableStatus($status, ?AccountInterface $account = NULL) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasStatus($status) {
    $statuses = $this->getStatuses();
    return isset($statuses[$status]);
  }

  /**
   * {@inheritdoc}
   */
  public function disableNotifications(EntityModeratedInterface $entity, $status) {
    $entity->notifications_content_disable = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function entityPresave(EntityModeratedInterface $entity) {
    $status = $entity->getModerationStatus();

    // If notifications are not already disabled, check if they need to be.
    if (empty($entity->in_preview) && empty($entity->notifications_content_disable)) {
      // Check if notifications should be disabled based on status.
      $this->disableNotifications($entity, $status);

      // Disable notifications for buried entities.
      if ($entity->hasField('field_bury') && !$entity->field_bury->isEmpty()) {
        $entity->notifications_content_disable = TRUE;
      }
    }

    // Mark as published if the status is viewable by everybody.
    if ($entity instanceof EntityPublishedInterface) {
      $entity->setPublished($this->isViewableStatus($status));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function entityAccess(EntityModeratedInterface $entity, $operation = 'view', ?AccountInterface $account = NULL) {
    $account = $account ?: $this->currentUser;

    $access = FALSE;

    $bundle = $entity->bundle();

    $entity_type_id = $entity->getEntityTypeId();

    $status = $entity->getModerationStatus();

    $viewable = $this->isViewableStatus($status, $account);

    $editable = $this->isEditableStatus($status, $account);

    $owner = FALSE;
    if ($entity instanceof EntityOwnerInterface) {
      $owner = $entity->getOwnerId() === $account->id() && $account->id() > 0;
    }

    switch ($entity_type_id) {
      case 'node':
        // Access to everything for those permissions.
        $access = $account->hasPermission('bypass node access') || $account->hasPermission('administer nodes');

        if (!$access) {
          switch ($operation) {
            case 'view':
              if ($account->hasPermission('access content')) {
                $access = $viewable || $account->hasPermission('view any content');

                // Check if the user is the owner or has posting rights.
                // Document owners are allowed to view their documents even if
                // they don't have the posting rights on it (due to being
                // blocked for one of the sources for example).
                if (!$access && $account->hasPermission('view own unpublished content')) {
                  $access = $owner || UserPostingRightsHelper::userHasPostingRights($account, $entity, $status);
                }
              }
              break;

            case 'create':
              $access = $account->hasPermission('create any content') ||
                        $account->hasPermission('create ' . $bundle . ' content');
              break;

            case 'update':
              if ($account->hasPermission('edit any ' . $bundle . ' content')) {
                $access = TRUE;
              }
              elseif ($editable && $account->hasPermission('edit own ' . $bundle . ' content')) {
                $access = UserPostingRightsHelper::userHasPostingRights($account, $entity, $status);
              }
              break;

            case 'delete':
              if ($account->hasPermission('delete any ' . $bundle . ' content')) {
                $access = TRUE;
              }
              elseif ($account->hasPermission('delete own ' . $bundle . ' content')) {
                $access = UserPostingRightsHelper::userHasPostingRights($account, $entity, $status);
              }
              break;
          }
        }

        break;

      case 'taxonomy_term':
        // Access to everything for those permissions.
        $access = $account->hasPermission('administer taxonomy');

        if (!$access) {
          switch ($operation) {
            case 'view':
              if ($account->hasPermission('access content')) {
                $access = $viewable || $account->hasPermission('view any content');
              }
              break;

            case 'create':
              $access = $account->hasPermission('edit terms in ' . $bundle);
              break;

            case 'update':
              $access = $account->hasPermission('edit terms in ' . $bundle) && $editable;
              break;

            case 'delete':
              $access = $account->hasPermission('delete terms in ' . $bundle);
              break;
          }
        }

        break;
    }

    return $access ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function alterEntityForm(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $status = $entity->getModerationStatus();

    // Disable the moderation status widget and the default submit buttons.
    $form['moderation_state']['#access'] = FALSE;
    $form['actions']['submit']['#access'] = FALSE;

    // Move the preview button at the beginning if it exists.
    if (isset($form['actions']['preview'])) {
      $form['actions']['preview']['#weight'] = -1;
    }

    // Ensure we call all the submit handlers.
    $submit_handlers = [];
    if (!empty($form['#submit'])) {
      $submit_handlers = array_merge($submit_handlers, $form['#submit']);
    }
    if (!empty($form['actions']['submit']['#submit'])) {
      $submit_handlers = array_merge($submit_handlers, $form['actions']['submit']['#submit']);
    }

    // Add submit handler at the end to finalize the selection of the status
    // based on the rest of the submitted data.
    $submit_handlers[] = [$this, 'handleEntitySubmission'];

    // Add the buttons.
    foreach ($this->getEntityFormSubmitButtons($status, $entity) as $status => $info) {
      $form['actions'][$status] = array_merge_recursive([
        '#type' => 'submit',
        '#name' => $status,
        '#submit' => $submit_handlers,
        '#entity_status' => $status,
        // Add validation callback to update the moderation status based on the
        // clicked status button. This needs to be added as element_validate
        // so that it runs before any other validation which may rely on the
        // entity status.
        '#element_validate' => [[$this, 'validateEntityStatus']],
      ], $info);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateEntityStatus(array $element, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    if (isset($triggering_element['#entity_status']) && $triggering_element['#entity_status'] === $element['#entity_status']) {
      $form_state->setValue(['moderation_state', 0, 'value'], $element['#entity_status']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function handleEntitySubmission(array $form, FormStateInterface $form_state) {
    // Alter the status based on the rest of the submitted form.
    // @todo review if that should not be done in the entity presave instead.
    $status = $form_state->getValue(['moderation_state', 0, 'value']);
    $status = $this->alterSubmittedEntityStatus($status, $form_state);
    $form_state->setValue(['moderation_state', 0, 'value'], $status);
  }

  /**
   * {@inheritdoc}
   */
  public function alterSubmittedEntityStatus($status, FormStateInterface $form_state) {
    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function getTable(array $filters, $limit = 30) {
    // Execute the query.
    $results = $this->executeQuery($filters, $limit);

    // Get the headers with the one currently used for sorting flagged.
    $headers = $this->getOrderInformation()['headers'];

    // Compute the sort URL for the sortable headers.
    $query = $this->request->query->all();
    $remove = ['form_build_id', 'form_id', 'submit', 'page'];
    $query = array_diff_key($query, array_flip($remove));
    foreach ($headers as $header => $info) {
      if (isset($info['sortable'])) {
        $headers[$header]['url'] = Url::fromRoute('<current>', [
          'order' => $header,
          'sort' => ($info['sort'] ?? 'desc') === 'desc' ? 'asc' : 'desc',
        ] + $query);
      }
    }

    return [
      '#theme' => 'reliefweb_moderation_table',
      '#totals' => $this->getTotals($results),
      '#headers' => $headers,
      '#rows' => $this->getRows($results),
      '#empty' => $this->t('No results'),
      // @todo check if there are some parameters like `op` that should be
      // removed, in that case use '#theme' => 'pager'.
      '#pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefinitions() {
    if (!isset($this->definitions)) {
      $this->definitions = $this->initFilterDefinitions();
    }
    return $this->definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefinition($name) {
    $filters = $this->getFilterDefinitions();
    return isset($filters[$name]) ? $filters[$name] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFilterDefinition($name) {
    $filters = $this->getFilterDefinitions();
    return isset($filters[$name]);
  }

  /**
   * {@inheritdoc}
   */
  public function getAutocompleteSuggestions($filter) {
    $query = $this->request->query->get('query', '');

    if (empty($query) || !$this->hasFilterDefinition($filter)) {
      return [];
    }

    $filter_definition = $this->getFilterDefinition($filter);
    $method = $filter_definition['autocomplete_callback'] ?? '';
    $query = urldecode($query);

    // Allow to find entities "without" a value.
    if (!empty($filter_definition['allow_no_value']) && $query === '-') {
      $label = !empty($filter_definition['label']) ? $filter_definition['label'] : $filter;
      return [
        [
          'value' => 'NULL',
          'label' => $this->t('Without @label', ['@label' => $label]),
        ],
      ];
    }

    // @todo review the prefixes, not used currently.
    $prefix = substr($query, 0, 1);
    if ($prefix === '-' || $prefix === '+') {
      $query = substr($query, 1);
      if ($prefix === '+') {
        $prefix = '';
      }
    }
    else {
      $prefix = '';
    }

    // Suggestion records (objects with a label and value at least).
    $records = [];
    if (strlen($query) > 0) {
      $terms = [];

      // Search matching filter's static values if defined.
      if (!empty($filter_definition['values'])) {
        $values = $filter_definition['values'];

        foreach (explode('&', $query) as $index => $term) {
          $starting = FALSE;
          if (strpos($term, '!') === 0) {
            $term = substr($term, 1);
            $starting = TRUE;
          }
          foreach ($values as $key => $value) {
            $pos = stripos($value, $term);
            if ($pos !== FALSE && (!$starting || $pos === 0)) {
              // Compatibility with the DB query results.
              $record = new \stdClass();
              $record->value = $key;
              $record->label = $value;
              $records[] = $record;
            }
          }
        }
      }
      // Otherwise assume it's a DB search.
      elseif (!empty($method) && method_exists($this, $method)) {
        $conditions = [];
        $replacements = [];
        // Build the search conditions.
        foreach (explode('&', $query) as $index => $term) {
          $term_prefix = '%';
          // If the query starts with a "!" then it means a prefix search.
          if (strpos($term, '!') === 0) {
            $term = substr($term, 1);
            $term_prefix = '';
          }
          $terms[] = $term;
          // We don't use a \Drupal\Core\Database\Query\Condition because
          // we need to replace the `@field` later on.
          $conditions[] = '@field LIKE :term' . $index;
          $replacements[':term' . $index] = $term_prefix . $this->getDatabase()->escapeLike($term) . '%';
        }
        // Call the filter's autocomplete callback.
        if (!empty($conditions)) {
          $conditions = '(' . implode(' AND ', $conditions) . ')';
          $records = $this->{$method}($filter, $query, $conditions, $replacements);
        }
      }
    }

    // Parse and format the results.
    $results = [];
    foreach ($records as $record) {
      $result = [
        'value' => $prefix . ($record->value ?? $record->label),
        'label' => $record->label,
      ];
      if (!empty($record->abbr) && $record->abbr !== $record->label) {
        $result['label'] .= ' (' . $record->abbr . ')';
      }
      $results[] = $result;
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public static function getModerationService($bundle) {
    try {
      return \Drupal::service('reliefweb_moderation.' . $bundle . '.moderation');
    }
    catch (ServiceNotFoundException $exception) {
      return NULL;
    }
  }

  /**
   * Get the moderation table rows from the query results.
   *
   * @param array $results
   *   Results as returned by ::executeQuery() containing notably the
   *   entities.
   *
   * @return array
   *   List of rows with entity data prepared for display in the table.
   */
  abstract protected function getRows(array $results);

  /**
   * Set filter definitions for this service.
   *
   * @param array $filters
   *   List of filter names used by this moderation service. This is used to
   *   select which filter definitions to return.
   *
   * @return array
   *   Filter definitions for the service.
   *
   * @todo review if this should be static etc.
   */
  protected function initFilterDefinitions(array $filters = []) {
    if (!isset($this->filterDefinitions)) {
      $taxonomy_reference = [
        'type' => 'field',
        'column' => 'target_id',
        'operator' => 'AND',
        'form' => 'omnibox',
        'widget' => 'autocomplete',
        'autocomplete_callback' => 'getTaxonomyTermAutocompleteSuggestions',
        'allow_no_value' => TRUE,
      ];

      $this->filterDefinitions = [
        'status' => [
          'form' => 'status',
          'type' => 'field',
          'label' => $this->t('Status'),
          'field' => 'field_status',
          'column' => 'value',
          'operator' => 'OR',
        ],
        'primary_country' => array_merge($taxonomy_reference, [
          'field' => 'field_primary_country',
          'label' => $this->t('Primary Country'),
          'vocabulary' => 'country',
          'shortcut' => 'pc',
        ]),
        'country' => array_merge($taxonomy_reference, [
          'field' => 'field_country',
          'label' => $this->t('Country'),
          'vocabulary' => 'country',
          'shortcut' => 'c',
        ]),
        'source' => array_merge($taxonomy_reference, [
          'field' => 'field_source',
          'label' => $this->t('Source'),
          'vocabulary' => 'source',
          'shortcut' => 's',
        ]),
        'theme' => array_merge($taxonomy_reference, [
          'field' => 'field_theme',
          'label' => $this->t('Theme'),
          'vocabulary' => 'theme',
          'shortcut' => 'th',
        ]),
        'content_format' => array_merge($taxonomy_reference, [
          'field' => 'field_content_format',
          'label' => $this->t('Content Format'),
          'vocabulary' => 'content_format',
          'shortcut' => 'cf',
        ]),
        'disaster' => array_merge($taxonomy_reference, [
          'field' => 'field_disaster',
          'label' => $this->t('Disaster'),
          'vocabulary' => 'disaster',
          'shortcut' => 'd',
        ]),
        'disaster_type' => array_merge($taxonomy_reference, [
          'field' => 'field_disaster_type',
          'label' => $this->t('Disaster Type'),
          'vocabulary' => 'disaster_type',
          'shortcut' => 'dt',
        ]),
        // Disable vulnerable group field (#kUklB1e4).
        /*'vulnerable_groups' => array_merge($taxonomy_reference, [
          'field' => 'field_vulnerable_groups',
          'label' => $this->t('Vulnerable Groups'),
          'shortcut' => 'vg',
        ]),*/
        'language' => array_merge($taxonomy_reference, [
          'field' => 'field_language',
          'label' => $this->t('Language'),
          'vocabulary' => 'language',
          'shortcut' => 'l',
        ]),
        'career_categories' => array_merge($taxonomy_reference, [
          'field' => 'field_career_categories',
          'label' => $this->t('Career categories'),
          'vocabulary' => 'career_category',
          'shortcut' => 'cc',
        ]),
        'job_type' => array_merge($taxonomy_reference, [
          'field' => 'field_job_type',
          'label' => $this->t('Job type'),
          'vocabulary' => 'job_type',
          'shortcut' => 'jt',
        ]),
        'job_experience' => array_merge($taxonomy_reference, [
          'field' => 'field_job_experience',
          'label' => $this->t('Job experience'),
          'vocabulary' => 'job_experience',
          'shortcut' => 'je',
        ]),
        'training_type' => array_merge($taxonomy_reference, [
          'field' => 'field_training_type',
          'label' => $this->t('Training type'),
          'vocabulary' => 'training_type',
          'shortcut' => 'tt',
        ]),
        'organization_type' => array_merge($taxonomy_reference, [
          'field' => 'field_organization_type',
          'label' => $this->t('Organization Type'),
          'vocabulary' => 'organization_type',
          'shortcut' => 'ot',
          'join_callback' => 'joinOrganizationType',
          'allow_no_value' => FALSE,
        ]),
        'ocha_product' => array_merge($taxonomy_reference, [
          'field' => 'field_ocha_product',
          'label' => $this->t('OCHA Product'),
          'vocabulary' => 'ocha_product',
          'shortcut' => 'op',
        ]),
        'glide' => [
          'type' => 'field',
          'field' => 'field_glide',
          'column' => 'value',
          'label' => $this->t('Glide Number'),
          'shortcut' => 'g',
          'form' => 'omnibox',
          'widget' => 'autocomplete',
          'autocomplete_callback' => 'getGlideAutocompleteSuggestions',
        ],
        'content_type' => [
          'type' => 'field',
          'field' => 'field_allowed_content_types',
          'column' => 'value',
          'label' => $this->t('Content type'),
          'shortcut' => 'ct',
          'form' => 'other',
          'values' => [
            1 => 'Reports',
            0 => 'Jobs',
            2 => 'Training',
          ],
        ],
        'created' => [
          'type' => 'property',
          'field' => 'created',
          'label' => $this->t('Post date'),
          'shortcut' => 'pd',
          'form' => 'omnibox',
          'widget' => 'datepicker',
        ],
        'reviewed' => [
          'type' => 'property',
          'field' => 'revision_created',
          'label' => $this->t('Review date'),
          'shortcut' => 'rwd',
          'form' => 'omnibox',
          'widget' => 'datepicker',
          'join_callback' => 'joinReview',
        ],
        'job_closing_date' => [
          'type' => 'field',
          'field' => 'field_job_closing_date',
          'column' => 'value',
          'label' => $this->t('Closing date'),
          'shortcut' => 'cd',
          'form' => 'omnibox',
          'widget' => 'datepicker',
        ],
        'registration_deadline' => [
          'type' => 'field',
          'field' => 'field_registration_deadline',
          'column' => 'value',
          'label' => $this->t('Registration deadline'),
          'shortcut' => 'rd',
          'form' => 'omnibox',
          'widget' => 'datepicker',
        ],
        'disaster_date' => [
          'type' => 'field',
          'field' => 'field_disaster_date',
          'column' => 'value',
          'label' => $this->t('Creation date'),
          'shortcut' => 'cd',
          'form' => 'omnibox',
          'widget' => 'datepicker',
        ],
        'author' => [
          'type' => 'property',
          'field' => 'uid',
          'label' => $this->t('Author'),
          'shortcut' => 'a',
          'form' => 'omnibox',
          'widget' => 'autocomplete',
          'autocomplete_callback' => 'getUserAutocompleteSuggestions',
          'operator' => 'OR',
        ],
        'reviewer' => [
          'type' => 'property',
          'field' => 'revision_user',
          'label' => $this->t('Reviewer'),
          'shortcut' => 'rw',
          'form' => 'omnibox',
          'widget' => 'autocomplete',
          'autocomplete_callback' => 'getUserAutocompleteSuggestions',
          'operator' => 'OR',
          'join_callback' => 'joinReview',
        ],
        'user_role' => [
          'type' => 'other',
          'field' => 'roles_target_id',
          'label' => $this->t('User Roles'),
          'shortcut' => 'ur',
          'form' => 'omnibox',
          'widget' => 'autocomplete',
          'autocomplete_callback' => 'getUserRoleAutocompleteSuggestions',
          'operator' => 'OR',
          'join_callback' => 'joinUsersRoles',
        ],
        'posting_rights' => [
          'type' => 'other',
          'field' => 'posting_rights',
          'label' => $this->t('Posting Rights'),
          'shortcut' => 'pr',
          'form' => 'omnibox',
          'widget' => 'autocomplete',
          'operator' => 'OR',
          'join_callback' => 'joinPostingRights',
          'values' => [
            0 => 'Unverified',
            1 => 'Blocked',
            2 => 'Allowed',
            3 => 'Trusted',
          ],
        ],
        'name' => [
          'type' => 'property',
          'field' => 'name',
          'label' => $this->t('Name'),
          'shortcut' => 'n',
          'form' => 'omnibox',
          'widget' => 'search',
        ],
        'title' => [
          'type' => 'property',
          'field' => 'title',
          'label' => $this->t('Title'),
          'shortcut' => 't',
          'form' => 'omnibox',
          'widget' => 'search',
        ],
        'body' => [
          'type' => 'field',
          'field' => 'body',
          'column' => 'value',
          'label' => $this->t('Body'),
          'shortcut' => 'b',
          'form' => 'omnibox',
          'widget' => 'search',
        ],
        'how_to_apply' => [
          'type' => 'field',
          'field' => 'field_how_to_apply',
          'column' => 'value',
          'label' => $this->t('How to apply'),
          'shortcut' => 'ha',
          'form' => 'omnibox',
          'widget' => 'search',
        ],
        'shortname' => [
          'type' => 'field',
          'field' => 'field_shortname',
          'column' => 'value',
          'label' => $this->t('Shortname'),
          'shortcut' => 'sn',
          'form' => 'omnibox',
          'widget' => 'search',
        ],
        'headline_title' => [
          'type' => 'field',
          'field' => 'field_headline_title',
          'column' => 'value',
          'label' => $this->t('Headline Title'),
          'shortcut' => 'ht',
          'form' => 'omnibox',
          'widget' => 'search',
        ],
        'headline_summary' => [
          'type' => 'field',
          'field' => 'field_headline_summary',
          'column' => 'value',
          'label' => $this->t('Headline Summary'),
          'shortcut' => 'hs',
          'form' => 'omnibox',
          'widget' => 'search',
        ],
        'headline' => [
          'type' => 'field',
          'label' => $this->t('Headline'),
          'field' => 'field_headline',
          'column' => 'value',
          'form' => 'other',
        ],
        'bury' => [
          'type' => 'field',
          'label' => $this->t('Bury'),
          'field' => 'field_bury',
          'column' => 'value',
          'form' => 'other',
        ],
        'profile' => [
          'type' => 'field',
          'label' => $this->t('Show Profile'),
          'field' => 'field_profile',
          'column' => 'value',
          'form' => 'other',
        ],
        'featured' => [
          'type' => 'field',
          'label' => $this->t('Featured'),
          'field' => 'field_featured',
          'column' => 'value',
          'form' => 'other',
        ],
        'ongoing' => [
          'type' => 'field',
          'label' => $this->t('Ongoing'),
          'field' => 'field_training_date',
          'column' => 'value',
          'form' => 'other',
          'widget' => 'fieldnotset',
          'join_callback' => 'leftJoin',
          'values' => [
            'ongoing' => 'Ongoing',
          ],
        ],
        'cost' => [
          'type' => 'field',
          'label' => $this->t('Cost'),
          'field' => 'field_cost',
          'column' => 'value',
          'form' => 'other',
          'values' => [
            'free' => 'Free',
            'fee-based' => 'Fee-based',
          ],
        ],
        'training_format' => [
          'type' => 'field',
          'field' => 'field_training_format',
          'label' => $this->t('Training format'),
          'vocabulary' => 'training_format',
          'column' => 'target_id',
          'form' => 'other',
          'operator' => 'AND',
          'values' => [
            '4606' => 'On-site',
            '4607' => 'Online',
          ],
        ],
        'key_content' => [
          'type' => 'field',
          'label' => $this->t('Key Content'),
          'field' => 'field_key_content',
          'column' => 'url',
          'form' => 'other',
          // No specific widget as the join is enough.
          'widget' => 'none',
          'join_callback' => 'joinKeyContent',
        ],
      ];
    }

    // Get the definitions for the given filters and preserve order.
    $return = [];
    foreach ($filters as $name) {
      if (isset($this->filterDefinitions[$name])) {
        $return[$name] = $this->filterDefinitions[$name];
      }
    }
    return $return;
  }

  /**
   * Parse selected values for a filter, keeping only the valid ones.
   *
   * @param array|scalar $values
   *   Values to parse.
   * @param array|null $fixed_values
   *   List of fixed values for the filer.
   *
   * @return array
   *   Array of valid values.
   */
  protected function parseFilterValues($values, $fixed_values = NULL) {
    // Ensure values is an array.
    $values = is_array($values) ? $values : [$values => $values];

    // If the filter has static values, keep only valid ones.
    if (!empty($fixed_values)) {
      $values = array_intersect_key($values, $fixed_values);
    }

    // Filter out the invalid/unselected values: (int) 0.
    $values = array_keys(array_filter($values, function ($value) {
      return $value !== 0;
    }));

    return $values;
  }

  /**
   * Get the moderation content result totals from the query results.
   *
   * @param array $results
   *   Results as returned by ::executeQuery() containing notably the totals:
   *   number of entities matching the query grouped by entity status.
   *
   * @return array
   *   Render array for the totals.
   */
  protected function getTotals(array $results) {
    if (empty($results['totals'])) {
      return [];
    }

    // Prepare the table caption with the number of entities per status.
    $statuses = ['total' => $this->t('Total')] + $this->getFilterStatuses();
    $totals = $results['totals'];
    $list = [];
    foreach ($statuses as $status => $label) {
      if (isset($totals[$status])) {
        $list[$status] = [
          'status' => $status,
          'label' => $label,
          'total' => $totals[$status],
        ];
      }
    }
    return [
      '#theme' => 'reliefweb_moderation_totals',
      '#totals' => $list,
    ];
  }

  /**
   * Execute the query to get the moderation table rows' data.
   *
   * @param array $filters
   *   User selected filter.
   * @param int $limit
   *   Number of items to retrieve.
   *
   * @return array
   *   Associative array with the the list of entities matching the query,
   *   the totals of entities per status and the pager.
   */
  protected function executeQuery(array $filters, $limit = 30) {
    $data = [];

    // Entity informantion.
    $bundle = $this->getBundle();
    $entity_type_id = $this->getEntityTypeId();
    $entity_table = $this->getEntityTypeDataTable($entity_type_id);
    $entity_id_field = $this->getEntityTypeIdField($entity_type_id);
    $entity_bundle_field = $this->getEntityTypeBundleField($entity_type_id);

    // Base table. We use the content moderation state data table so we
    // can easily give hints on the status to improve performances.
    $base_table = $this->getEntityTypeDataTable('content_moderation_state');

    // Main query.
    $query = $this->getDatabase()->select($base_table, 'cms')
      ->fields('cms', ['moderation_state', 'content_entity_id'])
      ->condition('cms.content_entity_type_id', $entity_type_id, '=');

    // We use MySQL variables to store the count of entities per status.
    // This is much faster than executing several count queries with the same
    // filters.
    $variables = [];
    foreach (array_keys($this->getStatuses()) as $status) {
      // Statuses are machine names so it's safe to use them directly.
      $variables['@' . $status] = $status;
    }

    // Prepare the case expression to increment the variables.
    $cases = '';
    foreach ($variables as $variable => $status) {
      $cases .= "WHEN '{$status}' THEN {$variable} := {$variable} + 1 ";
    }

    // Add the expression to increment the counters.
    $query->addExpression("CASE cms.moderation_state {$cases} END");

    // Join the entity data table.
    $entity_table_alias = $query->innerJoin($entity_table, $entity_table, "%alias.{$entity_id_field} = cms.content_entity_id");

    // Filter for the service entity bundle.
    if (!empty($bundle)) {
      if (is_array($bundle)) {
        $query->condition($entity_table_alias . '.' . $entity_bundle_field, $bundle, 'IN');
      }
      else {
        $query->condition($entity_table_alias . '.' . $entity_bundle_field, $bundle, '=');
      }
    }

    // Filter the query with the form filters.
    $this->filterQuery($query, $filters);

    // Wrap the query in a parent query to which the ordering and limiting is
    // applied.
    //
    // The point here is that we have the filtered query return all the results
    // populating the counter variables doing so and the wrapper query will
    // return only a subset of the data according to the current page, limit
    // and sort criterium.
    $wrapper = $this->wrapQuery($query, $limit);

    $variables_keys = array_keys($variables);

    // Initialize the counters.
    $this->getDatabase()
      ->query('SET ' . implode(' := 0, ', $variables_keys) . ' := 0');

    // Retrieve the entity ids.
    $entity_ids = $wrapper
      ->execute()
      ?->fetchCol() ?? [];

    // Retrieve the counters.
    $totals = $this->getDatabase()
      ->query('SELECT ' . implode(', ', $variables_keys))
      ?->fetchAssoc() ?? [];

    // Load the entities.
    if (!empty($entity_ids)) {
      $data['entities'] = $this->entityTypeManager
        ->getStorage($entity_type_id)
        ->loadMultiple($entity_ids);
    }
    else {
      $data['entities'] = [];
    }

    // Parse the total of entities per status.
    $total = 0;
    foreach ($totals as $name => $number) {
      if (isset($variables[$name])) {
        $number = intval($number, 10);
        $data['totals'][$variables[$name]] = $number;
        $total += $number;
      }
    }
    $data['totals']['total'] = $total;

    // Initialize the pager with the total.
    $data['pager'] = $this->pagerManager->createPager($total, $limit);

    return $data;
  }

  /**
   * Get the order information of the table.
   *
   * @return array
   *   Associative array with the list of headers (with the one currently
   *   used for sorting having a sort property with the current sorting
   *   direction), the id of the header used for sorting and the sort direction.
   */
  protected function getOrderInformation() {
    $headers = $this->getHeaders();
    $order = $this->request->query->get('order', '');
    // We assume the date header is present and sortable.
    $order = !empty($headers[$order]['sortable']) ? $order : 'date';
    $sort = strtolower($this->request->query->get('sort', ''));
    $sort = in_array($sort, ['asc', 'desc']) ? $sort : 'desc';
    $headers[$order]['sort'] = $sort;

    return [
      'headers' => $headers,
      'order' => $order,
      'sort' => $sort,
    ];
  }

  /**
   * Get the query sorting information from the request query parameters.
   *
   * @return array
   *   Associative array with the header field, table, type and sort direction
   *   or empty if there was no user selected order.
   */
  protected function getQueryOrderInformation() {
    // Get the order information and the header currently used for sorting.
    $info = $this->getOrderInformation();
    $headers = $info['headers'];
    $order = $info['order'];
    $sort = $info['sort'];
    $header = $headers[$order];

    // Retrieve the field table for the header.
    if (!empty($header['specifier'])) {
      $specifier = $header['specifier'];
      $entity_type_id = $this->getEntityTypeId();

      switch ($header['type'] ?? '') {
        // Sort on an entity field.
        case 'field':
          if (isset($specifier['field'], $specifier['column'])) {
            $table = $this->getFieldTableName($entity_type_id, $specifier['field']);
            $field = $this->getFieldColumnName($entity_type_id, $specifier['field'], $specifier['column']);
          }
          break;

        // Sort on a property of the entity table.
        case 'property':
          if (is_string($specifier)) {
            $table = $this->getEntityTypeDataTable($entity_type_id);
            $field = $specifier;
          }
          break;

        // Sort on a property of the base table.
        case '':
          if (is_string($specifier)) {
            $table = '';
            $field = $specifier;
          }
          break;
      }
    }

    // Return the order information.
    if (isset($table, $field)) {
      return [
        'type' => $header['type'] ?? '',
        'table' => $table,
        'field' => $field,
        'direction' => $sort,
      ];
    }

    return [];
  }

  /**
   * Wrap the filtered query in another query.
   *
   * Sorting and limiting the number of results is done the wrapper query.
   *
   * @param \Drupal\Core\Database\Query\Select $query
   *   Query to wrap.
   * @param int $limit
   *   Number of items to retrieve.
   *
   * @return \Drupal\Core\Database\Query\Select
   *   Wrapper query.
   */
  protected function wrapQuery(Select $query, $limit = 30) {
    $order = $this->getQueryOrderInformation();
    $base_table_alias = $this->getQueryBaseTableAlias($query);
    $entity_type_id = $this->getEntityTypeId();

    // Join the sort field/property table if necessary.
    if (isset($order['type'], $order['table'], $order['field'])) {
      $sort_table = $order['table'];
      $sort_field = $order['field'];
      $sort_direction = $order['direction'] ?? 'desc';

      // Check the order type to determine the join condition.
      switch ($order['type']) {
        case 'field':
          $join_condition = "%alias.entity_id = {$base_table_alias}.content_entity_id";
          break;

        case 'property':
          $entity_id_field = $this->getEntityTypeIdField($entity_type_id);
          $join_condition = "%alias.{$entity_id_field} = {$base_table_alias}.content_entity_id";
          break;
      }

      if (isset($join_condition)) {
        // Check if we are already joining the table and retrieve its alias.
        $sort_table_alias = '';
        foreach ($query->getTables() as $info) {
          if (isset($info['table'], $info['alias']) && $info['table'] === $sort_table) {
            $sort_table_alias = $info['alias'];
            break;
          }
        }

        // If the sort table is not yet joined, do it.
        if (empty($sort_table_alias)) {
          $sort_table_alias = $query->leftJoin($sort_table, $sort_table, $join_condition);
        }
      }
      else {
        $sort_table_alias = $base_table_alias;
      }
    }
    // Otherwise sort on the entity id.
    else {
      $sort_field = 'content_entity_id';
      $sort_direction = 'desc';
      $sort_table_alias = $base_table_alias;
    }

    // Check the field/property to sort on is already present.
    $sort_field_alias = '';
    foreach ($query->getFields() as $info) {
      if ($info['field'] === $sort_field && $info['table'] === $sort_table_alias) {
        $sort_field_alias = $info['alias'];
        break;
      }
    }

    // Add the field/property to the query so the wrapper query can sort on it.
    if (empty($sort_field_alias)) {
      $sort_field_alias = $query->addField($sort_table_alias, $sort_field);
    }

    // Wrap the query.
    $wrapper = $this->getDatabase()->select($query, 'subquery');
    $wrapper->addField('subquery', 'content_entity_id', 'entity_id');
    $wrapper->addField('subquery', $sort_field_alias, 'sort');

    // Keep track of the subquery.
    // @todo review if that's still necessary.
    $wrapper->addMetaData('subquery', $query);

    // Add the sort property to the wrapper query.
    $wrapper->orderBy("subquery.{$sort_field_alias}", $sort_direction);

    // Set the query range to the wrapper.
    $page = $this->pagerManager->findPage();
    $wrapper->distinct()->range($page * $limit, $limit);

    return $wrapper;
  }

  /**
   * Add the filters to the query.
   *
   * @param \Drupal\Core\Database\Query\Select $query
   *   Main database query. It's a select query against the
   *   `content_moderation_state` table.
   * @param array $filters
   *   Filters from which to add conditions to the query.
   */
  protected function filterQuery(Select $query, array $filters = []) {
    // Merge with the service inner filters.
    if (!empty($this->filters)) {
      $filters = array_merge_recursive($this->filters, $filters);
    }

    // Skip if there are no filters.
    if (empty($filters)) {
      return;
    }

    // Entity information.
    $entity_type_id = $this->getEntityTypeId();
    $entity_base_table = $this->getEntityTypeDataTable($entity_type_id);
    $entity_id_field = $this->getEntityTypeIdField($entity_type_id);

    // The base table alias for the query is the content moderation table.
    $base_table_alias = $this->getQueryBaseTableAlias($query);

    // Available widgets for the filter form.
    $widgets = [
      'autocomplete',
      'datepicker',
      'search',
      'fieldnotset',
      'none',
    ];

    // Parse filters.
    $available_filters = $this->getFilterDefinitions();
    foreach ($filters as $name => $values) {
      if (isset($available_filters[$name]['type'], $available_filters[$name]['field'])) {
        $definition = $available_filters[$name];
        $widget = !empty($definition['widget']) ? $definition['widget'] : NULL;

        // Check the widget.
        if (isset($widget) && !in_array($widget, $widgets)) {
          continue;
        }

        // Parse the selected values, keeping only the valid ones.
        $values = $this->parseFilterValues($values, $definition['values'] ?? NULL);

        // Add the conditions.
        if (count($values) > 0) {
          // Special case for the status filter which is against the base table.
          if ($name === 'status') {
            $query->condition($base_table_alias . '.moderation_state', $values, 'IN');
            continue;
          }
          // Other filters.
          else {
            $or = !empty($definition['operator']) && $definition['operator'] === 'OR';
            $condition = $or ? new Condition('OR') : new Condition('AND');

            // AND filter.
            if (!$or) {
              // For AND type filter we need to join multiple times
              // the field table.
              foreach ($values as $value) {
                $field = $this->joinField($query, $definition, $entity_type_id, $entity_base_table, $entity_id_field, FALSE, $values);

                // Add the condition depending on the widget type.
                if (!isset($widget) || $widget === 'autocomplete') {
                  if ($value !== 'NULL') {
                    $this->addFilterCondition($definition, $condition, $field, $value);
                  }
                  else {
                    // We change the join type of the table to a LEFT join so
                    // we can find entities without the field.
                    $this->changeJoin($query, strtok($field, '.'), 'LEFT OUTER');
                    $this->addFilterCondition($definition, $condition, $field, NULL, 'IS NULL');
                  }
                }
                elseif ($widget === 'datepicker') {
                  list($start, $end) = array_pad(explode('-', $value, 2), 2, NULL);
                  $start = intval($start);
                  $end = intval($end);
                  // Should not happen.
                  if (empty($start) && empty($end)) {
                    continue;
                  }
                  elseif (empty($start)) {
                    $this->addFilterCondition($definition, $condition, $field, $end + 86399, '<=');
                  }
                  elseif (empty($end)) {
                    $this->addFilterCondition($definition, $condition, $field, $start, '>=');
                  }
                  else {
                    $this->addFilterCondition($definition, $condition, $field, [
                      $start,
                      $end + 86399,
                    ], 'BETWEEN');
                  }
                }
                elseif ($widget === 'search') {
                  $this->addFilterCondition($definition, $condition, $field, '%' . $this->getDatabase()->escapeLike($value) . '%', 'LIKE');
                }
                elseif ($widget === 'fieldnotset') {
                  // We change the join type of the table to a LEFT join so
                  // we can find entities without the field.
                  $this->changeJoin($query, strtok($field, '.'), 'LEFT OUTER');
                  $this->addFilterCondition($definition, $condition, $field, NULL, 'IS NULL');
                }
              }
            }
            // OR filter.
            else {
              // For OR type filter, joining once is enough.
              $field = $this->joinField($query, $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or, $values);

              // Add the condition depending on the widget type.
              if (!isset($widget) || $widget === 'autocomplete') {
                $is_null_condition = FALSE;
                foreach ($values as $index => $value) {
                  if ($value === 'NULL') {
                    unset($values[$index]);
                    $is_null_condition = TRUE;
                  }
                }

                if (!empty($values)) {
                  $this->addFilterCondition($definition, $condition, $field, $values, 'IN');
                }
                if ($is_null_condition) {
                  // We change the join type of the table to a LEFT join so
                  // we can find entities without the field.
                  $this->changeJoin($query, strtok($field, '.'), 'LEFT OUTER');
                  $this->addFilterCondition($definition, $condition, $field, NULL, 'IS NULL');
                }
              }
              elseif ($widget === 'datepicker') {
                foreach ($values as $value) {
                  list($start, $end) = array_pad(explode('-', $value, 2), 2, NULL);
                  $start = intval($start);
                  $end = intval(isset($end) ? $end : $start + 86399);
                  $this->addFilterCondition($definition, $condition, $field, [
                    $start,
                    $end,
                  ], 'BETWEEN');
                }
              }
              elseif ($widget === 'search') {
                foreach ($values as $value) {
                  $this->addFilterCondition($definition, $condition, $field, '%' . $this->getDatabase()->escapeLike($value) . '%', 'LIKE');
                }
              }
            }

            if ($condition->count() > 0) {
              $query->condition($condition);
            }
          }
        }
      }
    }
  }

  /**
   * Add a filter condition with the given values to the base condition.
   *
   * @param array $definition
   *   Field definition.
   * @param \Drupal\Core\Database\Query\Condition $base
   *   Base condition to add the field conditions to.
   * @param string|array $fields
   *   Field(s) on which to add the condtion.
   * @param mixed $value
   *   Value for the condition.
   * @param string|null $operator
   *   Operator for the condition.
   */
  protected function addFilterCondition(array $definition, Condition $base, $fields, $value, $operator = NULL) {
    // Skip.
    if (empty($fields)) {
      return;
    }

    if (!empty($definition['condition_callback']) && method_exists($this, $definition['condition_callback'])) {
      $this->{$definition['condition_callback']}($definition, $base, $fields, $value, $operator);
    }
    else {
      if (is_array($fields)) {
        if (count($fields) > 1) {
          $condition = new Condition('OR');
          foreach ($fields as $field) {
            $condition->condition($field, $value, $operator);
          }
          $base->condition($condition);
        }
        else {
          $base->condition(reset($field), $value, $operator);
        }
      }
      else {
        $base->condition($fields, $value, $operator);
      }
    }
  }

  /**
   * Join a field table to the query.
   *
   * @param \Drupal\Core\Database\Query\Select $query
   *   Query on which to join the field table.
   * @param array $definition
   *   Field definition.
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $entity_base_table
   *   Entity base table.
   * @param string $entity_id_field
   *   Entity id field.
   * @param bool $or
   *   Indicates if the condition is OR or AND.
   * @param array $values
   *   Filter values for the field.
   *
   * @return string
   *   Name of the joined field.
   */
  protected function joinField(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, array $values = []) {
    // Field info.
    $field_name = $definition['field'];

    // Get the field to which apply the condition and join the corresponding
    // table if needed.
    if (!empty($definition['join_callback']) && method_exists($this, $definition['join_callback'])) {
      $field = $this->{$definition['join_callback']}($query, $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or, $values);
    }
    // For or conditions on properties we need to join again the entity table.
    elseif ($definition['type'] === 'property') {
      $table = $this->getEntityTypeDataTable($entity_type_id);
      if (empty($or)) {
        $alias = $query->innerJoin($table, $table, "%alias.{$entity_id_field} = {$entity_base_table}.{$entity_id_field}");
      }
      else {
        $alias = $table;
      }
      $field = $alias . '.' . $field_name;
    }
    elseif ($definition['type'] === 'field' && isset($definition['column'])) {
      $table = $this->getFieldTableName($entity_type_id, $field_name);
      $alias = $query->innerJoin($table, $table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");
      $field = $alias . '.' . $this->getFieldColumnName($entity_type_id, $field_name, $definition['column']);
    }

    return $field;
  }

  /**
   * Organization type join callback.
   *
   * @see ::joinField()
   */
  protected function joinOrganizationType(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Field info.
    $field_name = $definition['field'];

    // Join the source field table.
    $join_table = $this->getFieldTableName($entity_type_id, 'field_source');
    $join_alias = $query->innerJoin($join_table, $join_table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");

    // Join the organization type field table.
    $table = $this->getFieldTableName('taxonomy_term', 'field_organization_type');
    $alias = $query->innerJoin($table, $table, "%alias.entity_id = {$join_alias}.field_source_target_id");

    return $alias . '.' . $this->getFieldColumnName('taxonomy_term', $field_name, $definition['column']);
  }

  /**
   * Users roles join callback.
   *
   * @see ::joinField()
   *
   * @todo this only works with nodes currently, check how to enable this
   * for taxonomy terms.
   */
  protected function joinUsersRoles(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Field info.
    $field_name = $definition['field'];

    // Join the users_roles table.
    $table = 'user__roles';
    $alias = $query->innerJoin($table, $table, "%alias.entity_id = {$entity_base_table}.uid AND %alias.bundle = :bundle", [
      ':bundle' => 'user',
    ]);

    return $alias . '.' . $field_name;
  }

  /**
   * Taxonomy term author join callback.
   *
   * @see ::joinField()
   */
  protected function joinTaxonomyAuthor(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Field info.
    $field_name = $definition['field'];

    // Get the taxonomy term revision table and fields.
    $id_field = $this->getEntityTypeIdField('taxonomy_term');
    $revision_id_field = $this->getEntityTypeRevisionIdField('taxonomy_term');
    $revision_table = $this->getEntityTypeRevisionTable('taxonomy_term');
    $revision_user_field = $this->getEntityTypeRevisionUserField('taxonomy_term');

    // Revision table subquery.
    $revision_query = (string) $this->getDatabase()
      ->select($revision_table, $revision_table)
      ->fields($revision_table, [$revision_user_field])
      ->where("{$revision_table}.{$id_field} = {$entity_base_table}.{$entity_id_field}")
      ->orderBy($revision_id_field, 'ASC')
      ->range(0, 1);

    // Join the users table.
    $table = $this->getEntityTypeDataTable('user');
    $alias = $query->innerJoin($table, $table, "%alias.uid = ({$revision_query})");

    return $alias . '.' . $field_name;
  }

  /**
   * Reviewer/reviewed join callback.
   *
   * @see ::joinField()
   */
  protected function joinReview(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Get the revision field mapping.
    $keys = $this->entityTypeManager
      ->getStorage($entity_type_id)
      ->getEntityType()
      ->getRevisionMetadataKeys();

    // Retrieve the actual field name from the revision key.
    $field_name = $keys[$definition['field']];

    // Revision table name.
    $table = $this->getEntityTypeRevisionTable($entity_type_id);

    // Check if the revision table has already been added,
    // in which case we'll use the existing join.
    foreach ($query->getTables() as $join) {
      if ($join['table'] === $table) {
        return $join['alias'] . '.' . $field_name;
      }
    }

    // Join the revision table.
    $alias = $query->innerJoin($table, $table, "%alias.{$entity_id_field} = {$entity_base_table}.{$entity_id_field}");
    return $alias . '.' . $field_name;
  }

  /**
   * Key Content join callback.
   *
   * @see ::joinField()
   */
  protected function joinKeyContent(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    $table = $this->getFieldTableName('taxonomy_term', 'field_key_content');
    $field_name = $this->getFieldColumnName('taxonomy_term', 'field_key_content', 'url');

    // Get all the node ids.
    $subquery = $this->getDatabase()->select($table, $table);
    $subquery->addExpression("SUBSTR({$table}.{$field_name}, 7)", 'entity_id');
    $subquery->where("LEFT({$table}.{$field_name}, 6) = '/node/'");

    $query->innerJoin($subquery, $table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");

    // No field to return as the inner join is enough.
    return '';
  }

  /**
   * User posting rights join callback.
   *
   * @see ::joinField()
   */
  protected function joinPostingRights(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    $bundle = $this->getBundle();

    // Skip for empty bundle.
    if ($bundle === '' || is_array($bundle)) {
      return '';
    }

    // This is only valid for jobs and training. Skip it otherwise.
    if ($bundle !== 'job' && $bundle !== 'training') {
      return '';
    }

    $bundle_field = $this->getEntityTypeBundleField($entity_type_id);

    // Subquery to find all the node matching the requested rights.
    $subquery = $this->getDatabase()->select($entity_base_table, $entity_base_table);
    $subquery->addField($entity_base_table, $entity_id_field);
    $subquery->condition($entity_base_table . '.' . $bundle_field, $bundle, '=');
    $subquery->groupBy($entity_base_table . '.' . $entity_id_field);

    // Join the users table.
    $users_table = $this->getEntityTypeDataTable('user');
    $users_alias = $subquery->innerJoin($users_table, $users_table, "%alias.uid = {$entity_base_table}.uid");

    // Join the source table.
    $source_table = $this->getFieldTableName($entity_type_id, 'field_source');
    $source_alias = $subquery->leftJoin($source_table, $source_table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");
    $source_field = $this->getFieldColumnName($entity_type_id, 'field_source', 'target_id');

    // Join the user posting rights table.
    $user_rights_table = $this->getFieldTableName('taxonomy_term', 'field_user_posting_rights');
    $user_rights_id_field = $this->getFieldTableName('taxonomy_term', 'field_user_posting_rights', 'id');
    $user_rights_bundle_field = $this->getFieldTableName('taxonomy_term', 'field_user_posting_rights', $bundle);
    $user_rights_alias = $subquery->leftJoin($user_rights_table, $user_rights_table, "%alias.entity_id = {$source_alias}.{$source_field} AND %alias.{$user_rights_id_field} = {$users_alias}.uid");

    // Count the number of sources for a node for which the user has the
    // requested posting right. The computed right is then　compared to the
    // requested one, increasing the counter if it's a match.
    //
    // For 'unverified' and 'blocked' rights, we only need to check that there
    // is at least one source for which the user is respectively unverified or
    // blocked.
    //
    // For 'allowed' and 'trusted', the user must be respectively allowed or
    // trusted for all the sources.
    $having = [];
    $count_sources = FALSE;
    foreach ($values as $value) {
      switch ($value) {
        // Unverified.
        case 0:
          // Unverified users include users with 'unverified' rights and users
          // with no posting rights records for a source or if there is no
          // sources (caught via the 0 at the end of the COALESCE).
          $subquery->addExpression("SUM(IF(COALESCE({$user_rights_alias}.{$user_rights_bundle_field}, 0) = 0, 1, 0))", 'unverified');
          $having[] = 'unverified <> 0';
          break;

        // Blocked.
        case 1:
          $subquery->addExpression("SUM(IF({$user_rights_alias}.{$user_rights_bundle_field} = 1, 1, 0))", 'blocked');
          $having[] = 'blocked <> 0';
          break;

        // Allowed.
        case 2:
          $subquery->addExpression("SUM(IF({$user_rights_alias}.{$user_rights_bundle_field} = 2, 1, 0))", 'allowed');
          $having[] = '(sources <> 0 AND allowed = sources)';
          $count_sources = TRUE;
          break;

        // Trusted.
        case 3:
          $subquery->addExpression("SUM(IF({$user_rights_alias}.{$user_rights_bundle_field} = 3, 1, 0))", 'trusted');
          $having[] = '(sources <> 0 AND trusted = sources)';
          $count_sources = TRUE;
          break;
      }
    }

    // For 'allowed' and 'trusted' we need to keep track of the number of
    // sources for a node to compare against the rights counter.
    if ($count_sources) {
      $subquery->addExpression("COUNT({$source_alias}.{$source_field})", 'sources');
    }

    // Add the having condition.
    $subquery->having(implode(' OR ', $having));

    // Finally inner join the subquery to main query.
    $query->innerJoin($subquery, NULL, "%alias.{$entity_id_field} = {$entity_base_table}.{$entity_id_field}");

    // No field to return as the inner join of the subquery is enough.
    return '';
  }

  /**
   * Left outer join.
   *
   * @see ::joinField()
   */
  protected function leftJoin(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Field info.
    $field_name = $definition['field'];

    $table = $this->getFieldTableName($entity_type_id, $field_name);
    $alias = $query->leftJoin($table, $table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field}");
    $field = $alias . '.' . $this->getFieldColumnName($entity_type_id, $field_name, $definition['column']);

    return $field;
  }

  /**
   * Alter the join type of table.
   *
   * @param \Drupal\Core\Database\Query\Select $query
   *   Query to alter.
   * @param string $alias
   *   Table alias.
   * @param string $type
   *   Type of the join (either INNER or LEFT OUTER).
   */
  protected function changeJoin(Select $query, $alias, $type) {
    if ($type === 'INNER' || $type === 'LEFT OUTER') {
      $tables = &$query->getTables();
      if (isset($tables[$alias])) {
        $tables[$alias]['join type'] = $type;
      }
    }
  }

  /**
   * Get taxonomy term suggestions for the given term.
   *
   * @param string $filter
   *   Filter name.
   * @param string $term
   *   Autocomplete search term.
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $replacements
   *   Value replacements for the search condition.
   *
   * @return array
   *   List of suggestions. Each suggestion is an object with a value, label
   *   and optional abbreviation (abbr).
   */
  protected function getTaxonomyTermAutocompleteSuggestions($filter, $term, $conditions, array $replacements) {
    $bundle = $this->getBundle();

    $filter_definition = $this->getFilterDefinition($filter);
    if (!isset($filter_definition['vocabulary'])) {
      return [];
    }

    // Table and field information.
    $vocabulary = $filter_definition['vocabulary'];
    $table = $this->getEntityTypeDataTable('taxonomy_term');
    $alias = $table;
    $bundle_field = $this->getEntityTypeBundleField('taxonomy_term');
    $id_field = $this->getEntityTypeIdField('taxonomy_term');
    $label_field = $this->getEntityTypeLabelField('taxonomy_term');
    $join_condition = "%alias.entity_id = {$alias}.{$id_field}";

    // List of fields used for the condition replacements.
    $fields = [$alias . '.' . $label_field];

    // Base query.
    $query = $this->getDatabase()->select($table, $alias);
    $query->condition($alias . '.' . $bundle_field, $vocabulary, '=');
    $query->addField($alias, $id_field, 'value');
    $query->addField($alias, $label_field, 'label');
    $query->range(0, 10);
    $query->distinct();

    // Short name as abbreviation.
    $field_definitions = $this->entityFieldManager
      ->getFieldDefinitions('taxonomy_term', $vocabulary);

    if (isset($field_definitions['field_shortname'])) {
      $shortname_table = $this->getFieldTableName('taxonomy_term', 'field_shortname');
      $shortname_field = $this->getFieldColumnName('taxonomy_term', 'field_shortname', 'value');
      $shortname_alias = $query->leftJoin($shortname_table, $shortname_table, $join_condition);
      $query->addField($shortname_alias, $shortname_field, 'abbr');
      $fields[] = $shortname_alias . '.' . $shortname_field;
    }

    // Special cases.
    //
    // For sources, we limit the list to organizations allowed to have
    // content corresponding to the current moderation service's bundle.
    if ($vocabulary === 'source') {
      // Note: the array is ordered this way to match the numeric value
      // associated with the node bundle in the database.
      $content_type = array_search($bundle, ['job', 'report', 'training']);
      if ($content_type !== FALSE) {
        $content_type_table = $this->getFieldTableName('taxonomy_term', 'field_allowed_content_types');
        $content_type_field = $this->getFieldColumnName('taxonomy_term', 'field_allowed_content_types', 'value');
        $content_type_alias = $query->innerJoin($content_type_table, $content_type_table, $join_condition);
        $query->condition($content_type_alias . '.' . $content_type_field, $content_type, '=');
      }
    }
    // For disasters, we order by most recent first. The id is enough for that.
    elseif ($vocabulary === 'disaster') {
      $query->orderBy($alias . '.' . $id_field, 'DESC');
    }

    // Add conditions.
    $conditions = $this->buildFilterConditions($conditions, $fields);
    $query->where($conditions, $replacements);

    // Sort by name.
    $query->orderBy($alias . '.' . $label_field, 'ASC');

    return $query->execute()?->fetchAll() ?? [];
  }

  /**
   * Get user suggestions for the given term.
   *
   * @param string $filter
   *   Filter name.
   * @param string $term
   *   Autocomplete search term.
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $replacements
   *   Value replacements for the search condition.
   *
   * @return array
   *   List of suggestions. Each suggestion is an object with a value, label
   *   and optional abbreviation (abbr).
   */
  protected function getUserAutocompleteSuggestions($filter, $term, $conditions, array $replacements) {
    $bundle = $this->getBundle();
    $entity_type_id = $this->getEntityTypeId();

    $table = $this->getEntityTypeDataTable('user');
    $alias = $table;
    $id_field = $this->getEntityTypeIdField('user');

    // List of fields used for the condition replacements.
    $fields = [$alias . '.name', $alias . '.mail'];

    $term = mb_strtolower($term);
    // Special users.
    if ($term === 'anonymous') {
      return [(object) ['value' => 0, 'label' => 'Anonymous']];
    }
    elseif ($term === 'admin') {
      return [(object) ['value' => 1, 'label' => 'admin']];
    }
    elseif ($term === 'system') {
      return [(object) ['value' => 2, 'label' => 'System']];
    }

    // Base query.
    $query = $this->getDatabase()->select($table, $alias);
    $query->addField($alias, $id_field, 'value');
    $query->addField($alias, 'name', 'label');
    $query->addField($alias, 'mail', 'abbr');
    $query->range(0, 10);
    $query->distinct();

    // Limit to users who actually have posted entities of this bundle.
    $revision_table = $this->getEntityTypeRevisionTable($entity_type_id);
    $revision_id_field = $this->getEntityTypeRevisionIdField($entity_type_id);
    $revision_user_field = $this->getEntityTypeRevisionUserField($entity_type_id);
    $revision_alias = $query->innerJoin($revision_table, $revision_table, "%alias.{$revision_user_field} = {$alias}.{$id_field}");

    $entity_table = $this->getEntityTypeBaseTable($entity_type_id);
    $entity_bundle_field = $this->getEntityTypeBundleField($entity_type_id);
    $entity_alias = $query->innerJoin($entity_table, $entity_table, "%alias.{$revision_id_field} = {$revision_alias}.{$revision_id_field}");
    $query->condition($entity_alias . '.' . $entity_bundle_field, $bundle, '=');

    // Add conditions.
    $conditions = $this->buildFilterConditions($conditions, $fields);
    $query->where($conditions, $replacements);

    // Sort by name.
    $query->orderBy($alias . '.name', 'ASC');

    return $query->execute()?->fetchAll() ?? [];
  }

  /**
   * Get user role suggestions for the given term.
   *
   * @param string $filter
   *   Filter name.
   * @param string $term
   *   Autocomplete search term.
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $replacements
   *   Value replacements for the search condition.
   *
   * @return array
   *   List of suggestions. Each suggestion is an object with a value, label
   *   and optional abbreviation (abbr).
   */
  public function getUserRoleAutocompleteSuggestions($filter, $term, $conditions, array $replacements) {
    $suggestions = [];
    $term = mb_strtolower($term);

    foreach (user_roles(TRUE) as $id => $role) {
      $label = $role->label();
      if (mb_strpos($id, $term) !== FALSE || mb_strpos(mb_strtolower($label), $term) !== FALSE) {
        $suggestions[$id] = (object) [
          'value' => $id,
          'label' => $label,
        ];
      }
    }

    ksort($suggestions);
    return array_values($suggestions);
  }

  /**
   * Get glide suggestions for the given term.
   *
   * @param string $filter
   *   Filter name.
   * @param string $term
   *   Autocomplete search term.
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $replacements
   *   Value replacements for the search condition.
   *
   * @return array
   *   List of suggestions. Each suggestion is an object with a value, label
   *   and optional abbreviation (abbr).
   */
  public function getGlideAutocompleteSuggestions($filter, $term, $conditions, array $replacements) {
    $bundle = $this->getBundle();
    $entity_type_id = $this->getEntityTypeId();
    $entity_table = $this->getEntityTypeBaseTable('taxonomy_term');
    $entity_id_field = $this->getEntityTypeIdField('taxonomy_term');
    $entity_bundle_field = $this->getEntityTypeBundleField('taxonomy_term');
    $join_condition = "%alias.entity_id = {$entity_table}.{$entity_id_field}";

    // Get the glide field tables and value fields.
    $glide_table = $this->getFieldTableName($entity_type_id, 'field_glide');
    $glide_field = $this->getFieldColumnName($entity_type_id, 'field_glide', 'value');
    $related_glide_table = $this->getFieldTableName($entity_type_id, 'field_glide_related');
    $related_glide_field = $this->getFieldColumnName($entity_type_id, 'field_glide_related', 'value');

    // Base query.
    $query = $this->getDatabase()->select($entity_table, $entity_table);
    $query->condition($entity_table . '.' . $entity_bundle_field, $bundle, '=');

    // Join the Glide fields.
    $glide_alias = $query->leftJoin($glide_table, $glide_table, $join_condition);
    $related_glide_alias = $query->leftJoin($related_glide_table, $related_glide_table, $join_condition);

    $query->addField($glide_alias, $glide_field, 'glide');
    $query->addField($related_glide_alias, $related_glide_field, 'related_glide');

    $query->range(0, 10);
    $query->distinct();

    // List of fields used for the condition replacements.
    $fields = [
      $glide_alias . '.' . $glide_field,
      $related_glide_alias . '.' . $related_glide_field,
    ];

    // Add conditions.
    $conditions = $this->buildFilterConditions($conditions, $fields);
    $query->where($conditions, $replacements);

    $term = strtolower($term);
    $suggestions = [];

    $results = $query->execute()?->fetchAll() ?? [];
    foreach ($results as $result) {
      $glide_numbers = preg_split('#\s+#', trim($result->related_glide));
      $glide_numbers[] = $result->glide;

      foreach ($glide_numbers as $glide_number) {
        $glide_number = trim($glide_number);
        if (strpos(strtolower($glide_number), $term) !== FALSE) {
          $suggestions[] = (object) [
            'value' => $glide_number,
            'label' => $glide_number,
          ];
        }
      }
    }

    return $suggestions;
  }

  /**
   * Build filter condition based on matcher string and required fields.
   *
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $fields
   *   List of fields to replace in the conditions.
   *
   * @return string
   *   A stringified OR condition group with each condition being a stringified
   *   AND condition group.
   */
  protected function buildFilterConditions($conditions, array $fields) {
    $new_conditions = [];
    foreach ($fields as $field) {
      $new_conditions[] = strtr($conditions, ['@field' => $field]);
    }
    return '(' . implode(' OR ', $new_conditions) . ')';
  }

  /**
   * Create a filter link.
   *
   * @param string $title
   *   Link title.
   * @param string $parameter
   *   Filter parameter name.
   * @param string $value
   *   Filter parameter value.
   *
   * @return \Drupal\Core\GeneratedLink
   *   Link.
   */
  protected function getFilterLink($title, $parameter, $value) {
    // Get the query parameters, keeping only the moderation page filter form
    // paremeters.
    // @see \Drupal\reliefweb_moderation\Form\ModerationPageFilterForm
    $parameters = $this->request->query->all();
    $parameters = array_intersect_key($parameters, [
      'filters' => TRUE,
      'omnibox' => TRUE,
      'selection' => TRUE,
    ]);

    // Copy the parameters.
    $query = $parameters;

    // Loop through the nested parameter to find where to put the value.
    $parent = &$query;
    $keys = explode('[', $parameter);
    foreach ($keys as $key) {
      $key = rtrim($key, ']');
      if ($key === '') {
        break;
      }
      if (!isset($parent[$key])) {
        $parent[$key] = [];
      }
      $parent = &$parent[$key];
    }

    if (is_array($parent) && !is_array($value)) {
      $parent[] = $value;
      // Ensure the filter value is only added once.
      $parent = array_unique($parent);
    }
    else {
      $parent = $value;
    }
    $url = Url::fromRoute('<current>', [], ['query' => $query]);

    return Link::fromTextAndUrl($title, $url)->toString();
  }

  /**
   * Get the link to a taxonomy term's page.
   *
   * @param \Drupal\Core\Field\FieldItemInterface|null $item
   *   Entiry referenc field item.
   *
   * @return \Drupal\Core\GeneratedLink
   *   Link.
   */
  protected function getTaxonomyTermLink(?FieldItemInterface $item) {
    if (empty($item)) {
      return NULL;
    }

    $entity = $item->entity;
    if (empty($entity)) {
      return NULL;
    }

    if ($entity->hasField('field_shortname') && !$entity->field_shortname->isEmpty()) {
      $title = $entity->field_shortname->value;
    }
    else {
      $title = $entity->label();
    }

    return $entity->toLink($title)->toString();
  }

  /**
   * Format a timestamp or date.
   *
   * @param int|string $date
   *   Timestamp or date string.
   * @param string $type
   *   Format type.
   * @param string $format
   *   Custom format pattern.
   *
   * @return string
   *   Formatted date.
   *
   * @see \Drupal\Core\Datetime\DateFormatterInterface::format()
   */
  protected function formatDate($date, $type = 'custom', $format = 'j M Y') {
    if (!is_numeric($date)) {
      $date = date_create($date, timezone_open('UTC'))->getTimeStamp();
    }
    return $this->dateFormatter->format($date, $type, $format);
  }

  /**
   * Get the edit link and moderation status info for the entity.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return array
   *   Array with the edit link and the status info (label and value).
   */
  protected function getEntityEditAndStatusData(EntityModeratedInterface $entity) {
    return [
      'link' => $entity->toLink($this->t('edit'), 'edit-form')->toString(),
      'status' => [
        'label' => $entity->getModerationStatusLabel(),
        'value' => $entity->getModerationStatus(),
      ],
    ];
  }

  /**
   * Get the entity creator.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return \Drupal\Core\GeneratedLink|null
   *   Link to the page filtered by the user or NULL if the creator couldn't be
   *   determined.
   */
  protected function getEntityAuthorData(EntityModeratedInterface $entity) {
    $entity_type_id = $entity->getEntityTypeId();

    switch ($entity_type_id) {
      case 'node':
        $author_id = $entity->getOwnerId();
        $author_title = $entity->getOwner()->label() ?? 'System';
        break;

      case 'taxonomy_term':
        // Assume the user associated with the first revision is the creator.
        $entity_id_field = $this->getEntityTypeIdField($entity_type_id);
        $revision_table = $this->getEntityTypeRevisionTable($entity_type_id);
        $revision_id_field = $this->getEntityTypeRevisionIdField($entity_type_id);
        $revision_user_field = $this->getEntityTypeRevisionUserField($entity_type_id);

        $query = $this->getDatabase()->select($revision_table, $revision_table);
        $query->fields($revision_table, [$revision_user_field]);
        $query->condition($revision_table . '.' . $entity_id_field, $entity->id(), '=');
        $query->orderBy($revision_table . '.' . $revision_id_field, 'ASC');
        $query->range(0, 1);

        $author_id = $query->execute()?->fetchField();
        if (!empty($author_id)) {
          $user = $this->entityTypeManager->getStorage('user')->load($author_id);
          if (!empty($user)) {
            $author_title = $user->label();
          }
          else {
            return NULL;
          }
        }
        break;

      default:
        return NULL;
    }

    $author_parameter = 'selection[author][]';
    $author_value = $author_id . ':' . $author_title;
    return $this->getFilterLink($author_title, $author_parameter, $author_value);
  }

  /**
   * Get the revision information for the entity.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return array
   *   Array with the revision information (type, message, reviewer) to display
   *   in the moderation backend pages if there is a revision message.
   */
  protected function getEntityRevisionData(EntityModeratedInterface $entity) {
    // Revision information.
    $revision_message = trim($entity->getRevisionLogMessage() ?? '');

    // Skip if there is no log message.
    if (empty($revision_message)) {
      return [];
    }

    $revision_user = $entity->getRevisionUser();
    if (!$revision_user->isAnonymous()) {
      $reviewer_title = $revision_user->label() ?? 'System';
      $reviewer_parameter = 'selection[reviewer][]';
      $reviewer_value = $revision_user->id() . ':' . $reviewer_title;
      $reviewer = $this->getFilterLink($reviewer_title, $reviewer_parameter, $reviewer_value);
    }
    else {
      $reviewer = $this->t('Anonymous');
    }

    $type = 'feedback';
    if (method_exists($entity, 'getOwnerId')) {
      $type = $revision_user->id() === $entity->getOwnerId() ? 'comment' : 'feedback';
    }

    return [
      'type' => $type,
      'message' => $revision_message,
      'reviewer' => $reviewer,
    ];
  }

  /**
   * Get the entity creation date.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return string|int
   *   Timestamp or ISO 8601 date string. If the date couldn't be
   *   determined we use the creation date of ReliefWeb...
   */
  protected function getEntityCreationDate(EntityModeratedInterface $entity) {
    $entity_type_id = $entity->getEntityTypeId();

    switch ($entity_type_id) {
      case 'node':
        $date = $entity->getCreatedTime();

      case 'taxonomy_term':
        // Consider the timestamp of the first revision as the creation date.
        // This is not super reliable but no choice for now...
        //
        // @see https://www.drupal.org/project/drupal/issues/1295148
        // @see https://www.drupal.org/project/drupal/issues/1882678
        $entity_id_field = $this->getEntityTypeIdField($entity_type_id);
        $revision_table = $this->getEntityTypeRevisionTable($entity_type_id);
        $revision_id_field = $this->getEntityTypeRevisionIdField($entity_type_id);
        $revision_created_field = $this->getEntityTypeRevisionCreatedField($entity_type_id);

        $query = $this->getDatabase()->select($revision_table, $revision_table);
        $query->fields($revision_table, [$revision_created_field]);
        $query->condition($revision_table . '.' . $entity_id_field, $entity->id(), '=');
        $query->orderBy($revision_table . '.' . $revision_id_field, 'ASC');
        $query->range(0, 1);

        $date = $query->execute()?->fetchField();
    }

    return empty($date) ? '1996-04-01T00:00:00+0000' : $date;
  }

}
