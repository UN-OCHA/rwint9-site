<?php

namespace Drupal\reliefweb_sync_orgs\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Queue\QueueInterface;
use Drupal\file\Validation\FileValidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to import a csv file and add all items to a queue.
 */
class ImportAndQueueCsvFile extends FormBase {

  use LoggerChannelTrait;

  /**
   * Queue name.
   */
  protected const QUEUE_NAME = 'reliefweb_sync_orgs_process_csv_item';

  /**
   * Queue factory.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

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
    QueueInterface $queue_factory,
    FileSystemInterface $file_system,
    FileValidatorInterface $file_validator,
  ) {
    $this->queue = $queue_factory;
    $this->fileSystem = $file_system;
    $this->fileValidator = $file_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue')->get(self::QUEUE_NAME),
      $container->get('file_system'),
      $container->get('file.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_sync_orgs_import_and_queue_csv_file';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Allow user to select the source of the CSV file.
    $form['source'] = [
      '#type' => 'select',
      '#title' => $this->t('Source'),
      '#options' => reliefweb_sync_orgs_sources(),
      '#required' => TRUE,
    ];

    $form['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV file'),
      '#description' => $this->t('CSV file containing clients to import'),
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
    if (!empty($all_files['csv_file'])) {
      $file_upload = $all_files['csv_file'];
      if ($file_upload->isValid()) {
        $form_state->setValue('csv_file', $file_upload->getRealPath());
        return;
      }
    }

    $form_state->setErrorByName('csv_file', $this->t('The file could not be uploaded.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $validators = ['FileExtension' => ['csv']];
    $file = file_save_upload('csv_file', $validators, FALSE, 0);
    if (!$file) {
      return;
    }

    $filename = $this->fileSystem->realpath($file->getFileUri());
    $source = $form_state->getValue('source');

    $this->importFromCsv($filename, $source);
  }

  /**
   * Import from csv.
   */
  public function importFromCsv(string $filename, string $source) {
    $count = 0;

    $f = fopen($filename, 'r');
    $header = fgetcsv($f, NULL, ',');

    // Replace all spaces with underscores.
    $header_lowercase = array_map(function ($value) {
      return str_replace(' ', '_', trim(strtolower($value)));
    }, $header);

    // Get data.
    while ($row = fgetcsv($f, NULL, ',')) {
      $data = [];
      for ($i = 0; $i < count($row); $i++) {
        $data[$header_lowercase[$i]] = trim($row[$i] ?? '');
      }

      // Add source to the data.
      $data['_source'] = $source;

      // Add row number to the data.
      $data['_row_number'] = $count + 1;

      $this->queue->createItem($data);
      $count++;
    }

    fclose($f);

    $message = $this->t('Queued @count items.', [
      '@count' => $count,
    ]);

    $this->messenger()->addMessage($message);
  }

}
