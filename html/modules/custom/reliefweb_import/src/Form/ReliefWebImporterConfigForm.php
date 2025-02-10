<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ReliefWeb content importer plugin form.
 */
class ReliefWebImporterConfigForm extends FormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginManager $pluginManager
   *   The ReliefWeb content importer plugin manager.
   */
  public function __construct(
    protected ReliefWebImporterPluginManager $pluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('plugin.manager.reliefweb_import.reliefweb_importer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_importer_config_form';
  }

  /**
   * {@inheritdoc}
   *
   * Builds the configuration form for a specific ReliefWeb importer plugin.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string|null $plugin_id
   *   The ID of the plugin being configured.
   *
   * @return array
   *   The form structure.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the plugin_id does not match any known plugin.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $plugin_id = NULL): array {
    if (!$this->pluginManager->hasDefinition($plugin_id)) {
      throw new NotFoundHttpException();
    }

    // Store the plugin ID so it can be retrieved in the validation and submit
    // methods.
    $form_state->set('plugin_id', $plugin_id);

    // Create a pre-configured plugin instance with the existing configuration
    // so the form fields can be populated.
    $plugin = $this->pluginManager->getPlugin($plugin_id);

    // Build the plugin configuration form.
    $form = $plugin->buildConfigurationForm($form, $form_state);

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $plugin_id = $form_state->get('plugin_id');
    $plugin = $this->pluginManager->createInstance($plugin_id);
    $plugin->validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $plugin_id = $form_state->get('plugin_id');
    $plugin = $this->pluginManager->createInstance($plugin_id);

    // Update the plugin configuration with the form values.
    $plugin->submitConfigurationForm($form, $form_state);

    // Get the new plugin configuration.
    $configuration = $plugin->getConfiguration();

    // Save the new plugin configuration.
    $plugin->saveConfiguration($configuration);

    $this->messenger()->addStatus($this->t('The configuration has been saved.'));

    // Redirect to the plugin list.
    $form_state->setRedirect('reliefweb_import.reliefweb_importer.plugin.list');
  }

  /**
   * Generates the title for the configuration form.
   *
   * @param string $plugin_id
   *   The ID of the plugin being configured.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   The translated title for the configuration form.
   */
  public function getTitle(string $plugin_id): string|MarkupInterface {
    if (!$this->pluginManager->hasDefinition($plugin_id)) {
      return $this->t('Missing plugin');
    }

    $definition = $this->pluginManager->getDefinition($plugin_id);
    return $this->t('Configure @label', ['@label' => $definition['label']]);
  }

}
