<?php

namespace Drupal\reliefweb_sync_orgs\Form;

use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_sync_orgs\Service\ImportRecordService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to manually select the proper organization.
 */
class FixOrganizationManually extends FormBase {

  /**
   * The import record service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportRecordService
   */
  protected $importRecordService;

  /**
   * Constructs a new FixOrganizationManually form.
   */
  public function __construct(ImportRecordService $import_record_service) {
    $this->importRecordService = $import_record_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_sync_orgs.import_record_service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_sync_orgs_fix_organization_manually';
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

    $form['description'] = [
      '#markup' => $this->t('Select the proper organization for source <strong>@source</strong> and ID <strong>@id</strong>.', [
        '@source' => $source,
        '@id' => $id,
      ]),
    ];

    // Display the raw csv_item for reference.
    $form['csv_item'] = [
      '#type' => 'textarea',
      '#title' => $this->t('CSV Item'),
      '#default_value' => json_encode($record['csv_item'], JSON_PRETTY_PRINT),
      '#rows' => 20,
      '#disabled' => TRUE,
      '#description' => $this->t('This is the raw CSV item that needs to be fixed.'),
    ];

    $form['organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization'),
      '#required' => TRUE,
      '#autocomplete_route_name' => 'reliefweb_sync_orgs..autocomplete.organizations',
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_org = EntityAutocomplete::extractEntityIdFromAutocompleteInput($form_state->getValue('organization'));
    $source = $form_state->getValue('source');
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

    // Update the record with the selected organization.
    $record['tid'] = $selected_org;
    $record['status'] = 'fixed';
    $this->importRecordService->saveImportRecords($source, $id, $record);

    // Here you would implement logic to save the mapping.
    $this->messenger()->addStatus($this->t('Organization "@org" selected for source "@source" and ID "@id".', [
      '@org' => $selected_org,
      '@source' => $source,
      '@id' => $id,
    ]));
  }

}
