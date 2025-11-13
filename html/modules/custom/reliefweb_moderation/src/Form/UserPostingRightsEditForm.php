<?php

namespace Drupal\reliefweb_moderation\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Controller\SourceAutocompleteController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing user posting rights.
 */
class UserPostingRightsEditForm extends PostingRightsEditFormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('reliefweb_moderation.user_posting_rights')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_moderation_user_posting_rights_edit_form';
  }

  /**
   * Title callback for the route.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user parameter from the route.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public static function getTitle(AccountInterface $user): TranslatableMarkup {
    return t('Edit Posting Rights for %name (@email)', [
      '%name' => $user->getDisplayName(),
      '@email' => $user->getEmail(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AccountInterface $user = NULL): array {
    // Store the user for use in other methods.
    $form_state->set('user', $user);

    // Get user posting rights and domain posting rights separately.
    $user_sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser($user);
    $domain_sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser($user);

    // Build the existing rights table.
    $user_entity = $this->entityTypeManager->getStorage('user')->load($user->id());
    $user_display_name = $user_entity ? $user_entity->getDisplayName() : $user->getAccountName();
    $user_email = $user_entity ? $user_entity->getEmail() : '';

    $form['rights'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Posting Rights for %name (@email)', [
        '%name' => $user_display_name,
        '@email' => $user_email,
      ]),
      '#weight' => 0,
      '#tree' => TRUE,
    ];

    // Add informational box before the table.
    $form['rights']['info'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['rw-posting-rights-info-box', 'messages', 'messages--warning'],
      ],
      '#weight' => -1,
    ];
    $form['rights']['info']['content'] = [
      '#type' => 'inline_template',
      '#template' => '<ul><li>{{ domain_rights_info }}</li><li>{{ precedence_info }}</li></ul>',
      '#context' => [
        'domain_rights_info' => $this->t('Domain posting rights apply to all users sharing the same email domain. Any changes will affect everyone with that domain.'),
        'precedence_info' => $this->t('For the same source, user-specific posting rights take precedence over domain posting rights.'),
      ],
    ];

    $rights_options = $this->getRightsOptions();

    // Build table header.
    $form['rights']['table'] = [
      '#type' => 'table',
      '#header' => [
        [
          'data' => $this->t('Source'),
          'colspan' => 2,
        ],
        $this->t('Type'),
        $this->t('Report'),
        $this->t('Job'),
        $this->t('Training'),
        $this->t('Remove'),
      ],
      '#attributes' => [
        'class' => ['rw-user-posting-rights-edit-table'],
      ],
    ];

    // Load all sources at once for efficiency.
    $all_source_ids = array_unique(array_merge(array_keys($user_sources), array_keys($domain_sources)));
    $sources = $this->loadSourcesByIds($all_source_ids);

    // Process existing sources that have user or domain rights.
    foreach ($sources as $source) {
      $source_id = $source->id();

      // Get source name and shortname separately.
      $source_link = $this->buildSourceLink($source);
      $shortname = $this->getSourceShortname($source, '-');

      // Add row for user posting rights if they exist.
      if (isset($user_sources[$source_id])) {
        $user_data = $user_sources[$source_id];
        $row_key = 'user_' . $source_id;

        $form['rights']['table'][$row_key]['source'] = $source_link;
        $form['rights']['table'][$row_key]['shortname'] = [
          '#markup' => $shortname ?: '-',
        ];
        $form['rights']['table'][$row_key]['type'] = [
          '#type' => 'inline_template',
          '#template' => '<span class="{{ class }}">{{ text }}</span>',
          '#context' => [
            'text' => $this->t('User'),
            'class' => 'rw-user-posting-rights-edit-type rw-user-posting-rights-edit-type--user',
          ],
        ];

        $form['rights']['table'][$row_key]['report'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $user_data['report'] ?? 0,
        ];

        $form['rights']['table'][$row_key]['job'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $user_data['job'] ?? 0,
        ];

        $form['rights']['table'][$row_key]['training'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $user_data['training'] ?? 0,
        ];

        $form['rights']['table'][$row_key]['remove'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Remove'),
          '#title_display' => 'invisible',
          '#default_value' => FALSE,
        ];
      }

      // Add row for domain posting rights if they exist.
      if (isset($domain_sources[$source_id])) {
        $domain_data = $domain_sources[$source_id];
        $row_key = 'domain_' . $source_id;

        $form['rights']['table'][$row_key]['source'] = $source_link;
        $form['rights']['table'][$row_key]['shortname'] = [
          '#markup' => $shortname ?: '-',
        ];
        $form['rights']['table'][$row_key]['type'] = [
          '#type' => 'inline_template',
          '#template' => '<span class="{{ class }}">{{ text }}</span>',
          '#context' => [
            'text' => $this->t('Domain'),
            'class' => 'rw-user-posting-rights-edit-type rw-user-posting-rights-edit-type--domain',
          ],
        ];

        $form['rights']['table'][$row_key]['report'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $domain_data['report'] ?? 0,
        ];

        $form['rights']['table'][$row_key]['job'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $domain_data['job'] ?? 0,
        ];

        $form['rights']['table'][$row_key]['training'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $domain_data['training'] ?? 0,
        ];

        $form['rights']['table'][$row_key]['remove'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Remove'),
          '#title_display' => 'invisible',
          '#default_value' => FALSE,
        ];
      }
    }

    // Get the number of new rows from form state or default to 0.
    $new_rows_count = $form_state->get('new_rows_count', 0);
    $removed_new_rows = $form_state->get('removed_new_rows') ?? [];

    // Add new rows if any.
    for ($i = 0; $i < $new_rows_count; $i++) {
      $row_key = 'new_' . $i;

      // Skip removed new rows.
      if (in_array($row_key, $removed_new_rows, TRUE)) {
        continue;
      }

      // Source column, spans 2 columns.
      $form['rights']['table'][$row_key]['source'] = $this->buildNewRowSourceField($row_key, $rights_options);

      // Type column.
      $form['rights']['table'][$row_key]['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#title_display' => 'invisible',
        '#options' => [
          'user' => $this->t('User'),
          'domain' => $this->t('Domain'),
        ],
        '#default_value' => 'user',
      ];

      // Rights columns.
      $rights_fields = $this->buildRightsSelectFields($rights_options);
      $form['rights']['table'][$row_key]['report'] = $rights_fields['report'];
      $form['rights']['table'][$row_key]['job'] = $rights_fields['job'];
      $form['rights']['table'][$row_key]['training'] = $rights_fields['training'];

      // Remove column.
      $form['rights']['table'][$row_key]['remove'] = $this->buildRemoveButton($row_key, 'user-posting-rights-table-wrapper');
    }

    // Add wrapper for AJAX updates.
    $form['rights']['table']['#prefix'] = '<div id="user-posting-rights-table-wrapper">';
    $form['rights']['table']['#suffix'] = '</div>';

    // Add "Add another item" button.
    $form['rights']['add_more'] = $this->buildAddMoreButton('user-posting-rights-table-wrapper');

    $form['actions'] = $this->buildFormActions($this->getRedirectUrl($user));

    $form['#attached']['library'][] = 'common_design_subtheme/rw-posting-rights-edit-form';
    $form['#attributes']['class'][] = 'rw-posting-rights-edit-form';
    $form['#attributes']['class'][] = 'rw-posting-rights-edit-form--user';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $user = $form_state->get('user');
    $rights = $form_state->getValue(['rights', 'table'], []);

    // Validate new rows.
    if (!empty($rights)) {
      $new_rows_count = $form_state->get('new_rows_count', 0);
      $removed_new_rows = $form_state->get('removed_new_rows') ?? [];
      for ($i = 0; $i < $new_rows_count; $i++) {
        $row_key = 'new_' . $i;

        // Skip removed new rows.
        if (in_array($row_key, $removed_new_rows, TRUE)) {
          continue;
        }

        if (!isset($rights[$row_key])) {
          continue;
        }

        $row_data = $rights[$row_key];

        // Validate source if provided.
        if (!empty($row_data['source'])) {
          $source_input = $row_data['source'];
          $source_id = SourceAutocompleteController::extractSourceIdFromInput($source_input);

          if (!$source_id) {
            $form_state->setErrorByName("rights][table][{$row_key}][source", $this->t('Invalid source selected. Please select a source from the autocomplete suggestions.'));
            continue;
          }

          // Load the source.
          $source = $this->entityTypeManager->getStorage('taxonomy_term')->load($source_id);
          if (!$source) {
            $form_state->setErrorByName("rights][table][{$row_key}][source", $this->t('Invalid source selected. Source with ID: @source_id not found.', ['@source_id' => $source_id]));
            continue;
          }

          $type = $row_data['type'] ?? 'user';

          // Check if user already has rights for this source.
          if ($type === 'user') {
            // Check for existing user posting rights.
            if ($source->hasField('field_user_posting_rights')) {
              foreach ($source->field_user_posting_rights as $item) {
                if ($item->id == $user->id()) {
                  $form_state->setErrorByName("rights][table][{$row_key}][source", $this->t('User already has user rights for the organization @source.', [
                    '@source' => $source->label(),
                  ]));
                  break;
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
              $form_state->setErrorByName("rights][table][{$row_key}][type", $this->t('Cannot extract domain from user email address.'));
              continue;
            }

            // Check for existing domain posting rights.
            if ($source->hasField('field_domain_posting_rights')) {
              foreach ($source->field_domain_posting_rights as $item) {
                if (mb_strtolower(trim($item->domain)) === $domain) {
                  $form_state->setErrorByName("rights][table][{$row_key}][source", $this->t('User already has domain rights for the organization @source with domain @domain.', [
                    '@source' => $source->label(),
                    '@domain' => $domain,
                  ]));
                  break;
                }
              }
            }
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
    $rights = $form_state->getValue(['rights', 'table'], []);

    if (empty($rights)) {
      $this->messenger()->addStatus($this->t('No changes were made.'));
      $form_state->setRedirectUrl($this->getRedirectUrl($user));
      return;
    }

    // Get existing sources.
    $user_sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser($user);
    $domain_sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser($user);

    // Build maps of existing rights.
    $existing_user_source_ids = array_keys($user_sources);
    $existing_domain_source_ids = array_keys($domain_sources);

    // Get user entity and domain.
    $user_entity = $this->entityTypeManager->getStorage('user')->load($user->id());
    $user_domain = NULL;
    if ($user_entity && $user_entity->getEmail()) {
      $user_domain = $this->extractDomainFromEmail($user_entity->getEmail());
    }

    $removed_new_rows = $form_state->get('removed_new_rows') ?? [];

    $counts = [
      'updated' => 0,
      'removed' => 0,
      'added' => 0,
    ];

    // Process all rows in the table.
    foreach ($rights as $row_key => $row_data) {
      $this->processRow($row_key, $row_data, $user, $user_domain, $existing_user_source_ids, $existing_domain_source_ids, $removed_new_rows, $counts);
    }

    // Show success message.
    $this->addSuccessMessages($counts['updated'], $counts['removed'], $counts['added']);

    // Redirect back to the user canonical URL.
    $form_state->setRedirectUrl($this->getRedirectUrl($user));
  }

  /**
   * Process a single row from the form.
   *
   * @param string|int $row_key
   *   The row key.
   * @param array $row_data
   *   The row data.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param string|null $user_domain
   *   The user's email domain.
   * @param array $existing_user_source_ids
   *   Array of existing user source IDs.
   * @param array $existing_domain_source_ids
   *   Array of existing domain source IDs.
   * @param array $removed_new_rows
   *   Array of removed new row keys.
   * @param array &$counts
   *   Reference to counts array (updated, removed, added).
   */
  protected function processRow($row_key, array $row_data, AccountInterface $user, ?string $user_domain, array $existing_user_source_ids, array $existing_domain_source_ids, array $removed_new_rows, array &$counts): void {
    // Determine if this is a new row or an existing one.
    $is_new_row = is_string($row_key) && strpos($row_key, 'new_') === 0;

    // Skip removed new rows.
    if ($is_new_row && in_array($row_key, $removed_new_rows, TRUE)) {
      return;
    }

    $report_rights = (int) ($row_data['report'] ?? 0);
    $job_rights = (int) ($row_data['job'] ?? 0);
    $training_rights = (int) ($row_data['training'] ?? 0);

    // Determine the type and source ID.
    [$type, $source_id] = $this->parseRowKey($row_key, $row_data, $is_new_row);
    if (!$source_id) {
      return;
    }

    // Load the source.
    $source = $this->entityTypeManager->getStorage('taxonomy_term')->load($source_id);
    if (!$source) {
      return;
    }

    // Determine if this is an existing right.
    $is_existing_user = !$is_new_row && $type === 'user' && in_array($source_id, $existing_user_source_ids, TRUE);
    $is_existing_domain = !$is_new_row && $type === 'domain' && in_array($source_id, $existing_domain_source_ids, TRUE);
    $is_existing = $is_existing_user || $is_existing_domain;

    // Process existing rights.
    if ($is_existing) {
      $this->processExistingRights($source, $row_data, $type, $user, $user_domain, $report_rights, $job_rights, $training_rights, $counts);
    }
    // Process new rights.
    else {
      $this->processNewRights($source, $type, $user, $user_domain, $report_rights, $job_rights, $training_rights, $counts);
    }
  }

  /**
   * Parse row key to extract type and source ID.
   *
   * @param string|int $row_key
   *   The row key.
   * @param array $row_data
   *   The row data.
   * @param bool $is_new_row
   *   Whether this is a new row.
   *
   * @return array
   *   Array with [type, source_id].
   */
  protected function parseRowKey($row_key, array $row_data, bool $is_new_row): array {
    if ($is_new_row) {
      // For new rows, extract source ID and type from form data.
      $source_id = NULL;
      if (!empty($row_data['source'])) {
        $source_id = SourceAutocompleteController::extractSourceIdFromInput($row_data['source']);
      }
      $type = $row_data['type'] ?? 'user';
      return [$type, $source_id];
    }

    // For existing rows, parse the row key (user_123 or domain_123).
    if (strpos($row_key, 'user_') === 0) {
      return ['user', (int) substr($row_key, 5)];
    }
    if (strpos($row_key, 'domain_') === 0) {
      return ['domain', (int) substr($row_key, 7)];
    }

    return [NULL, NULL];
  }

  /**
   * Process existing rights (update or remove).
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param array $row_data
   *   The row data.
   * @param string $type
   *   The type ('user' or 'domain').
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param string|null $user_domain
   *   The user's email domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function processExistingRights($source, array $row_data, string $type, AccountInterface $user, ?string $user_domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    // Check if marked for removal.
    if (!empty($row_data['remove'])) {
      if ($type === 'user') {
        $this->removeUserRights($source, $user, $counts);
      }
      else {
        $this->removeDomainRights($source, $user_domain, $counts);
      }
      return;
    }

    // Update the posting rights.
    if ($type === 'user') {
      $this->updateUserRights($source, $user, $report_rights, $job_rights, $training_rights, $counts);
    }
    else {
      $this->updateDomainRights($source, $user_domain, $report_rights, $job_rights, $training_rights, $counts);
    }
  }

  /**
   * Process new rights (add).
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string $type
   *   The type ('user' or 'domain').
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param string|null $user_domain
   *   The user's email domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function processNewRights($source, string $type, AccountInterface $user, ?string $user_domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    if ($type === 'user') {
      $this->addUserRights($source, $user, $report_rights, $job_rights, $training_rights, $counts);
    }
    else {
      $this->addDomainRights($source, $user_domain, $report_rights, $job_rights, $training_rights, $counts);
    }
  }

  /**
   * Remove user posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function removeUserRights($source, AccountInterface $user, array &$counts): void {
    $user_id = (int) $user->id();
    foreach ($source->get('field_user_posting_rights') as $index => $item) {
      if (isset($item->id) && (int) $item->id === $user_id) {
        $source->get('field_user_posting_rights')->removeItem($index);
        $source->save();
        $counts['removed']++;
        break;
      }
    }
  }

  /**
   * Remove domain posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string|null $user_domain
   *   The user's email domain.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function removeDomainRights($source, ?string $user_domain, array &$counts): void {
    if (empty($user_domain)) {
      return;
    }

    $user_domain = $this->normalizeDomain($user_domain);
    foreach ($source->get('field_domain_posting_rights') as $index => $item) {
      if (isset($item->domain) && $this->normalizeDomain($item->domain) === $user_domain) {
        $source->get('field_domain_posting_rights')->removeItem($index);
        $source->save();
        $counts['removed']++;
        break;
      }
    }
  }

  /**
   * Update user posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function updateUserRights($source, AccountInterface $user, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    $user_id = (int) $user->id();
    foreach ($source->get('field_user_posting_rights') as $item) {
      if (isset($item->id) && (int) $item->id === $user_id) {
        // Only update if the rights have changed.
        if ((int) $item->report !== $report_rights || (int) $item->job !== $job_rights || (int) $item->training !== $training_rights) {
          $item->report = $report_rights;
          $item->job = $job_rights;
          $item->training = $training_rights;
          $source->save();
          $counts['updated']++;
        }
        break;
      }
    }
  }

  /**
   * Update domain posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string|null $user_domain
   *   The user's email domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function updateDomainRights($source, ?string $user_domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    if (empty($user_domain)) {
      return;
    }

    $user_domain = $this->normalizeDomain($user_domain);
    foreach ($source->get('field_domain_posting_rights') as $item) {
      if (isset($item->domain) && $this->normalizeDomain($item->domain) === $user_domain) {
        // Only update if the rights have changed.
        if ((int) $item->report !== $report_rights || (int) $item->job !== $job_rights || (int) $item->training !== $training_rights) {
          $item->report = $report_rights;
          $item->job = $job_rights;
          $item->training = $training_rights;
          $source->save();
          $counts['updated']++;
        }
        break;
      }
    }
  }

  /**
   * Add user posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function addUserRights($source, AccountInterface $user, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    // Check if the source already has rights for this user.
    if ($this->hasUserRights($source, $user)) {
      return;
    }

    $source->get('field_user_posting_rights')->appendItem([
      'id' => (int) $user->id(),
      'job' => $job_rights,
      'training' => $training_rights,
      'report' => $report_rights,
    ]);
    $source->save();
    $counts['added']++;
  }

  /**
   * Add domain posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string|null $user_domain
   *   The user's email domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function addDomainRights($source, ?string $user_domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    if (empty($user_domain)) {
      return;
    }

    // Check if the source already has rights for this domain.
    if ($this->hasDomainRights($source, $user_domain)) {
      return;
    }

    $source->get('field_domain_posting_rights')->appendItem([
      'domain' => $user_domain,
      'job' => $job_rights,
      'training' => $training_rights,
      'report' => $report_rights,
    ]);
    $source->save();
    $counts['added']++;
  }

  /**
   * Check if source has user posting rights for the given user.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   *
   * @return bool
   *   TRUE if the source has rights for this user, FALSE otherwise.
   */
  protected function hasUserRights($source, AccountInterface $user): bool {
    if (!$source->hasField('field_user_posting_rights')) {
      return FALSE;
    }

    $user_id = (int) $user->id();
    foreach ($source->field_user_posting_rights as $item) {
      if (isset($item->id) && (int) $item->id === $user_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if source has domain posting rights for the given domain.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string $user_domain
   *   The user's email domain.
   *
   * @return bool
   *   TRUE if the source has rights for this domain, FALSE otherwise.
   */
  protected function hasDomainRights($source, string $user_domain): bool {
    if (!$source->hasField('field_domain_posting_rights')) {
      return FALSE;
    }

    $user_domain = $this->normalizeDomain($user_domain);
    foreach ($source->field_domain_posting_rights as $item) {
      if (isset($item->domain) && $this->normalizeDomain($item->domain) === $user_domain) {
        return TRUE;
      }
    }
    return FALSE;
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
    return $this->normalizeDomain($domain);
  }

  /**
   * Get the redirect URL for redirecting after saving or cancelling.
   *
   * If no destination is set, the user is redirected to the user canonical URL.
   * If a destination is set, the user is redirected to the destination URL.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   *
   * @return \Drupal\Core\Url
   *   The redirect URL.
   */
  protected function getRedirectUrl(AccountInterface $user): Url {
    $destination_array = $this->getDestinationArray();
    if (!empty($destination_array['destination'])) {
      return Url::fromUserInput($destination_array['destination']);
    }
    return Url::fromRoute('entity.user.canonical', ['user' => $user->id()]);
  }

}
