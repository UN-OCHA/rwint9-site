<?php

namespace Drupal\reliefweb_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\reliefweb_import\Service\InoreaderService;

/**
 * Provides a form to test Inoreader feed import.
 */
class InoreaderTestForm extends FormBase {

  use LoggerChannelTrait;

  /**
   * The Inoreader service.
   *
   * @var \Drupal\reliefweb_import\Service\InoreaderService
   */
  protected $inoreaderService;

  /**
   * Constructs a new InoreaderTestForm.
   */
  public function __construct(InoreaderService $inoreader_service) {
    $this->inoreaderService = $inoreader_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_import.inoreader_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_import_inoreader_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['inoreader_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Inoreader Feed URL'),
      '#required' => TRUE,
      '#description' => $this->t('Enter the Inoreader feed URL.'),
      '#maxlength' => 255,
    ];

    $form['tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tags'),
      '#description' => $this->t('Enter tags.'),
      '#placeholder' => '[source:123][pdf:canonical]',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyze data'),
    ];

    // Display results if available.
    if ($data = $form_state->get('inoreader_data')) {
      $form['results'] = [
        '#type' => 'details',
        '#title' => $this->t('Inoreader output'),
        '#open' => FALSE,
        'content' => [
          '#markup' => '<pre>' . htmlspecialchars(print_r($data, TRUE)) . '</pre>',
        ],
      ];

      $records = $form_state->get('inoreader_records') ?? [];
      $form['records'] = [
        '#type' => 'details',
        '#title' => $this->t('Parsed output'),
        '#open' => TRUE,
        'content' => [
          '#markup' => '<pre>' . htmlspecialchars(print_r($records, TRUE)) . '</pre>',
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url = $form_state->getValue('inoreader_url');
    $tags = $form_state->getValue('tags') ?? '';

    $settings = $this->config('reliefweb_import.plugin.importer.inoreader')->get();
    $settings['api_url'] = 'https://www.inoreader.com/reader/api/0/stream/contents/';
    $settings['api_url'] .= str_replace('https://www.inoreader.com/', '', $url);

    // Fetch data using the InoreaderService.
    $this->inoreaderService->setSettings($settings);
    $this->inoreaderService->setLogger($this->getLogger('reliefweb_import.inoreader.test'));
    $data = $this->inoreaderService->getDocuments(10);

    $form_state->setRebuild();
    if (empty($data)) {
      return;
    }

    // Process the data to extract records.
    $records = [];
    foreach ($data as $document) {
      if (!empty($tags)) {
        // Remove any existing tags.
        if (strpos($document['origin']['title'], '[source:') !== FALSE) {
          $document['origin']['title'] = substr($document['origin']['title'], 0, strpos($document['origin']['title'], '[source:'));
        }

        $document['origin']['title'] .= ' ' . $tags;
      }
      $record = $this->inoreaderService->processDocumentData($document);

      if (isset($record['file_data']['bytes'])) {
        $record['file_data']['bytes'] = substr($record['file_data']['bytes'], 0, 30) . '...';
      }
      if (isset($record['body'])) {
        $record['body'] = substr($record['body'], 0, 30) . '...';
      }

      $records[] = $record;
    }

    // Store data in form state for display.
    $form_state->set('inoreader_data', $data);
    $form_state->set('inoreader_records', $records);
  }

}
