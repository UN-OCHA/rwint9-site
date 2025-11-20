<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base form for editing posting rights.
 */
abstract class PostingRightsEditFormBase extends FormBase {

  /**
   * Constructs a PostingRightsEditFormBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $userPostingRightsManager
   *   The user posting rights manager service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UserPostingRightsManagerInterface $userPostingRightsManager,
  ) {}

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
   * Get the rights options array.
   *
   * @return array
   *   Array of rights options.
   */
  protected function getRightsOptions(): array {
    return [
      0 => $this->t('Unverified'),
      1 => $this->t('Blocked'),
      2 => $this->t('Allowed'),
      3 => $this->t('Trusted'),
    ];
  }

  /**
   * Build a source link element.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source taxonomy term.
   *
   * @return array
   *   A render array for the source link.
   */
  protected function buildSourceLink(TermInterface $source): array {
    return [
      '#type' => 'link',
      '#title' => $source->label(),
      '#url' => $source->toUrl(),
      '#attributes' => [
        'target' => '_blank',
      ],
    ];
  }

  /**
   * Build the source autocomplete field for new rows.
   *
   * @param string $row_key
   *   The row key.
   * @param array $rights_options
   *   The rights options array.
   *
   * @return array
   *   A render array for the source field.
   */
  protected function buildNewRowSourceField(string $row_key, array $rights_options): array {
    return [
      '#type' => 'textfield',
      '#title' => $this->t('Source'),
      '#title_display' => 'invisible',
      '#autocomplete_route_name' => 'reliefweb_moderation.source_autocomplete',
      '#autocomplete_route_parameters' => [],
      '#attributes' => [
        'placeholder' => $this->t('Search for an organization by name, shortname, or ID.'),
      ],
      '#required' => FALSE,
      '#wrapper_attributes' => [
        'colspan' => 2,
      ],
    ];
  }

  /**
   * Build the rights select fields (report, job, training).
   *
   * @param array $rights_options
   *   The rights options array.
   * @param bool $privileged
   *   TRUE if the domain is privileged, FALSE otherwise.
   * @param int $default_value
   *   The default value for the select fields.
   *
   * @return array
   *   An array with 'report', 'job', and 'training' keys.
   */
  protected function buildRightsSelectFields(array $rights_options, bool $privileged, int $default_value = 0): array {
    if ($privileged) {
      $default_values = $this->userPostingRightsManager->getDefaultDomainPostingRightCodes();
    }
    else {
      $default_values = array_fill_keys(['report', 'job', 'training'], $default_value);
    }

    return [
      'report' => [
        '#type' => 'select',
        '#options' => $rights_options,
        '#default_value' => $default_values['report'],
      ],
      'job' => [
        '#type' => 'select',
        '#options' => $rights_options,
        '#default_value' => $default_values['job'],
      ],
      'training' => [
        '#type' => 'select',
        '#options' => $rights_options,
        '#default_value' => $default_values['training'],
      ],
    ];
  }

  /**
   * Build the remove button for new rows.
   *
   * @param string $row_key
   *   The row key.
   * @param string $ajax_wrapper_id
   *   The AJAX wrapper ID.
   *
   * @return array
   *   A render array for the remove button.
   */
  protected function buildRemoveButton(string $row_key, string $ajax_wrapper_id): array {
    return [
      '#type' => 'submit',
      '#value' => $this->t('Remove'),
      '#name' => 'remove_' . $row_key,
      '#submit' => [[$this, 'removeNewRowSubmit']],
      '#ajax' => [
        'callback' => [$this, 'removeNewRowAjax'],
        'wrapper' => $ajax_wrapper_id,
        'effect' => 'fade',
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button', 'button--small'],
      ],
    ];
  }

  /**
   * Build the "Add another item" button.
   *
   * @param string $ajax_wrapper_id
   *   The AJAX wrapper ID.
   *
   * @return array
   *   A render array for the add more button.
   */
  protected function buildAddMoreButton(string $ajax_wrapper_id): array {
    return [
      '#type' => 'submit',
      '#value' => $this->t('Add another item'),
      '#submit' => [[$this, 'addMoreSubmit']],
      '#ajax' => [
        'callback' => [$this, 'addMoreAjax'],
        'wrapper' => $ajax_wrapper_id,
        'effect' => 'fade',
      ],
      '#limit_validation_errors' => [],
    ];
  }

