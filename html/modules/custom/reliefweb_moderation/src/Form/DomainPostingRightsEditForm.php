<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Controller\SourceAutocompleteController;
use Drupal\reliefweb_utility\Helpers\DomainHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing domain posting rights.
 */
class DomainPostingRightsEditForm extends PostingRightsEditFormBase {

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
    return 'reliefweb_moderation_domain_posting_rights_edit_form';
  }

  /**
   * Title callback for the route.
   *
   * @param string $domain
   *   The domain parameter from the route.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public static function getTitle(string $domain): TranslatableMarkup {
    // Normalize domain.
    $domain = DomainHelper::normalizeDomain($domain);
    return \Drupal::translation()->translate('Edit Posting Rights for @domain', ['@domain' => $domain]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $domain = NULL): array {
    // Normalize domain.
    if (!empty($domain)) {
      $domain = DomainHelper::normalizeDomain($domain);
    }

    if (empty($domain)) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error"><p>' . $this->t('Invalid domain.') . '</p></div>',
      ];
      return $form;
    }

    // Store the domain for use in other methods.
    $form_state->set('domain', $domain);

    // Check if the domain is privileged.
    $privileged = $this->userPostingRightsManager->isDomainPrivileged($domain);

    // Add informational box before the table if the domain is privileged.
    if ($privileged) {
      $privileged_domains_url = Url::fromRoute('reliefweb_users.privileged_domains')->toString();

      $form['privileged_domain_info'] = [
        '#type' => 'inline_template',
        '#template' => <<<TEMPLATE
          <div class="rw-posting-rights-privileged-box">
          {%- trans -%}
          The domain <strong>{{ domain }}</strong> is currently in the <a href="{{ url }}" target="_blank">privileged domains list</a>. By default it is considered <strong>allowed</strong> for jobs, training and reports for any source. The organizations listed below have <strong>explicit posting rights</strong> that take precedence over this default.
          {%- endtrans -%}
          </div>
          TEMPLATE,
        '#context' => [
          'domain' => $domain,
          'url' => $privileged_domains_url,
        ],
      ];
    }

    // Get existing domain posting rights for this domain.
    $domain_sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForDomain($domain);

    // Build the rights table.
    $form['rights'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Posting Rights for @domain', [
        '@domain' => $domain,
      ]),
      '#weight' => 0,
      '#tree' => TRUE,
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
        $this->t('Report'),
        $this->t('Job'),
        $this->t('Training'),
        $this->t('Remove'),
      ],
      '#attributes' => [
        'class' => ['rw-domain-posting-rights-edit-table'],
      ],
    ];

    // Process existing sources that have domain rights.
    if (!empty($domain_sources)) {
      // Load all sources at once for efficiency.
      $all_source_ids = array_keys($domain_sources);
      $sources = $this->loadSourcesByIds($all_source_ids);

      foreach ($sources as $source) {
        $source_id = $source->id();

        // Get source name and shortname separately.
        $source_link = $this->buildSourceLink($source);
        $shortname = $this->getSourceShortname($source);

        $domain_data = $domain_sources[$source_id];

        // Build form elements for each right.
        $form['rights']['table'][$source_id]['source'] = $source_link;
        $form['rights']['table'][$source_id]['shortname'] = [
          '#markup' => $shortname,
        ];

        $form['rights']['table'][$source_id]['report'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $domain_data['report'] ?? 0,
        ];
        $form['rights']['table'][$source_id]['job'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $domain_data['job'] ?? 0,
        ];
        $form['rights']['table'][$source_id]['training'] = [
          '#type' => 'select',
          '#options' => $rights_options,
          '#default_value' => $domain_data['training'] ?? 0,
        ];

        $form['rights']['table'][$source_id]['remove'] = [
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

      $form['rights']['table'][$row_key]['source'] = $this->buildNewRowSourceField($row_key, $rights_options);

      $rights_fields = $this->buildRightsSelectFields($rights_options, $privileged);
      $form['rights']['table'][$row_key]['report'] = $rights_fields['report'];
      $form['rights']['table'][$row_key]['job'] = $rights_fields['job'];
      $form['rights']['table'][$row_key]['training'] = $rights_fields['training'];

      $form['rights']['table'][$row_key]['remove'] = $this->buildRemoveButton($row_key, 'domain-posting-rights-table-wrapper');
    }

    // Add wrapper for AJAX updates.
    $form['rights']['table']['#prefix'] = '<div id="domain-posting-rights-table-wrapper">';
    $form['rights']['table']['#suffix'] = '</div>';

    // Add "Add another item" button.
    $form['rights']['add_more'] = $this->buildAddMoreButton('domain-posting-rights-table-wrapper');

    $form['actions'] = $this->buildFormActions($this->getRedirectUrl());

    $form['#attached']['library'][] = 'common_design_subtheme/rw-posting-rights-edit-form';
    $form['#attributes']['class'][] = 'rw-posting-rights-edit-form';
    $form['#attributes']['class'][] = 'rw-posting-rights-edit-form--domain';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $domain = $form_state->get('domain');
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
            $form_state->setErrorByName("rights][table][{$row_key}][source", $this->t('Invalid source selected.'));
            continue;
          }

          // Check for existing domain posting rights.
          if ($source->hasField('field_domain_posting_rights')) {
            foreach ($source->field_domain_posting_rights as $item) {
              if (DomainHelper::normalizeDomain($item->domain) === $domain) {
                $form_state->setErrorByName("rights][table][{$row_key}][source", $this->t('Domain @domain already has posting rights for the organization @source.', [
                  '@domain' => $domain,
                  '@source' => $source->label(),
                ]));
                break;
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
    $domain = $form_state->get('domain');
    $rights = $form_state->getValue(['rights', 'table'], []);

    if (empty($rights)) {
      $this->messenger()->addStatus($this->t('No changes were made.'));
      $form_state->setRedirectUrl($this->getRedirectUrl());
      return;
    }

    // Get existing domain sources.
    $domain_sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForDomain($domain);
    $existing_source_ids = array_keys($domain_sources);
    $removed_new_rows = $form_state->get('removed_new_rows') ?? [];

    $counts = [
      'updated' => 0,
      'removed' => 0,
      'added' => 0,
    ];

    // Process all rows in the table.
    foreach ($rights as $row_key => $row_data) {
      $this->processRow($row_key, $row_data, $domain, $existing_source_ids, $removed_new_rows, $counts);
    }

    // Show success message.
    $this->addSuccessMessages($counts['updated'], $counts['removed'], $counts['added']);

    // Redirect back to the destination URL.
    $form_state->setRedirectUrl($this->getRedirectUrl());
  }

  /**
   * Process a single row from the form.
   *
   * @param string|int $row_key
   *   The row key.
   * @param array $row_data
   *   The row data.
   * @param string $domain
   *   The domain.
   * @param array $existing_source_ids
   *   Array of existing source IDs.
   * @param array $removed_new_rows
   *   Array of removed new row keys.
   * @param array &$counts
   *   Reference to counts array (updated, removed, added).
   */
  protected function processRow($row_key, array $row_data, string $domain, array $existing_source_ids, array $removed_new_rows, array &$counts): void {
    // Determine if this is a new row or an existing one.
    $is_new_row = is_string($row_key) && strpos($row_key, 'new_') === 0;

    // Skip removed new rows.
    if ($is_new_row && in_array($row_key, $removed_new_rows, TRUE)) {
      return;
    }

    $report_rights = (int) ($row_data['report'] ?? 0);
    $job_rights = (int) ($row_data['job'] ?? 0);
    $training_rights = (int) ($row_data['training'] ?? 0);

    // Determine if this is an existing source or a new one.
    $is_existing = !$is_new_row && in_array((int) $row_key, $existing_source_ids, TRUE);

    // Retrieve the source ID.
    $source_id = match ($is_new_row) {
      TRUE => SourceAutocompleteController::extractSourceIdFromInput($row_data['source'] ?? ''),
      FALSE => (int) $row_key,
    };
    if (!$source_id) {
      return;
    }

    // Load the source.
    $source = $this->entityTypeManager->getStorage('taxonomy_term')->load($source_id);
    if (!$source) {
      return;
    }

    // Process existing source.
    if ($is_existing) {
      $this->processExistingSource($source, $row_data, $domain, $report_rights, $job_rights, $training_rights, $counts);
    }
    // Process new source.
    else {
      $this->processNewSource($source, $domain, $report_rights, $job_rights, $training_rights, $counts);
    }
  }

  /**
   * Process an existing source (update or remove).
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param array $row_data
   *   The row data.
   * @param string $domain
   *   The domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function processExistingSource($source, array $row_data, string $domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    // Check if marked for removal.
    if (!empty($row_data['remove'])) {
      $this->removeDomainRights($source, $domain, $counts);
      return;
    }

    // Update the domain posting rights.
    $this->updateDomainRights($source, $domain, $report_rights, $job_rights, $training_rights, $counts);
  }

  /**
   * Process a new source (add).
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string $domain
   *   The domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function processNewSource($source, string $domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    // Check if the source already has rights for this domain.
    // Normally this was already checked in the validateForm() method as
    // well as when we called getSourcesWithDomainPostingRightsForDomain().
    // But we check again here to be sure.
    if ($this->hasDomainRights($source, $domain)) {
      return;
    }

    $this->addDomainRights($source, $domain, $report_rights, $job_rights, $training_rights, $counts);
  }

  /**
   * Check if source has domain posting rights for the given domain.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string $domain
   *   The domain.
   *
   * @return bool
   *   TRUE if the source has rights for this domain, FALSE otherwise.
   */
  protected function hasDomainRights($source, string $domain): bool {
    foreach ($source->get('field_domain_posting_rights') as $item) {
      if (isset($item->domain) && DomainHelper::normalizeDomain($item->domain) === $domain) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Remove domain posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string $domain
   *   The domain.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function removeDomainRights($source, string $domain, array &$counts): void {
    foreach ($source->get('field_domain_posting_rights') as $index => $item) {
      if (isset($item->domain) && DomainHelper::normalizeDomain($item->domain) === $domain) {
        $source->get('field_domain_posting_rights')->removeItem($index);
        $source->save();
        $counts['removed']++;
        break;
      }
    }
  }

  /**
   * Update domain posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string $domain
   *   The domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function updateDomainRights($source, string $domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    foreach ($source->get('field_domain_posting_rights') as $item) {
      if (isset($item->domain) && DomainHelper::normalizeDomain($item->domain) === $domain) {
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
   * Add domain posting rights for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source entity.
   * @param string $domain
   *   The domain.
   * @param int $report_rights
   *   Report rights value.
   * @param int $job_rights
   *   Job rights value.
   * @param int $training_rights
   *   Training rights value.
   * @param array &$counts
   *   Reference to counts array.
   */
  protected function addDomainRights($source, string $domain, int $report_rights, int $job_rights, int $training_rights, array &$counts): void {
    $source->get('field_domain_posting_rights')->appendItem([
      'domain' => $domain,
      'job' => $job_rights,
      'training' => $training_rights,
      'report' => $report_rights,
    ]);
    $source->save();
    $counts['added']++;
  }

  /**
   * Get the redirect URL for redirecting after saving or cancelling.
   *
   * If no destination is set, the user is redirected to the overview page.
   * If a destination is set, the user is redirected to the destination URL.
   *
   * @return \Drupal\Core\Url
   *   The redirect URL.
   */
  protected function getRedirectUrl(): Url {
    $destination_array = $this->getDestinationArray();
    if (!empty($destination_array['destination'])) {
      return Url::fromUserInput($destination_array['destination']);
    }
    return Url::fromRoute('reliefweb_moderation.domain_posting_rights.overview');
  }

}
