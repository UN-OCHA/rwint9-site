<?php

namespace Drupal\reliefweb_users\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manage subscription for user.
 */
class UserPageFilterForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_users_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AccountInterface $user = NULL) {
    // Get filter values and options.
    $filters = $this->getFilters($form_state);
    $roles = $this->getRoles();
    $statuses = $this->getStatuses();
    $rights = $this->getRights();
    $content_types = $this->getContentTypes();

    $form_state->setStorage([
      'filters' => $filters,
      'roles' => $roles,
      'statuses' => $statuses,
      'rights' => $rights,
      'content_types' => $content_types,
    ]);

    $form['filters'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Filters'),
    ];

    $form['filters']['role'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Role'),
      '#options' => $roles,
      '#default_value' => $filters['role'] ?? [],
    ];

    $form['filters']['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Status'),
      '#options' => ['any' => 'Any'] + $statuses,
      '#default_value' => $filters['status'] ?? 'any',
    ];

    $form['filters']['posted'] = [
      '#title' => $this->t('Posted'),
      '#type' => 'checkboxes',
      '#options' => $content_types,
      '#default_value' => array_keys($filters['posted'] ?? []),
    ];

    $form['filters']['job_rights'] = [
      '#type' => 'radios',
      '#title' => $this->t('Job posting rights'),
      '#options' => ['any' => 'Any'] + $rights,
      '#default_value' => $filters['job_rights'] ?? 'any',
    ];

    $form['filters']['training_rights'] = [
      '#type' => 'radios',
      '#title' => $this->t('Training posting rights'),
      '#options' => ['any' => 'Any'] + $rights,
      '#default_value' => $filters['training_rights'] ?? 'any',
    ];

    $form['filters']['report_rights'] = [
      '#type' => 'radios',
      '#title' => $this->t('Report posting rights'),
      '#options' => ['any' => 'Any'] + $rights,
      '#default_value' => $filters['report_rights'] ?? 'any',
    ];

    $form['filters']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#maxlength' => 2048,
      '#default_value' => $filters['name'] ?? '',
    ];

    $form['filters']['mail'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mail'),
      '#maxlength' => 2048,
      '#default_value' => $filters['mail'] ?? '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#theme_wrappers' => [
        'fieldset' => [
          '#id' => 'actions',
          '#title' => $this->t('Form actions'),
          '#title_display' => 'invisible',
        ],
      ],
      '#weight' => 99,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'submit',
      '#name' => 'reset',
      '#value' => $this->t('Reset'),
      '#submit' => ['::resetForm'],
    ];
    $form['actions']['create'] = [
      '#type' => 'link',
      '#url' => Url::fromRoute('user.admin_create', [], [
        'attributes' => ['target' => '_blank'],
      ]),
      '#title' => $this->t('Create user'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function resetForm(array &$form, FormStateInterface $form_state) {
    $form_state->setProgrammed(FALSE);
    $form_state->setRedirect('<current>');
  }

  /**
   * Get and cache the filters for the users admin page.
   */
  protected function getFilters(FormStateInterface $form_state) {
    static $filters;

    if (!isset($filters)) {
      $inputs = $form_state->getUserInput();

      $roles = $this->getRoles();
      $statuses = $this->getStatuses();
      $rights = $this->getRights();
      $content_types = $this->getContentTypes();

      $filters = [
        'role' => !empty($inputs['role']) ? array_intersect_key($roles, $inputs['role']) : NULL,
        'status' => isset($inputs['status'], $statuses[$inputs['status']]) ? $inputs['status'] : NULL,
        'name' => !empty($inputs['name']) && is_string($inputs['name']) ? $inputs['name'] : NULL,
        'mail' => !empty($inputs['mail']) && is_string($inputs['mail']) ? $inputs['mail'] : NULL,
        'posted' => isset($inputs['posted']) && is_array($inputs['posted']) ? array_intersect_key($content_types, $inputs['posted']) : NULL,
        'job_rights' => isset($inputs['job_rights'], $rights[$inputs['job_rights']]) ? $inputs['job_rights'] : NULL,
        'training_rights' => isset($inputs['training_rights'], $rights[$inputs['training_rights']]) ? $inputs['training_rights'] : NULL,
        'report_rights' => isset($inputs['report_rights'], $rights[$inputs['report_rights']]) ? $inputs['report_rights'] : NULL,
      ];
    }

    return $filters;
  }

  /**
   * Get the list of user roles keyed by id.
   */
  protected function getRoles() {
    static $roles;

    if (!isset($roles)) {
      $role_objects = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
      $roles = array_combine(array_keys($role_objects), array_map(function ($a) {
        return $a->label();
      }, $role_objects));
      // Remove the anonymous and authenticated user roles as they are not
      // useful to filter the list of user accounts.
      unset($roles['anonymous']);
      unset($roles['authenticated']);
      // Remove the job importer role as it's an internal role used by the
      // job importer.
      // @todo remove if we remove the job importer role.
      unset($roles['job_importer']);
    }

    return $roles;
  }

  /**
   * Get the list of content types.
   */
  protected function getContentTypes() {
    return [
      'job' => $this->t('Job'),
      'training' => $this->t('Training'),
      'report' => $this->t('Report'),
    ];
  }

  /**
   * Get the list of possible user statuses.
   */
  protected function getStatuses() {
    return [
      'blocked' => $this->t('Blocked'),
      'active' => $this->t('Active'),
    ];
  }

  /**
   * Get the list of possible user statuses.
   */
  protected function getRights() {
    return [
      'unverified' => $this->t('Unverified'),
      'blocked' => $this->t('Blocked'),
      'allowed' => $this->t('Allowed'),
      'trusted' => $this->t('Trusted'),
    ];
  }

}
