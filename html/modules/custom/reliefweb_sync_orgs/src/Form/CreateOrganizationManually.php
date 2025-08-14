<?php

namespace Drupal\reliefweb_sync_orgs\Form;

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

    $form['organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name of new organization'),
      '#required' => TRUE,
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

    // Create a new taxonomy term for the organization.
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
      'name' => $form_state->getValue('organization'),
      'vid' => 'source',
      'field_shortname' => [
        'value' => $form_state->getValue('organization'),
      ],
    ]);

    // Save the term.
    $term->save();

    // Update the record with the created organization.
    $record['tid'] = $term->id();
    $record['status'] = 'fixed';
    $this->importRecordService->saveImportRecords($source, $id, $record);

    $this->messenger()->addStatus($this->t('Organization "@org" created for source "@source" and ID "@id".', [
      '@org' => $term->label(),
      '@source' => $source,
      '@id' => $id,
    ]));
  }

}
