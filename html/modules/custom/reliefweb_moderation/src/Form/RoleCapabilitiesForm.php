<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for displaying role capabilities summary for content types.
 */
class RoleCapabilitiesForm extends FormBase {

  /**
   * Constructs a RoleCapabilitiesForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'role_capabilities_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'reliefweb_moderation/role_capabilities';

    $form['description'] = [
      '#type' => 'inline_template',
      '#template' => <<<'TEMPLATE'
        <div class="role-capabilities-description">
          <p>{{ 'Set what Contributors, Submitters and Advertisers can do for Reports, Jobs and Trainings.'|t }}</p>
          <p><strong>{{ 'How the options work:'|t }}</strong></p>
          <ul>
            <li><strong>{{ 'No:'|t }}</strong> {{ 'Cannot act on any content.'|t }}</li>
            <li><strong>{{ 'Yes:'|t }}</strong> {{ 'Can act on any content.'|t }}</li>
            <li><strong>{{ 'Affiliated:'|t }}</strong> {{ "Can act on content from the user's affiliated organizations (includes their own)."|t }}</li>
            <li><strong>{{ 'Own:'|t }}</strong> {{ 'Can act only on content the user created.'|t }}</li>
          </ul>
          <p><strong>{{ 'Note:'|t }}</strong> {{ 'The "view published content" capability is inherited from the authenticated role. This page only manages capabilities for Contributors, Submitters and Advertisers; it does not change the editor or authenticated roles.'|t }}</p>
        </div>
        TEMPLATE,
    ];

    // Get all roles that can potentially work with content.
    $roles = $this->getRelevantRoles();

    // Define the content types.
    $content_types = [
      'report' => $this->t('Report'),
      'job' => $this->t('Job'),
      'training' => $this->t('Training'),
    ];

    // Create separate tables for each role.
    foreach ($roles as $role_id => $role_data) {
      $role = $role_data['role'];
      $permissions = $role_data['permissions'] ?? [];

      $form['role_' . $role_id] = [
        '#type' => 'fieldset',
        '#title' => $role->label(),
        '#attributes' => ['class' => ['role-capabilities-fieldset']],
        '#tree' => TRUE,
      ];

      $form['role_' . $role_id]['table'] = $this->buildRoleTable($role, $permissions, $content_types);
    }

    // Add submit button for editable roles.
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Changes'),
    ];

    return $form;
  }

  /**
   * Get relevant roles for the capabilities table.
   *
   * @return array
   *   Array of role objects keyed by role ID with the role entity and its
   *   comprehensive permissions. Ex:
   *   [
   *     'editor' => [
   *       'role' => \Drupal\user\RoleInterface,
   *       'permissions' => [
   *         'permission1' => TRUE,
   *         'permission2' => TRUE,
   *         ...
   *       ],
   *     ],
   *     'contributor' => [
   *       'role' => \Drupal\user\RoleInterface,
   *       'permissions' => [
   *         'permission1' => TRUE,
   *         'permission2' => TRUE,
   *         ...
   *       ],
   *     ],
   *     ...
   *   ];
   */
  protected function getRelevantRoles(): array {
    $role_ids = [
      'authenticated',
      'editor',
      'contributor',
      'submitter',
      'advertiser',
    ];

    $roles = $this->entityTypeManager
      ->getStorage('user_role')
      ->loadMultiple($role_ids);

    // Inherited permissions.
    $base_permissions = [];
    if (isset($roles['authenticated'])) {
      $base_permissions = $roles['authenticated']->getPermissions();
    }

    // Order the roles by the order defined in $role_ids and map them to
    // their comprehensive permissions (including inherited ones).
    $ordered_roles = [];
    foreach ($role_ids as $role_id) {
      if (isset($roles[$role_id])) {
        $role = $roles[$role_id];
        $permissions = array_flip(array_merge($base_permissions, $role->getPermissions()));
        $ordered_roles[$role_id] = [
          'role' => $role,
          'permissions' => $permissions,
        ];
      }
    }

    return $ordered_roles;
  }

