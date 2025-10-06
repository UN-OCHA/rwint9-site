<?php

namespace Drupal\reliefweb_utility\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_utility\Helpers\FileHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing MIME type to text extraction command mapping.
 */
class TextExtractionCommandMappingForm extends ConfigFormBase {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_utility_text_extraction_command_mapping_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['reliefweb_utility.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure text extraction commands for different MIME types. These commands are stored in the site state and used by the FileHelper for text extraction.') . '</p>',
    ];

    $form['commands'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('MIME Type to Command Mapping'),
      '#tree' => TRUE,
    ];

    // Retrieve the current commands from the form state storage
    // or use the config if no commands are set.
    $commands = $form_state->get('commands');
    if (is_null($commands)) {
      $commands = $this->configFactory
        ->get('reliefweb_utility.settings')
        ->get('text_extraction.commands') ?? [];
    }
    // Clean the commands.
    $commands = $this->cleanCommands($commands);
    $form_state->set('commands', $commands);

    // Convert current form values back to the command format we need.
    foreach ($commands as $index => $command) {
      $form['commands'][$index] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Command @number', ['@number' => $index + 1]),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
      ];

      $form['commands'][$index]['mimetype'] = [
        '#type' => 'textfield',
        '#title' => $this->t('MIME Type'),
        '#description' => $this->t('The MIME type for this command (e.g., application/pdf, application/msword)'),
        '#default_value' => $command['mimetype'] ?? '',
        '#required' => TRUE,
      ];

      $form['commands'][$index]['command'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Command'),
        '#description' => $this->t('The full path to the command executable (e.g., /usr/bin/mutool, /usr/bin/pandoc)'),
        '#default_value' => $command['command'] ?? '',
        '#required' => TRUE,
      ];

      $form['commands'][$index]['args'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Arguments'),
        '#description' => $this->t('Command arguments (space-separated, e.g., "draw -F txt" for mutool)'),
        '#default_value' => $command['args'] ?? '',
      ];

      $form['commands'][$index]['options'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Additional Options'),
        '#description' => $this->t('Additional command options (optional)'),
        '#default_value' => $command['options'] ?? '',
      ];

      $form['commands'][$index]['page'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Supports Page Parameter'),
        '#description' => $this->t('Check if this command supports extracting text from a specific page (e.g., PDF files)'),
        '#default_value' => $command['page'] ?? FALSE,
      ];

      $form['commands'][$index]['ignore_errors_if_output'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Ignore Errors if Output Present'),
        '#description' => $this->t('Check if the command should return output even when it reports errors (useful for some PDF tools)'),
        '#default_value' => $command['ignore_errors_if_output'] ?? FALSE,
      ];

      $form['commands'][$index]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove this command'),
        '#name' => 'remove_' . $index,
        '#submit' => ['::removeCommand'],
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'commands-wrapper',
        ],
        '#limit_validation_errors' => [],
      ];
    }

    $form['commands']['#prefix'] = '<div id="commands-wrapper">';
    $form['commands']['#suffix'] = '</div>';

    $form['add_command'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add new command'),
      '#submit' => ['::addCommand'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'commands-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Only validate on the main submit button, not on add/remove actions.
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element['#name'] !== 'submit') {
      return;
    }

    $commands = $form_state->getValue('commands', []);
    $mimetypes = [];

    foreach ($commands as $index => $command) {
      if (empty($command['mimetype']) || empty($command['command'])) {
        continue;
      }

      // Check for duplicate MIME types in the command mapping.
      if (isset($mimetypes[$command['mimetype']])) {
        $form_state->setErrorByName("commands][$index][mimetype", $this->t('Duplicate MIME type: @mimetype', ['@mimetype' => $command['mimetype']]));
      }
      $mimetypes[$command['mimetype']] = TRUE;

      // Validate command path.
      if (!is_executable($command['command'])) {
        $form_state->setErrorByName("commands][$index][command", $this->t('Command is not executable: @command', ['@command' => $command['command']]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Only process on the main submit button, not on add/remove actions.
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element['#name'] !== 'submit') {
      return;
    }

    $commands = $form_state->getValue('commands', []);

    // Clean the commands and ensure uniqueness.
    $clean_commands = [];
    foreach ($this->cleanCommands($commands) as $command) {
      if (!empty($command['mimetype']) && !empty($command['command'])) {
        $clean_commands[$command['mimetype']] = $command;
      }
    }

    // Save to config.
    $this->configFactory->getEditable('reliefweb_utility.settings')
      ->set('text_extraction.commands', array_values($clean_commands))
      ->save();

    // Clear the FileHelper cache.
    FileHelper::clearTextExtractionCommandsCache();

    $this->messenger()->addStatus($this->t('Text extraction commands have been saved.'));
  }

  /**
   * Add a new command.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addCommand(array &$form, FormStateInterface $form_state) {
    $commands = $form_state->get('commands', []);
    $commands[] = [
      'mimetype' => '',
      'command' => '',
      'args' => '',
      'options' => '',
      'page' => FALSE,
      'ignore_errors_if_output' => FALSE,
    ];
    $commands = $this->cleanCommands($commands);
    $form_state->set('commands', $commands);
    $form_state->setRebuild();
  }

  /**
   * Remove a command.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function removeCommand(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $index = $triggering_element['#name'];
    $index = str_replace('remove_', '', $index);
    $index = (int) $index;

    $commands = $form_state->get('commands', []);
    if (isset($commands[$index])) {
      unset($commands[$index]);
      $commands = $this->cleanCommands($commands);
      $form_state->set('commands', $commands);
    }
    $form_state->setRebuild();
  }

  /**
   * AJAX callback for the form.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['commands'];
  }

  /**
   * Clean commands.
   *
   * @param array $commands
   *   The commands to clean.
   *
   * @return array
   *   The cleaned commands.
   */
  protected function cleanCommands(array $commands): array {
    // Clean each command.
    $commands = array_map([$this, 'cleanCommand'], $commands);
    // Re-index the array to avoid gaps.
    return array_values(array_filter($commands));
  }

  /**
   * Clean a command.
   *
   * @param array $command
   *   The command to clean.
   *
   * @return array
   *   The cleaned command.
   */
  protected function cleanCommand(array $command): array {
    return [
      'mimetype' => trim($command['mimetype'] ?? ''),
      'command' => trim($command['command']),
      'args' => trim($command['args'] ?? ''),
      'options' => trim($command['options'] ?? ''),
      'page' => !empty($command['page']),
      'ignore_errors_if_output' => !empty($command['ignore_errors_if_output']),
    ];
  }

}
