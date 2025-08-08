<?php

namespace Drupal\reliefweb_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use EliasHaeussler\TransientLogger\TransientLogger;

/**
 * Provides a form to import an Inoreader feed.
 */
class InoreaderImportForm extends FormBase {

  use LoggerChannelTrait;

  /**
   * Constructs a new InoreaderImportForm.
   */
  public function __construct(protected ReliefWebImporterPluginManagerInterface $importerPluginManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.reliefweb_import.reliefweb_importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_import_inoreader_import_form';
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

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import content'),
    ];

    // Display results if available.
    if ($data = $form_state->get('inoreader_logs')) {
      if (!empty($data)) {
        $logs = [];
        foreach ($data as $row) {
          $logs[] = $row->message . ' (' . $row->level->name . ')';
        }

        $form['results'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('Inoreader output'),
          '#items' => $logs,
        ];
      }
    }

    // Display error if available.
    if ($error = $form_state->get('inoreader_error')) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Error fetching data from Inoreader: @error', ['@error' => $error]),
        '#prefix' => '<div class="messages error">',
        '#suffix' => '</div>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url = $form_state->getValue('inoreader_url');
    $limit = $form_state->getValue('limit') ?? 3;
    $logger = new TransientLogger();
    $form_state->setRebuild();

    // Clear data.
    $form_state->set('inoreader_logs', []);
    $form_state->set('inoreader_error', []);

    // Inoreader plugin.
    $plugin_id = 'inoreader';
    /** @var \Drupal\reliefweb_import\Plugin\ReliefWebImporter\InoreaderImporter $inoreader */
    $inoreader = $this->importerPluginManager->getPlugin($plugin_id);

    $api_url = 'https://www.inoreader.com/reader/api/0/stream/contents/';
    $url = str_replace('https://www.inoreader.com/', '', $url);
    $api_url .= $url;

    // Fetch data using the InoreaderService.
    try {
      $conf = $inoreader->getConfiguration();
      $conf['api_url'] = $api_url;
      $inoreader->setConfiguration($conf);
      $inoreader->setLogger($logger);
      $inoreader->importContent($limit);

      $form_state->set('inoreader_logs', $logger->getAll());
    }
    catch (\Exception $e) {
      $logger->error('Error fetching data from Inoreader: @message', ['@message' => $e->getMessage()]);
      $form_state->set('inoreader_error', $e->getMessage());
    }

  }

}