  /**
   * Build the table for a role.
   *
   * @param \Drupal\user\RoleInterface $role
   *   The role object.
   * @param array $permissions
   *   The role permissions.
   * @param array $content_types
   *   The content types.
   *
   * @return array
   *   The table form element.
   */
  protected function buildRoleTable(RoleInterface $role, array $permissions, array $content_types) {
    $role_id = $role->id();
    $role_label = $role->label();
    $content_type_keys = array_keys($content_types);

    $header = array_merge([$this->t('Action')], $content_types);

    $simple_options = [
      'no' => $this->t('No'),
      'any' => $this->t('Yes'),
    ];

    if (isset($permissions['apply posting rights'])) {
      $extended_options = [
        'no' => $this->t('No'),
        'any' => $this->t('Yes'),
        'affiliated' => $this->t('Affiliated content only'),
        'own' => $this->t('Own content only'),
      ];
    }
    else {
      $extended_options = $simple_options;
    }

    // Build the table of role capabilities per content type.
    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#attributes' => ['class' => ['role-capabilities-table']],
    ];

    foreach ($this->getCapabilities() as $capability_id => $capability_label) {
      $table[$capability_id]['capability'] = [
        '#type' => 'item',
        '#markup' => $capability_label,
        '#wrapper_attributes' => ['class' => ['capability-cell']],
      ];

      foreach ($content_type_keys as $content_type) {
        // Disable all select elements for the editor and authenticated roles.
        // Their permissions are not managed via this form. They are just
        // references for the other roles.
        $disabled = $role_id === 'editor' || $role_id === 'authenticated';

        switch ($capability_id) {
          case 'view_published':
            $options = $simple_options;
            $value = match (TRUE) {
              isset($permissions['access content']) => 'any',
              default => 'no',
            };
            // This capability is not managed via this form. It is just a
            // reference for the sake of completeness of the role capabilities.
            $disabled = TRUE;
            break;

          case 'view_unpublished':
            $options = $extended_options;
            $value = match (TRUE) {
              isset($permissions['view any ' . $content_type . ' content']) => 'any',
              isset($permissions['view affiliated unpublished ' . $content_type . ' content']) => 'affiliated',
              isset($permissions['view own unpublished ' . $content_type . ' content']) => 'own',
              // This is for editors who can view any content on the site.
              isset($permissions['view any content']) => 'any',
              default => 'no',
            };
            break;

          case 'create_content':
            $options = $simple_options;
            $value = match (TRUE) {
              isset($permissions['create ' . $content_type . ' content']) => 'any',
              default => 'no',
            };
            break;

          case 'edit_content':
            $options = $extended_options;
            $value = match (TRUE) {
              isset($permissions['edit any ' . $content_type . ' content']) => 'any',
              isset($permissions['edit affiliated ' . $content_type . ' content']) => 'affiliated',
              isset($permissions['edit own ' . $content_type . ' content']) => 'own',
              default => 'no',
            };
            break;

          case 'delete_content':
            $options = $extended_options;
            $value = match (TRUE) {
              isset($permissions['delete any ' . $content_type . ' content']) => 'any',
              isset($permissions['delete affiliated ' . $content_type . ' content']) => 'affiliated',
              isset($permissions['delete own ' . $content_type . ' content']) => 'own',
              default => 'no',
            };
            break;

          default:
            $options = $simple_options;
            $value = 'no';
            break;
        }

        $cell = [
          '#type' => 'select',
          '#title' => $this->t('@capability for @content_type for @role', [
            '@capability' => $capability_label,
            '@content_type' => $content_types[$content_type],
            '@role' => $role_label,
          ]),
          '#title_display' => 'invisible',
          '#options' => $options,
          '#default_value' => $value,
          '#wrapper_attributes' => ['class' => ['select-value']],
          '#disabled' => $disabled,
        ];

        $table[$capability_id][$content_type] = $cell;
      }
    }

    return $table;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $roles = $this->getRelevantRoles();
    $content_types = ['report', 'job', 'training'];

    $capabilities = $this->getCapabilities();
    $changed_roles = [];

