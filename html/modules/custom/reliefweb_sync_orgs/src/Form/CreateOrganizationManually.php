<?php

namespace Drupal\reliefweb_sync_orgs\Form;

use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\reliefweb_sync_orgs\Service\ImportRecordService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to manually create an organization.
 */
class CreateOrganizationManually extends FormBase {

  /**
   * The import record service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportRecordService
   */
  protected $importRecordService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new form.
   */
  public function __construct(ImportRecordService $import_record_service, EntityTypeManagerInterface $entity_type_manager) {
    $this->importRecordService = $import_record_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_sync_orgs.import_record_service'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_sync_orgs_create_organization_manually';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $source = NULL, $id = NULL) {
    // Make sure source and id are provided.
    if (!$source || !$id) {
      throw new \InvalidArgumentException('Source and ID must be provided.');
    }

    // Make sure the import record exists.
    $record = $this->importRecordService->getExistingImportRecord($source, $id);
    if (empty($record)) {
      throw new \InvalidArgumentException('No import record found for the provided source and ID.');
    }

    // Display the raw csv_item for reference.
    $form['csv_item'] = [
      '#type' => 'textarea',
      '#title' => $this->t('CSV Item'),
      '#default_value' => json_encode($record['csv_item'], JSON_PRETTY_PRINT),
      '#rows' => 20,
      '#disabled' => TRUE,
      '#description' => $this->t('This is the raw CSV item that needs to be created.'),
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name of new organization'),
      '#required' => TRUE,
    ];

    $form['short_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Short name of new organization'),
      '#required' => TRUE,
    ];

    $form['organization_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Organization type'),
      '#options' => $this->getOrganizationTypes(),
      '#required' => TRUE,
    ];

    $form['country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country'),
      '#required' => TRUE,
      '#autocomplete_route_name' => 'reliefweb_sync_orgs.autocomplete.countries',
    ];

    $form['parent_organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent organization'),
      '#required' => FALSE,
      '#autocomplete_route_name' => 'reliefweb_sync_orgs.autocomplete.organizations',
    ];

    $form['source'] = [
      '#type' => 'hidden',
      '#value' => $source,
    ];
    $form['id'] = [
      '#type' => 'hidden',
      '#value' => $id,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ],
    ];

    // Prefill the form with existing data if available.
    $field_info = reliefweb_sync_orgs_field_info($source);
    foreach ($field_info['mapping'] ?? [] as $field => $target) {
      if (isset($record['csv_item'][$field])) {
        $form[$target]['#default_value'] = $record['csv_item'][$field];
        if ($target === 'country') {
          // Try to load the country term if available.
          $country_id = $this->entityTypeManager
            ->getStorage('taxonomy_term')
            ->loadByProperties([
              'vid' => 'country',
              'name' => $record['csv_item'][$field],
            ]);

          if ($country_id) {
            $form[$target]['#default_value'] .= ' (' . reset($country_id)->id() . ')';
          }
        }
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $source = $form_state->getValue('source');
    $organization = trim($form_state->getValue('name') ?? '');
    $short_name = trim($form_state->getValue('short_name') ?? '');
    $organization_type = $form_state->getValue('organization_type');
    $country = EntityAutocomplete::extractEntityIdFromAutocompleteInput($form_state->getValue('country'));
    $parent_organization = EntityAutocomplete::extractEntityIdFromAutocompleteInput($form_state->getValue('parent_organization'));

    $id = $form_state->getValue('id');

    // Load the import record.
    $record = $this->importRecordService->getExistingImportRecord($source, $id);
    if (empty($record)) {
      $this->messenger()->addError($this->t('No import record found for source "@source" and ID "@id".', [
        '@source' => $source,
        '@id' => $id,
      ]));
      return;
    }

    $payload = [
      'name' => $organization,
      'vid' => 'source',
      'field_shortname' => [
        'value' => $short_name,
      ],
      'field_organization_type' => [
        'target_id' => $organization_type,
      ],
      'field_country' => [
        'target_id' => $country,
      ],
    ];

    if ($parent_organization) {
      $payload['parent'] = [
        'target_id' => $parent_organization,
      ];
    }

    // Create a new taxonomy term for the organization.
    /** @var \Drupal\taxonomy\Entity\Term $term */
    $term = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->create($payload);

    // Save the term.
    $term->save();

    // Update the record with the created organization.
    $record['tid'] = $term->id();
    $record['status'] = 'fixed';
    $record['message'] = 'Organization created manually';
    $this->importRecordService->saveImportRecords($source, $id, $record);

    $this->messenger()->addStatus($this->t('Organization "@org" created for source "@source" and ID "@id".', [
      '@org' => $term->label(),
      '@source' => $source,
      '@id' => $id,
    ]));
  }

  /**
   * Get a list of organization types.
   */
  protected function getOrganizationTypes() {
    $options = [];

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'organization_type']);
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }

    return $options ?? [];
  }

}
