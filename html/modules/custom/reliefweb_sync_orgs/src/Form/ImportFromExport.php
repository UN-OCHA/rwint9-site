<?php

namespace Drupal\reliefweb_sync_orgs\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\file\Validation\FileValidatorInterface;
use Drupal\reliefweb_sync_orgs\Service\ImportExportService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to import a csv file and add all items to a queue.
 */
class ImportFromExport extends FormBase {

  use LoggerChannelTrait;

  /**
   * Queue name.
   */
  protected const QUEUE_NAME = 'reliefweb_sync_orgs_from_export';

  /**
   * Import export service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportExportService
   */
  protected $importExportService;

  /**
   * File system.
   *
   * @var Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * File validator service.
   *
   * @var \Drupal\file\Validation\FileValidatorInterface
   */
  protected $fileValidator;

  /**
   * Constructs a new InoreaderImportForm.
   */
  public function __construct(
    ImportExportService $import_export_service,
    FileSystemInterface $file_system,
    FileValidatorInterface $file_validator,
  ) {
    $this->importExportService = $import_export_service;
    $this->fileSystem = $file_system;
    $this->fileValidator = $file_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_sync_orgs.import_export_service'),
      $container->get('file_system'),
      $container->get('file.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_sync_orgs_import_from_export';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#type' => 'markup',
      '#markup' => implode('<br>', [
        $this->t('Upload a TSV file exported from the ReliefWeb Sync Organizations module to import organization records.'),
        $this->t('Set the <em>Create New</em> column to 1 to create a new organization.'),
        $this->t('Set the <em>Use sheet data</em> column to 1 to use the data from the sheet, columns: homepage, countries, short_name and description.'),
        $this->t('You can use <em>Parent Name</em> and <em>Parent ID</em> columns to set a parent organization.'),
      ]),
    ];

    $form['tsv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('TSV file'),
      '#description' => $this->t('TSV file containing records to import'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $all_files = $this->getRequest()->files->get('files', []);
    if (!empty($all_files['tsv_file'])) {
      $file_upload = $all_files['tsv_file'];
      if ($file_upload->isValid()) {
        $form_state->setValue('tsv_file', $file_upload->getRealPath());
        return;
      }
    }

    $form_state->setErrorByName('tsv_file', $this->t('The file could not be uploaded.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $validators = ['FileExtension' => ['tsv']];
    $file = file_save_upload('tsv_file', $validators, FALSE, 0);
    if (!$file) {
      return;
    }

    $filename = $this->fileSystem->realpath($file->getFileUri());

    $this->importFromTsv($filename);
  }

  /**
   * Import from tsv.
   */
  public function importFromTsv(string $filename) {
    try {
      $count = $this->importExportService->importFromTsv(self::QUEUE_NAME, $filename);
    }
    catch (\Exception $e) {
      $this->getLogger('reliefweb_sync_orgs')->error($e->getMessage());
      $this->messenger()->addError($e->getMessage());
      return;
    }

    $message = $this->t('Queued @count items.', [
      '@count' => $count,
    ]);

    $this->messenger()->addMessage($message);
  }

}