    foreach ($roles as $role_id => $role_data) {
      if ($role_id === 'editor' || $role_id === 'authenticated') {
        // Skip editor and authenticated roles as their permissions are not
        // managed via this form.
        continue;
      }

      $role = $role_data['role'];
      $changes_made = FALSE;

      // Get table rows for this role.
      $rows = $form_state->getValue(['role_' . $role_id, 'table'], []);
      foreach ($rows as $row_id => $row_data) {
        if (!isset($capabilities[$row_id])) {
          continue;
        }

        // Skip view published capability since it is not managed via this form.
        if ($row_id === 'view_published') {
          continue;
        }

        // Process each content type column in the row.
        foreach ($content_types as $content_type) {
          if (!isset($row_data[$content_type])) {
            continue;
          }

          $cell_permissions = $this->getPermissionsFromCellValue($row_id, $row_data[$content_type], $content_type);

          // Grant or revoke permissions based on the cell value.
          foreach ($cell_permissions as $permission => $grant) {
            if ($role->hasPermission($permission)) {
              if (!$grant) {
                // Important: if the permission was inherited from the
                // authenticated role, revoking it here will not revoke it for
                // the authenticated role so the users will still have the
                // permission.
                $role->revokePermission($permission);
                $changes_made = TRUE;
              }
            }
            else {
              if ($grant) {
                $role->grantPermission($permission);
                $changes_made = TRUE;
              }
            }
          }
        }
      }

      if ($changes_made) {
        try {
          $role->save();
          $changed_roles[$role_id] = $role->label();
        }
        catch (\Exception $exception) {
          $this->messenger()->addError($this->t('Failed to update role permissions: @error', [
            '@error' => $exception->getMessage(),
          ]));
        }
      }
    }

    if (!empty($changed_roles)) {
      $this->messenger()->addStatus($this->t('Role permissions have been updated successfully for the following role(s): @roles', [
        '@roles' => implode(', ', $changed_roles),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('No changes were made to role permissions.'));
    }
  }

  /**
   * Get the capabilities.
   *
   * @return array
   *   Associative array of capabilities with ID as key and label as value.
   */
  protected function getCapabilities(): array {
    return [
      'view_published' => $this->t('View published content'),
      'view_unpublished' => $this->t('View unpublished content'),
      'create_content' => $this->t('Create content'),
      'edit_content' => $this->t('Edit content'),
      'delete_content' => $this->t('Delete content'),
    ];
  }

  /**
   * Get the list of permissions to grant from the cell value.
   *
   * @param string $capability_id
   *   The capability ID.
   * @param string $value
   *   The cell value.
   * @param string $content_type
   *   The content type.
   *
   * @return array
   *   The list of permissions to grant or revoke. The key is the permission and
   *   the value is a boolean indicating whether to grant or revoke the
   *   permission.
   */
  protected function getPermissionsFromCellValue(string $capability_id, string $value, string $content_type): array {
    return match ($capability_id) {
      'view_published' => [
        'access content' => match ($value) {
          'any' => TRUE,
          default => FALSE,
        },
      ],
      'view_unpublished' => [
        'view any ' . $content_type . ' content' => match ($value) {
          'any' => TRUE,
          default => FALSE,
        },
        'view affiliated unpublished ' . $content_type . ' content' => match ($value) {
          'affiliated' => TRUE,
          default => FALSE,
        },
        'view own unpublished ' . $content_type . ' content' => match ($value) {
          'own' => TRUE,
          default => FALSE,
        },
      ],
      'create_content' => [
        'create ' . $content_type . ' content' => match ($value) {
          'any' => TRUE,
          default => FALSE,
        },
      ],
      'edit_content' => [
        'edit any ' . $content_type . ' content' => match ($value) {
          'any' => TRUE,
          default => FALSE,
        },
        'edit affiliated ' . $content_type . ' content' => match ($value) {
          'affiliated' => TRUE,
          default => FALSE,
        },
        'edit own ' . $content_type . ' content' => match ($value) {
          'own' => TRUE,
          default => FALSE,
        },
      ],
      'delete_content' => [
        'delete any ' . $content_type . ' content' => match ($value) {
          'any' => TRUE,
          default => FALSE,
        },
        'delete affiliated ' . $content_type . ' content' => match ($value) {
          'affiliated' => TRUE,
          default => FALSE,
        },
        'delete own ' . $content_type . ' content' => match ($value) {
          'own' => TRUE,
          default => FALSE,
        },
      ],
      default => [],
    };
  }

}
