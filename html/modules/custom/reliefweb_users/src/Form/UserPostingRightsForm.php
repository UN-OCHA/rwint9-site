<?php

namespace Drupal\reliefweb_users\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing user posting rights.
 */
class UserPostingRightsForm extends FormBase {

  /**
   * Constructs a UserPostingRightsForm object.
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
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_users_posting_rights_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AccountInterface $user = NULL): array {
    // Store the user for use in other methods.
    $form_state->set('user', $user);

    // Get user posting rights and domain posting rights separately.
    $user_sources = UserPostingRightsHelper::getSourcesWithUserPostingRightsForUser($user);
    $domain_sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser($user);

    // Build the existing rights table.
    $user_entity = $this->entityTypeManager->getStorage('user')->load($user->id());
    $user_display_name = $user_entity ? $user_entity->getDisplayName() : $user->getAccountName();
    $user_email = $user_entity ? $user_entity->getEmail() : '';

    $form['existing_rights'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Current Posting Rights for %name (@email)', [
        '%name' => $user_display_name,
        '@email' => $user_email,
      ]),
      '#weight' => 0,
    ];

    if (empty($user_sources) && empty($domain_sources)) {
      $form['existing_rights']['empty'] = [
        '#markup' => $this->t('No posting rights found.'),
      ];
    }
    else {
      // Load all sources at once for efficiency.
      $all_source_ids = array_unique(array_merge(array_keys($user_sources), array_keys($domain_sources)));
      $sources = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadMultiple($all_source_ids);

      $header = [
        $this->t('Source'),
        $this->t('Type'),
        $this->t('Job'),
        $this->t('Training'),
        $this->t('Report'),
        $this->t('Edit'),
      ];

      $rows = [];

      // Get current request URL for destination parameter.
      $destination = $this->getRequest()->getRequestUri();

      // Process each source that has either user or domain rights.
      foreach ($all_source_ids as $source_id) {
        $source = $sources[$source_id] ?? NULL;

        if (!$source) {
          continue;
        }

        // Build the source title with shortname if available and different from
        // label.
        $source_title = $source->label();
        if ($source->hasField('field_shortname') && !$source->field_shortname->isEmpty()) {
          $shortname = $source->field_shortname->value;
          if (!empty($shortname) && $shortname !== $source_title) {
            $source_title = $source_title . ' (' . $shortname . ')';
          }
        }

        // Create source link that points to the source page itself.
        $source_link = [
          '#type' => 'link',
          '#title' => $source_title,
          '#url' => $source->toUrl(),
          '#attributes' => [
            'target' => '_blank',
          ],
        ];

        // Add row for user posting rights if they exist.
        if (isset($user_sources[$source_id])) {
          $user_data = $user_sources[$source_id];

          $edit_link = [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute(
              'reliefweb_fields.taxonomy_term.user_posting_rights_form',
              ['taxonomy_term' => $source->id()],
              [
                'fragment' => ':~:text=' . $user->id(),
                'query' => ['destination' => $destination],
              ]
            ),
          ];

          $rows[] = [
            ['data' => $source_link],
            ['data' => $this->formatType('user')],
            ['data' => $this->formatPostingRights($user_data['job'] ?? 0)],
            ['data' => $this->formatPostingRights($user_data['training'] ?? 0)],
            ['data' => $this->formatPostingRights($user_data['report'] ?? 0)],
            ['data' => $edit_link],
          ];
        }

        // Add row for domain posting rights if they exist.
        if (isset($domain_sources[$source_id])) {
          $domain_data = $domain_sources[$source_id];

          // Get user domain for the fragment.
          $user_entity = $this->entityTypeManager->getStorage('user')->load($user->id());
          $user_domain = NULL;
          if ($user_entity && $user_entity->getEmail()) {
            $user_domain = $this->extractDomainFromEmail($user_entity->getEmail());
          }

          $edit_link = [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute(
              'reliefweb_fields.taxonomy_term.domain_posting_rights_form',
              ['taxonomy_term' => $source->id()],
              [
                'fragment' => ':~:text=' . $user_domain,
                'query' => ['destination' => $destination],
              ]
            ),
          ];

          $rows[] = [
            ['data' => $source_link],
            ['data' => $this->formatType('domain')],
            ['data' => $this->formatPostingRights($domain_data['job'] ?? 0)],
            ['data' => $this->formatPostingRights($domain_data['training'] ?? 0)],
            ['data' => $this->formatPostingRights($domain_data['report'] ?? 0)],
            ['data' => $edit_link],
          ];
        }
      }

      $form['existing_rights']['table'] = [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#attached' => [
          'library' => [
            'common_design_subtheme/rw-user-posting-right',
          ],
        ],
      ];
    }

    // Build the add new rights form.
    $form['add_rights'] = [
      '#type' => 'details',
      '#title' => $this->t('Add New Posting Rights'),
      '#weight' => 1,
      '#tree' => TRUE,
      '#open' => FALSE,
    ];

    // Source selection.
    $form['add_rights']['source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source'),
      '#required' => TRUE,
      '#description' => $this->t('Start typing to search for a source organization by name, shortname, or ID.'),
      '#autocomplete_route_name' => 'reliefweb_users.source_autocomplete',
      '#autocomplete_route_parameters' => [],
    ];

    // Type selection.
    $form['add_rights']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => [
        'user' => $this->t('User'),
        'domain' => $this->t('Domain'),
      ],
      '#required' => TRUE,
      '#description' => $this->t('Select whether to add user-specific or domain-based posting rights. When selecting "Domain", the domain will be automatically extracted from the user\'s email address.'),
    ];

    // Rights fields.
    $form['add_rights']['rights'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Posting Rights'),
    ];

    $rights_options = [
      0 => $this->t('Unverified'),
      1 => $this->t('Blocked'),
      2 => $this->t('Allowed'),
      3 => $this->t('Trusted'),
    ];

    $form['add_rights']['rights']['job'] = [
      '#type' => 'select',
      '#title' => $this->t('Job'),
      '#options' => $rights_options,
      '#default_value' => 0,
    ];

    $form['add_rights']['rights']['training'] = [
      '#type' => 'select',
      '#title' => $this->t('Training'),
      '#options' => $rights_options,
      '#default_value' => 0,
    ];

    $form['add_rights']['rights']['report'] = [
      '#type' => 'select',
      '#title' => $this->t('Report'),
      '#options' => $rights_options,
      '#default_value' => 0,
    ];

    $form['add_rights']['actions'] = [
      '#type' => 'actions',
    ];

    $form['add_rights']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Posting Rights'),
    ];

    $form['#attached'] = [
      'library' => [
        'common_design_subtheme/rw-form',
        'common_design_subtheme/rw-user',
      ],
    ];

    $form['#attributes']['class'][] = 'rw-user-posting-rights-form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $user = $form_state->get('user');
    $source_input = $form_state->getValue(['add_rights', 'source']);
    $type = $form_state->getValue(['add_rights', 'type']);

    if (empty($source_input)) {
      return;
    }

    // Extract source ID from autocomplete input.
    $source_id = $this->extractSourceIdFromInput($source_input);
    if (!$source_id) {
      $form_state->setErrorByName('source', $this->t('Invalid source selected. Please select a source from the autocomplete suggestions.'));
      return;
    }

    // Load the source.
    $source = $this->entityTypeManager->getStorage('taxonomy_term')->load($source_id);
    if (!$source) {
      $form_state->setErrorByName('source', $this->t('Invalid source selected.'));
      return;
    }

    // Check if user already has rights for this source.
    if ($type === 'user') {
      // Check for existing user posting rights.
      if ($source->hasField('field_user_posting_rights')) {
        foreach ($source->field_user_posting_rights as $item) {
          if ($item->id == $user->id()) {
            $form_state->setErrorByName('source', $this->t('User already has user rights for the organization @source, please edit it directly or select a different organization.', [
              '@source' => $source->label(),
            ]));
            return;
          }
        }
      }
    }
    else {
      // Extract domain from user's email address.
      $user_entity = $this->entityTypeManager->getStorage('user')->load($user->id());
      $domain = NULL;
      if ($user_entity && $user_entity->getEmail()) {
        $domain = $this->extractDomainFromEmail($user_entity->getEmail());
      }

      if (empty($domain)) {
        $form_state->setErrorByName('type', $this->t('Cannot extract domain from user email address. Please select "User" type instead.'));
        return;
      }

      // Check for existing domain posting rights.
      if ($source->hasField('field_domain_posting_rights')) {
        foreach ($source->field_domain_posting_rights as $item) {
          if ($item->domain === $domain) {
            $form_state->setErrorByName('type', $this->t('User already has domain rights for the organization @source with domain @domain, please edit it directly or select a different organization.', [
              '@source' => $source->label(),
              '@domain' => $domain,
            ]));
            return;
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $form_state->get('user');
    $source_input = $form_state->getValue(['add_rights', 'source']);
    $type = $form_state->getValue(['add_rights', 'type']);
    $rights = $form_state->getValue(['add_rights', 'rights']);

    // Extract source ID from autocomplete input.
    $source_id = $this->extractSourceIdFromInput($source_input);
    if (!$source_id) {
      $this->messenger()->addError($this->t('Invalid source selected.'));
      return;
    }

    // Load the source.
    $source = $this->entityTypeManager->getStorage('taxonomy_term')->load($source_id);

    if ($type === 'user') {
      // Add user posting rights.
      $user_rights = $source->get('field_user_posting_rights')->getValue();
      $user_rights[] = [
        'id' => $user->id(),
        'job' => $rights['job'],
        'training' => $rights['training'],
        'report' => $rights['report'],
      ];
      $source->set('field_user_posting_rights', $user_rights);
    }
    else {
      // Extract domain from user's email address.
      $user_entity = $this->entityTypeManager->getStorage('user')->load($user->id());
      $domain = NULL;
      if ($user_entity && $user_entity->getEmail()) {
        $domain = $this->extractDomainFromEmail($user_entity->getEmail());
      }

      // Add domain posting rights.
      $domain_rights = $source->get('field_domain_posting_rights')->getValue();
      $domain_rights[] = [
        'domain' => $domain,
        'job' => $rights['job'],
        'training' => $rights['training'],
        'report' => $rights['report'],
      ];
      $source->set('field_domain_posting_rights', $domain_rights);
    }

    $source->save();

    $this->messenger()->addStatus($this->t('Posting rights have been added successfully.'));
  }

  /**
   * Extract domain from email address.
   *
   * @param string $email
   *   Email address.
   *
   * @return string|null
   *   Domain part of the email address or null if invalid.
   */
  protected function extractDomainFromEmail(string $email): ?string {
    if (empty($email) || !str_contains($email, '@')) {
      return NULL;
    }

    [, $domain] = explode('@', $email, 2);
    return mb_strtolower(trim($domain));
  }

