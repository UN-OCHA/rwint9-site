<?php

namespace Drupal\reliefweb_user_posts\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Form\ModerationPageFilterForm;
use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\reliefweb_user_posts\Services\UserPostsServiceBase;
use Drupal\user\UserInterface;

/**
 * Content moderation page filter form handler for My posts.
 */
class UserPostsPageFilterForm extends ModerationPageFilterForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_user_posts_page_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ModerationServiceInterface $service = NULL, ?UserInterface $user = NULL, ?string $bundle = NULL) {
    // Parent assumes an omnibox when selection query params are present.
    // My posts no longer uses the omnibox; preserve selection for the
    // controller's legacy URL handling, then restore after parent build.
    $input = $form_state->getUserInput();
    $selection = $input['selection'] ?? NULL;
    if (isset($input['selection'])) {
      unset($input['selection']);
      $form_state->setUserInput($input);
    }

    $form = parent::buildForm($form, $form_state, $service);

    if ($selection !== NULL) {
      $input = $form_state->getUserInput();
      $input['selection'] = $selection;
      $form_state->setUserInput($input);
    }

    // No omnibox filters remain; drop empty omnibox and selection chips UI.
    // Create lives in the page intro section, not next to Filter/Reset.
    unset($form['filters']['omnibox'], $form['selection'], $form['actions']['create']);

    // Add the filter labels to the properties.
    $definitions = $service->getFilterDefinitions();
    foreach ($definitions as $name => $filter) {
      if (isset($form['filters']['other'][$name], $filter['label'])) {
        $form['filters']['other'][$name]['#title'] = $filter['label'];
      }
    }
    // Hide the properties label (Status, Posted by, etc.).
    if (isset($form['filters']['status'])) {
      $form['filters']['status']['#weight'] = 0;
    }
    if (isset($form['filters']['other'])) {
      $form['filters']['other']['#title_display'] = 'invisible';
      $form['filters']['other']['#weight'] = 1;
    }

    if ($service instanceof UserPostsServiceBase) {
      $this->addSourceFilter($form, $form_state, $service);
      $this->addTextFilters($form, $form_state, $definitions);
      $this->addDateFilters($form, $form_state, $definitions);
    }

    // Make js work.
    $form['#attributes']['id'] = 'reliefweb-moderation-page-filter-form';
    if (isset($form['filters']['other']['poster'])) {
      $can_see_colleagues = $bundle && $user
        && UserPostsServiceBase::hasAffiliatedAccess($user, $bundle);
      if (!$can_see_colleagues) {
        // No colleagues to filter; own posts only.
        unset($form['filters']['other']['poster']);
      }
      elseif (!isset($form['filters']['other']['poster']['#default_value'])) {
        $form['filters']['other']['poster']['#default_value'] = ['me', 'colleagues'];
      }
    }