  /**
   * Build the form actions (submit and cancel buttons).
   *
   * @param \Drupal\Core\Url $cancel_url
   *   The cancel URL.
   *
   * @return array
   *   A render array for the form actions.
   */
  protected function buildFormActions($cancel_url): array {
    return [
      '#type' => 'actions',
      '#weight' => 2,
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save Changes'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => $cancel_url,
        '#attributes' => [
          'class' => ['button'],
        ],
      ],
    ];
  }

  /**
   * Add success messages based on counts.
   *
   * @param int $updated_count
   *   Number of updated items.
   * @param int $removed_count
   *   Number of removed items.
   * @param int $added_count
   *   Number of added items.
   */
  protected function addSuccessMessages(int $updated_count, int $removed_count, int $added_count): void {
    $messages = [];
    if ($updated_count > 0) {
      $messages[] = $this->formatPlural($updated_count, 'Updated posting rights for 1 source.', 'Updated posting rights for @count sources.');
    }
    if ($removed_count > 0) {
      $messages[] = $this->formatPlural($removed_count, 'Removed posting rights for 1 source.', 'Removed posting rights for @count sources.');
    }
    if ($added_count > 0) {
      $messages[] = $this->formatPlural($added_count, 'Added posting rights for 1 source.', 'Added posting rights for @count sources.');
    }

    if (!empty($messages)) {
      foreach ($messages as $message) {
        $this->messenger()->addStatus($message);
      }
    }
    else {
      $this->messenger()->addStatus($this->t('No changes were made.'));
    }
  }

  /**
   * Submit handler for the "Add another item" button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addMoreSubmit(array &$form, FormStateInterface $form_state): void {
    $new_rows_count = $form_state->get('new_rows_count', 0);
    $form_state->set('new_rows_count', $new_rows_count + 1);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback for the "Add another item" button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array.
   */
  public function addMoreAjax(array &$form, FormStateInterface $form_state): array {
    return $form['rights']['table'];
  }

  /**
   * Submit handler for the "Remove" button on new rows.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function removeNewRowSubmit(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? '';

    // Extract the row key from the button name (remove_<key>).
    if (strpos($button_name, 'remove_') === 0) {
      $row_key = substr($button_name, 7);

      // Add to removed new rows list.
      $removed_new_rows = $form_state->get('removed_new_rows') ?? [];
      if (!in_array($row_key, $removed_new_rows, TRUE)) {
        $removed_new_rows[] = $row_key;
        $form_state->set('removed_new_rows', $removed_new_rows);
      }

      $form_state->setRebuild();
    }
  }

  /**
   * AJAX callback for the "Remove" button on new rows.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array.
   */
  public function removeNewRowAjax(array &$form, FormStateInterface $form_state): array {
    return $form['rights']['table'];
  }

  /**
   * Get the shortname for a source.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   The source taxonomy term.
   * @param string $default
   *   The default value if the shortname is not available. Default is '-'.
   *
   * @return string
   *   The shortname or the default value if not available.
   */
  protected function getSourceShortname(TermInterface $source, string $default = '-'): string {
    $shortname = $default;
    if ($source->hasField('field_shortname') && !$source->field_shortname->isEmpty()) {
      $shortname = $source->field_shortname->value;
    }
    return $shortname;
  }

  /**
   * Load sources by IDs and sort them by label.
   *
   * @param array $source_ids
   *   The source IDs.
   * @param string $direction
   *   The direction to sort the sources. Default is 'asc'.
   *   Possible values are 'asc' and 'desc'.
   *
   * @return array
   *   The sources.
   */
  protected function loadSourcesByIds(array $source_ids, string $direction = 'asc'): array {
    if (empty($source_ids)) {
      return [];
    }
    // Load sources.
    $sources = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($source_ids);
    if (empty($sources)) {
      return [];
    }
    // Sort sources by label.
    $collator = LocalizationHelper::getCollator();
    uasort($sources, function (TermInterface $a, TermInterface $b) use ($collator) {
      return $collator->compare($a->label(), $b->label());
    });
    if ($direction === 'desc') {
      $sources = array_reverse($sources);
    }
    return $sources;
  }

}