  /**
   * Extract source ID from autocomplete input.
   *
   * @param string $input
   *   The autocomplete input value.
   *
   * @return int|null
   *   The source taxonomy term ID or null if not found.
   */
  protected function extractSourceIdFromInput(string $input): ?int {
    if (empty($input)) {
      return NULL;
    }

    // Check if the input contains [id:XXX] pattern.
    if (preg_match('/\[id:(\d+)\]$/', $input, $matches)) {
      return (int) $matches[1];
    }

    // If no ID pattern found, try to find by name or shortname.
    try {
      $query = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->getQuery()
        ->condition('vid', 'source')
        ->condition('status', 1)
        ->accessCheck(TRUE);

      // Create an OR condition group for name and shortname.
      $or_group = $query->orConditionGroup();
      $or_group->condition('name', $input);
      $or_group->condition('field_shortname', $input);
      $query->condition($or_group);

      $tids = $query->execute();

      if (!empty($tids)) {
        // Return the first match.
        return (int) reset($tids);
      }
    }
    catch (\Exception $exception) {
      // Log the error but don't expose it to the user.
      $this->getLogger('reliefweb_users')->error('Error extracting source ID: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Formats the type value for display.
   *
   * @param string $type
   *   The type of posting right (user or domain).
   *
   * @return array
   *   A render array for the formatted type.
   */
  protected function formatType(string $type): array {
    $labels = [
      'user' => $this->t('User'),
      'domain' => $this->t('Domain'),
    ];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $labels[$type] ?? $this->t('Unknown'),
      '#attributes' => [
        'class' => ['rw-user-posting-right-type', 'rw-user-posting-right-type--' . $type],
      ],
    ];
  }

  /**
   * Formats the posting rights value for display.
   *
   * @param int $right
   *   The numeric value of the posting right.
   *
   * @return array
   *   A render array for the formatted posting right.
   */
  protected function formatPostingRights(int $right): array {
    $rights = [
      0 => 'unverified',
      1 => 'blocked',
      2 => 'allowed',
      3 => 'trusted',
    ];

    $labels = [
      0 => $this->t('Unverified'),
      1 => $this->t('Blocked'),
      2 => $this->t('Allowed'),
      3 => $this->t('Trusted'),
    ];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $labels[$right] ?? $this->t('unknown'),
      '#attributes' => [
        'class' => ['rw-user-posting-right', 'rw-user-posting-right--large'],
        'data-user-posting-right' => $rights[$right] ?? 'unknown',
      ],
    ];
  }

}