    return $form;
  }

  /**
   * Add the source autocomplete select filter.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param \Drupal\reliefweb_user_posts\Services\UserPostsServiceBase $service
   *   User posts service.
   */
  protected function addSourceFilter(array &$form, FormStateInterface $form_state, UserPostsServiceBase $service): void {
    $source_filter = $service->getSourceFilterOptions();
    $wrapper_attributes = [
      'class' => [
        'rw-moderation-filter-source',
        'rw-moderation-filter-group',
      ],
    ];

    if (empty($source_filter['options'])) {
      $form['filters']['source'] = [
        '#type' => 'item',
        '#title' => $this->t('My organization(s)'),
        '#markup' => $this->t('No associated organizations'),
        '#weight' => 2,
        '#wrapper_attributes' => $wrapper_attributes,
      ];
      return;
    }

    $input = $form_state->getUserInput();
    $default = [];
    if (!empty($input['filters']['source']) && is_array($input['filters']['source'])) {
      $default = array_values(array_intersect(
        array_map('strval', $input['filters']['source']),
        array_map('strval', array_keys($source_filter['options']))
      ));
    }

    $form['filters']['source'] = [
      '#type' => 'select',
      '#title' => $this->t('My organization(s)'),
      '#options' => $source_filter['options'],
      '#option_attributes' => $source_filter['attributes'],
      '#multiple' => TRUE,
      '#parents' => ['filters', 'source'],
      '#default_value' => $default,
      '#weight' => 2,
      '#attributes' => [
        'data-with-autocomplete' => '',
        'data-autocomplete-placeholder' => $this->t('Type or select'),
      ],
      '#wrapper_attributes' => $wrapper_attributes,
    ];

    $form['#attached']['library'][] = 'reliefweb_form/widget.autocomplete';
    $form['#attached']['library'][] = 'common_design_subtheme/rw-autocomplete';
  }

  /**
   * Add dedicated Title and Id text filters on one row.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $definitions
   *   Filter definitions.
   */
  protected function addTextFilters(array &$form, FormStateInterface $form_state, array $definitions): void {
    $input = $form_state->getUserInput();

    if (!isset($definitions['title']) && !isset($definitions['nid'])) {
      return;
    }

    $form['filters']['text'] = [
      '#type' => 'container',
      '#weight' => 3,
      '#attributes' => [
        'class' => [
          'rw-moderation-filter-text',
          'rw-moderation-filter-group',
        ],
      ],
    ];

    if (isset($definitions['title'])) {
      $form['filters']['text']['title'] = [
        '#type' => 'textfield',
        '#title' => $definitions['title']['label'] ?? $this->t('Title'),
        '#size' => 60,
        '#parents' => ['filters', 'title'],
        '#default_value' => is_string($input['filters']['title'] ?? NULL) ? $input['filters']['title'] : '',
        '#attributes' => [
          'placeholder' => $this->t('Type part of a title'),
        ],
        '#wrapper_attributes' => [
          'class' => [
            'rw-moderation-filter-title',
          ],
        ],
      ];
    }

    if (isset($definitions['nid'])) {
      $form['filters']['text']['nid'] = [
        '#type' => 'textfield',
        '#title' => $definitions['nid']['label'] ?? $this->t('Id'),
        '#size' => 12,
        '#parents' => ['filters', 'nid'],
        '#default_value' => is_string($input['filters']['nid'] ?? NULL) ? $input['filters']['nid'] : '',
        '#attributes' => [
          'placeholder' => $this->t('e.g. 12345'),
        ],
        '#wrapper_attributes' => [
          'class' => [
            'rw-moderation-filter-nid',
          ],
        ],
      ];
    }
  }

  /**
   * Add from/to datepicker filters for post date and deadline.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $definitions
   *   Filter definitions.
   */
  protected function addDateFilters(array &$form, FormStateInterface $form_state, array $definitions): void {
    $input = $form_state->getUserInput();
    $attached = FALSE;

    if (isset($definitions['created'])) {
      $created = is_array($input['filters']['created'] ?? NULL)
        ? $input['filters']['created']
        : [];
      $form['filters']['created'] = $this->buildDateRangeFieldset(
        $definitions['created']['label'] ?? $this->t('Post date'),
        ['filters', 'created'],
        $created,
        4,
        'rw-moderation-filter-created'
      );
      $attached = TRUE;
    }

    if (isset($definitions['deadline'])) {
      $deadline = is_array($input['filters']['deadline'] ?? NULL)
        ? $input['filters']['deadline']
        : [];
      $form['filters']['deadline'] = $this->buildDateRangeFieldset(
        $definitions['deadline']['label'] ?? $this->t('Deadline'),
        ['filters', 'deadline'],
        $deadline,
        5,
        'rw-moderation-filter-deadline'
      );
      $attached = TRUE;
    }

    if ($attached) {
      $form['#attached']['library'][] = 'reliefweb_form/widget.datepicker';
      $form['#attached']['library'][] = 'common_design_subtheme/rw-datepicker';
    }
  }

  /**
   * Build a from/to date fieldset with datepicker attributes.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   Fieldset title.
   * @param array $parents
   *   Form parents.
   * @param array $values
   *   Current from/to values from user input.
   * @param int $weight
   *   Form weight.
   * @param string $wrapper_class
   *   Extra wrapper class.
   *
   * @return array
   *   Form element.
   */
  protected function buildDateRangeFieldset(string|TranslatableMarkup $title, array $parents, array $values, int $weight, string $wrapper_class): array {
    $from = is_string($values['from'] ?? NULL) ? $values['from'] : '';
    $to = is_string($values['to'] ?? NULL) ? $values['to'] : '';
    $placeholder = $this->t('YYYY/MM/DD');

    return [
      '#type' => 'fieldset',
      '#title' => $title,
      '#tree' => TRUE,
      '#parents' => $parents,
      '#weight' => $weight,
      '#attributes' => [
        'class' => [
          $wrapper_class,
          'rw-moderation-filter-date',
          'rw-moderation-filter-group',
        ],
      ],
      'from' => [
        '#type' => 'textfield',
        '#title' => $this->t('From'),
        '#size' => 28,
        '#default_value' => $from,
        '#attributes' => [
          'data-with-datepicker' => 'optional',
          'placeholder' => $placeholder,
          'autocomplete' => 'off',
        ],
      ],
      'to' => [
        '#type' => 'textfield',
        '#title' => $this->t('To'),
        '#size' => 28,
        '#default_value' => $to,
        '#attributes' => [
          'data-with-datepicker' => 'optional',
          'placeholder' => $placeholder,
          'autocomplete' => 'off',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getAutocompleteUrl(FormStateInterface $form_state, ModerationServiceInterface $service) {
    $build_info = $form_state->getBuildInfo();
    $user = $build_info['args'][1] ?? NULL;
    $bundle = $build_info['args'][2] ?? NULL;
    if ($user instanceof UserInterface && is_string($bundle) && $bundle !== '') {
      return Url::fromRoute('reliefweb_user_posts.autocomplete', [
        'user' => $user->id(),
        'bundle' => $bundle,
      ])->toString();
    }
    return '';
  }

}
