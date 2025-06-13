<?php

namespace Drupal\reliefweb_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\reliefweb_import\Service\InoreaderService;
use EliasHaeussler\TransientLogger\TransientLogger;

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
      '#maxlength' => 2550,
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Limit'),
      '#required' => TRUE,
      '#description' => $this->t('Max items to test.'),
      '#default_value' => 3,
    ];

    $form['tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tags'),
      '#description' => $this->t('Enter tags.'),
      '#placeholder' => '[source:123][pdf:canonical]',
      '#maxlength' => 255,
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

      $screenshots = [];
      $records = $form_state->get('inoreader_records') ?? [];
      foreach ($records as &$record) {
        if (isset($record['report']['_screenshot'])) {
          $screenshots[] = '<img src="data:image/png;base64, ' . $record['report']['_screenshot'] . '" />';
          unset($record['report']['_screenshot']);
        }
      }
      $form['records'] = [
        '#type' => 'details',
        '#title' => $this->t('Parsed output'),
        '#open' => TRUE,
        'content' => [
          '#markup' => '<pre>' . htmlspecialchars(print_r($records, TRUE)) . '</pre>',
        ],
      ];

      $form['screenshots'] = [
        '#type' => 'container',
        '#title' => $this->t('Screenshots'),
      ];

      foreach ($screenshots as $screenshot) {
        $form['screenshots']['screenshot'][] = [
          '#type' => 'inline_template',
          '#template' => $screenshot,
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url = $form_state->getValue('inoreader_url');
    $tags = $form_state->getValue('tags') ?? '';
    $limit = $form_state->getValue('limit') ?? 3;
    $logger = new TransientLogger();
    $form_state->setRebuild();

    $settings = $this->config('reliefweb_import.plugin.importer.inoreader')->get();
    $settings['api_url'] = 'https://www.inoreader.com/reader/api/0/stream/contents/';
    $url = str_replace('https://www.inoreader.com/', '', $url);
    $settings['api_url'] .= $url;

    // Fetch data using the InoreaderService.
    try {
      $this->inoreaderService->setSettings($settings);
      $this->inoreaderService->setLogger($logger);
      $data = $this->inoreaderService->getDocuments($limit);

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

        $logger->flushLog();
        $record = $this->inoreaderService->processDocumentData($document);

        if (isset($record['file_data']['bytes'])) {
          $record['file_data']['bytes'] = substr($record['file_data']['bytes'], 0, 30) . '...';
        }
        if (isset($record['body'])) {
          $record['body'] = substr($record['body'], 0, 30) . '...';
        }

        $record['logs'] = $logger->getAll();

        $used_tags = [];
        if (isset($record['_tags'])) {
          $used_tags = $record['_tags'];
          unset($record['_tags']);
        }

        $has_pdf = FALSE;
        if (isset($record['_has_pdf'])) {
          $has_pdf = $record['_has_pdf'];
          unset($record['_has_pdf']);
        }

        $records[] = [
          'has_pdf' => $has_pdf,
          'tags' => $used_tags,
          'report' => $record,
        ];
      }

      // Store data in form state for display.
      $form_state->set('inoreader_data', $data);
      $form_state->set('inoreader_records', $records);
    }
    catch (\Exception $e) {
      $logger->error($this->t('Error fetching data from Inoreader: @message', ['@message' => $e->getMessage()]));
      $form_state->set('inoreader_data', $e->getMessage());
      return;
    }

  }

}
