<?php

namespace Drupal\reliefweb_moderation\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_moderation\Services\ReportModeration;
use Drupal\reliefweb_moderation\Services\JobModeration;
use Drupal\reliefweb_moderation\Services\TrainingModeration;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for configuring posting rights status mapping.
 */
class PostingRightsStatusMappingForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The report moderation service.
   *
   * @var \Drupal\reliefweb_moderation\Services\ReportModeration
   */
  protected $reportModeration;

  /**
   * The job moderation service.
   *
   * @var \Drupal\reliefweb_moderation\Services\JobModeration
   */
  protected $jobModeration;

  /**
   * The training moderation service.
   *
   * @var \Drupal\reliefweb_moderation\Services\TrainingModeration
   */
  protected $trainingModeration;

  /**
   * The user posting rights manager service.
   *
   * @var \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface
   */
  protected $userPostingRightsManager;

  /**
   * Constructs a PostingRightsStatusMappingForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\reliefweb_moderation\Services\ReportModeration $report_moderation
   *   The report moderation service.
   * @param \Drupal\reliefweb_moderation\Services\JobModeration $job_moderation
   *   The job moderation service.
   * @param \Drupal\reliefweb_moderation\Services\TrainingModeration $training_moderation
   *   The training moderation service.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $user_posting_rights_manager
   *   The user posting rights manager service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ReportModeration $report_moderation,
    JobModeration $job_moderation,
    TrainingModeration $training_moderation,
    UserPostingRightsManagerInterface $user_posting_rights_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->reportModeration = $report_moderation;
    $this->jobModeration = $job_moderation;
    $this->trainingModeration = $training_moderation;
    $this->userPostingRightsManager = $user_posting_rights_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('reliefweb_moderation.report.moderation'),
      $container->get('reliefweb_moderation.job.moderation'),
      $container->get('reliefweb_moderation.training.moderation'),
      $container->get('reliefweb_moderation.user_posting_rights')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'posting_rights_status_mapping_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'reliefweb_moderation/posting_rights_mapping';

    $form['description'] = [
      '#markup' => '<div class="posting-rights-description">
        <p>' . $this->t("Configure the moderation status mapping for different posting rights scenarios. Each scenario represents the user's posting rights across the selected sources when creating or editing content (whether single or multiple sources).") . '</p>
        <p><strong>' . $this->t('How it works:') . '</strong></p>
        <ul>
          <li>' . $this->t('When a user creates or edits content, the system checks their posting rights for each selected source') . '</li>
          <li>' . $this->t('Based on the combination of rights (blocked, trusted, allowed, unverified), a scenario is determined') . '</li>
          <li>' . $this->t('The corresponding moderation status is then applied to the content') . '</li>
        </ul>
        <p>' . $this->t('The table shows 7 scenarios based on the user posting rights for the selected sources. For each column:') . '</p>
        <ul>
          <li><strong>✓</strong> ' . $this->t('means there is at least one selected source for which the user has this right') . '</li>
          <li><strong>-</strong> ' . $this->t('means there are no selected sources for which the user has this right') . '</li>
          <li><strong>?</strong> ' . $this->t('means that there may be some selected sources for which the user has this right, but this does not affect the scenario') . '</li>
        </ul>
        <p>' . $this->t('Each role with posting rights has its own table. If a role cannot create a specific content type, the corresponding column shows N/A.') . '</p>
      </div>',
    ];

    // Get the current mapping from the user posting rights manager.
    $current_mapping = $this->userPostingRightsManager->getUserPostingRightsToModerationStatusMapping();

    // Define the scenarios based on our analysis.
    $scenarios = [
      'blocked' => [
        'label' => $this->t('Blocked'),
        'blocked' => '✓',
        'trusted' => '?',
        'allowed' => '?',
        'unverified' => '?',
      ],
      'trusted_all' => [
        'label' => $this->t('Trusted All'),
        'blocked' => '-',
        'trusted' => '✓',
        'allowed' => '-',
        'unverified' => '-',
      ],
      'trusted_some_allowed' => [
        'label' => $this->t('Trusted + Allowed'),
        'blocked' => '-',
        'trusted' => '✓',
        'allowed' => '✓',
        'unverified' => '-',
      ],
      'trusted_some_unverified' => [
        'label' => $this->t('Trusted + Unverified'),
        'blocked' => '-',
        'trusted' => '✓',
        'allowed' => '?',
        'unverified' => '✓',
      ],
      'allowed_all' => [
        'label' => $this->t('Allowed All'),
        'blocked' => '-',
        'trusted' => '-',
        'allowed' => '✓',
        'unverified' => '-',
      ],
      'allowed_some_unverified' => [
        'label' => $this->t('Allowed + Unverified'),
        'blocked' => '-',
        'trusted' => '-',
        'allowed' => '✓',
        'unverified' => '✓',
      ],
      'unverified_all' => [
        'label' => $this->t('Unverified All'),
        'blocked' => '-',
        'trusted' => '-',
        'allowed' => '-',
        'unverified' => '✓',
      ],
    ];

    // Get available statuses for each content type.
    $report_statuses = $this->reportModeration->getStatuses();
    $job_statuses = $this->jobModeration->getStatuses();
    $training_statuses = $this->trainingModeration->getStatuses();

    // Get roles with posting rights.
    $roles_with_posting_rights = $this->getRolesWithPostingRights();

    if (empty($roles_with_posting_rights)) {
      $form['no_roles'] = [
        '#markup' => $this->t('No roles found with posting rights permission.'),
      ];
      return $form;
    }

    // Create a table for each role with posting rights.
    foreach ($roles_with_posting_rights as $role_id => $role) {
      $form['role_' . $role_id] = [
        '#type' => 'fieldset',
        '#title' => $this->t('@role Role', ['@role' => $role->label()]),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
        '#tree' => TRUE,
      ];

      $form['role_' . $role_id]['mapping'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Scenario'),
          $this->t('Blocked'),
          $this->t('Trusted'),
          $this->t('Allowed'),
          $this->t('Unverified'),
          $this->t('Report Status'),
          $this->t('Job Status'),
          $this->t('Training Status'),
        ],
        '#attributes' => ['class' => ['posting-rights-mapping-table']],
      ];

      foreach ($scenarios as $scenario_key => $scenario) {
        $row_key = 'scenario_' . $scenario_key;

        // Scenario label column.
        $form['role_' . $role_id]['mapping'][$row_key]['scenario'] = [
          '#type' => 'item',
          '#markup' => $scenario['label'],
          '#wrapper_attributes' => ['class' => ['scenario-cell']],
        ];

        // Blocked column.
        $form['role_' . $role_id]['mapping'][$row_key]['blocked'] = [
          '#type' => 'item',
          '#markup' => $scenario['blocked'],
          '#wrapper_attributes' => ['class' => ['rights-cell', 'symbol-' . $this->getSymbolClass($scenario['blocked'])]],
        ];

        // Trusted column.
        $form['role_' . $role_id]['mapping'][$row_key]['trusted'] = [
          '#type' => 'item',
          '#markup' => $scenario['trusted'],
          '#wrapper_attributes' => ['class' => ['rights-cell', 'symbol-' . $this->getSymbolClass($scenario['trusted'])]],
        ];

        // Allowed column.
        $form['role_' . $role_id]['mapping'][$row_key]['allowed'] = [
          '#type' => 'item',
          '#markup' => $scenario['allowed'],
          '#wrapper_attributes' => ['class' => ['rights-cell', 'symbol-' . $this->getSymbolClass($scenario['allowed'])]],
        ];

        // Unverified column.
        $form['role_' . $role_id]['mapping'][$row_key]['unverified'] = [
          '#type' => 'item',
          '#markup' => $scenario['unverified'],
          '#wrapper_attributes' => [
            'class' => ['rights-cell', 'symbol-' . $this->getSymbolClass($scenario['unverified'])],
          ],
        ];

        // Report status column - check if role can create reports.
        if ($this->roleCanCreateContentType($role, 'report')) {
          $form['role_' . $role_id]['mapping'][$row_key]['report_status'] = [
            '#type' => 'select',
            '#options' => $report_statuses,
            '#default_value' => $current_mapping[$role_id]['report'][$scenario_key] ?? 'draft',
            '#required' => TRUE,
          ];
        }
        else {
          $form['role_' . $role_id]['mapping'][$row_key]['report_status'] = [
            '#type' => 'item',
            '#markup' => 'N/A',
            '#wrapper_attributes' => ['class' => ['na-cell']],
          ];
        }

        // Job status column - check if role can create jobs.
        if ($this->roleCanCreateContentType($role, 'job')) {
          $form['role_' . $role_id]['mapping'][$row_key]['job_status'] = [
            '#type' => 'select',
            '#options' => $job_statuses,
            '#default_value' => $current_mapping[$role_id]['job'][$scenario_key] ?? 'draft',
            '#required' => TRUE,
          ];
        }
        else {
          $form['role_' . $role_id]['mapping'][$row_key]['job_status'] = [
            '#type' => 'item',
            '#markup' => 'N/A',
            '#wrapper_attributes' => ['class' => ['na-cell']],
          ];
        }

        // Training status column - check if role can create training.
        if ($this->roleCanCreateContentType($role, 'training')) {
          $form['role_' . $role_id]['mapping'][$row_key]['training_status'] = [
            '#type' => 'select',
            '#options' => $training_statuses,
            '#default_value' => $current_mapping[$role_id]['training'][$scenario_key] ?? 'draft',
            '#required' => TRUE,
          ];
        }
        else {
          $form['role_' . $role_id]['mapping'][$row_key]['training_status'] = [
            '#type' => 'item',
            '#markup' => 'N/A',
            '#wrapper_attributes' => ['class' => ['na-cell']],
          ];
        }
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    return $form;
  }

  /**
   * Get CSS class name for a symbol.
   *
   * @param string $symbol
   *   The symbol (✓, ?, -).
   *
   * @return string
   *   The CSS class name.
   */
  protected function getSymbolClass($symbol) {
    $classes = [
      '✓' => 'some',
      '?' => 'maybe',
      '-' => 'none',
    ];

    return $classes[$symbol] ?? 'none';
  }

  /**
   * Get roles that have the 'apply posting rights' permission.
   *
   * @return array
   *   Array of role objects with posting rights.
   */
  protected function getRolesWithPostingRights() {
    $roles = [];
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    $all_roles = $role_storage->loadMultiple();

    foreach ($all_roles as $role) {
      // Skip the administrator role.
      if ($role->id() === 'administrator') {
        continue;
      }

      if ($role->hasPermission('apply posting rights')) {
        $roles[$role->id()] = $role;
      }
    }

    return $roles;
  }

  /**
   * Check if a role has permission to create a specific content type.
   *
   * @param \Drupal\user\Entity\Role $role
   *   The role to check.
   * @param string $content_type
   *   The content type (report, job, training).
   *
   * @return bool
   *   TRUE if the role can create the content type, FALSE otherwise.
   */
  protected function roleCanCreateContentType($role, $content_type) {
    $permission = 'create ' . $content_type . ' content';
    return $role->hasPermission($permission);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mapping = [];

    // Get roles with posting rights.
    $roles_with_posting_rights = $this->getRolesWithPostingRights();

    // Extract the mapping from the form values for each role.
    foreach ($roles_with_posting_rights as $role_id => $role) {
      $role_mapping = [
        'report' => [],
        'job' => [],
        'training' => [],
      ];

      $form_values = $form_state->getValue('role_' . $role_id);
      if (isset($form_values['mapping'])) {
        foreach ($form_values['mapping'] as $row_key => $row_data) {
          if (strpos($row_key, 'scenario_') === 0) {
            // Remove 'scenario_' prefix.
            $scenario_key = substr($row_key, 9);

            // Only save values for content types the role can create.
            if ($this->roleCanCreateContentType($role, 'report') && isset($row_data['report_status'])) {
              $role_mapping['report'][$scenario_key] = $row_data['report_status'];
            }
            if ($this->roleCanCreateContentType($role, 'job') && isset($row_data['job_status'])) {
              $role_mapping['job'][$scenario_key] = $row_data['job_status'];
            }
            if ($this->roleCanCreateContentType($role, 'training') && isset($row_data['training_status'])) {
              $role_mapping['training'][$scenario_key] = $row_data['training_status'];
            }
          }
        }
      }

      $mapping[$role_id] = $role_mapping;
    }

    // Save the mapping using the user posting rights manager.
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);

    $this->messenger()->addStatus($this->t('Posting rights status mapping has been saved.'));
  }

}
