<?php

namespace Drupal\content_entity_clone\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\content_entity_clone\Plugin\FieldProcessorPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Content entity clone - bundle settings form handler.
 */
class BundleSettingsForm extends FormBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The content entity clone field processor plugin manager.
   *
   * @var \Drupal\content_entity_clone\Plugin\FieldProcessorPluginManagerInterface
   */
  protected $fieldProcessorManager;

  /**
   * The entity type for this form.
   *
   * @var \Drupal\Core\Entity\ContentEntityTypeInferface
   */
  protected $entityType;

  /**
   * The entity bundle for this form.
   *
   * @var string
   */
  protected $bundle;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeBundleInfoInterface $bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
    FieldProcessorPluginManagerInterface $field_processor_manager
  ) {
    $this->configFactory = $config_factory;
    $this->bundleInfo = $bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->fieldProcessorManager = $field_processor_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.content_entity_clone.field_processor')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_entity_clone_bundle_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ContentEntityTypeInterface $entity_type = NULL, $bundle = NULL) {
    $bundle_info = $this->getBundleInfo($entity_type, $bundle);
    if (empty($bundle_info)) {
      throw new NotFoundHttpException();
    }

    // Store the entity type and bundle.
    $this->entityType = $entity_type;
    $this->bundle = $bundle;

    // Get the cloning configuration for this bundle.
    $config = $this->configFactory->get($this->getConfigId());

    // Get the field settings from the configuraton.
    $defaults = [];
    foreach ($config->get('fields') ?? [] as $field_name => $info) {
      if (!empty($info['id'])) {
        $defaults[$field_name] = $info['id'];
      }
    }

    // Get the list of available fields for the bundle and generate a table
    // with the editable fields and optional processors.
    $field_definitions = $this->entityFieldManager
      ->getFieldDefinitions($entity_type->id(), $bundle);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable cloning'),
      '#default_value' => $config->get('enabled') ?? FALSE,
    ];

    $form['local_task_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Clone local task label'),
      '#default_value' => $config->get('local_task_label') ?? FALSE,
    ];

    $form['fields'] = [
      '#type' => 'table',
      '#caption' => $this->t('Clonable fields'),
      '#header' => [
        $this->t('Field'),
        $this->t('Processor'),
      ],
    ];

    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->isInternal() || $field_definition->isComputed() || $field_definition->isReadOnly()) {
        continue;
      }

      // Field label.
      $form['fields'][$field_name]['label'] = [
        '#plain_text' => $field_definition->getLabel(),
      ];

      // List of processing plugins.
      $plugins = $this->fieldProcessorManager->getAvailablePlugins($field_definition);
      if (empty($plugins)) {
        $form['fields'][$field_name]['processor'] = [
          '#plain_text' => $this->t('No processor available'),
        ];
      }
      else {
        $options = ['' => $this->t('Skip field')];
        foreach ($plugins as $plugin) {
          $options[$plugin->getPluginId()] = $plugin->getPluginLabel();
        }

        $form['fields'][$field_name]['processor'] = [
          '#type' => 'select',
          '#title' => $this->t('Processor'),
          '#title_display' => 'invisible',
          '#options' => $options,
          '#default_value' => $defaults[$field_name] ?? NULL,
        ];
      }
    }

    // Add the submit button.
    $form['actions'] = [
      '#type' => 'actions',
      '#theme_wrappers' => [
        'fieldset' => [
          '#id' => 'actions',
          '#title' => $this->t('Actions'),
          '#title_display' => 'invisible',
        ],
      ],
      '#weight' => 99,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable($this->getConfigId());

    if (empty($form_state->getValue('enabled'))) {
      $config->delete();
    }
    else {
      $fields = [];
      foreach ($form_state->getValue('fields') as $field_name => $data) {
        if (!empty($data['processor'])) {
          $fields[$field_name]['id'] = $data['processor'];
        }
      }
      $config->set('enabled', TRUE);
      $config->set('local_task_label', $form_state->getValue('local_task_label'));
      $config->set('fields', $fields);
      $config->save();
    }
  }

  /**
   * Get the config ID.
   *
   * @return string
   *   Config ID.
   */
  protected function getConfigId() {
    return 'content_entity_clone.bundle.settings.' . $this->entityType->id() . '.' . $this->bundle;
  }

  /**
   * Validate the entity type and bundle.
   *
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   Entity type.
   * @param string $bundle
   *   Entity bundle.
   *
   * @return array|false
   *   FALSE if the entity type or bundle are not valid or the bundle info
   *   otherwise.
   */
  protected function getBundleInfo(ContentEntityTypeInterface $entity_type = NULL, $bundle = NULL) {
    if (!empty($entity_type)) {
      $bundles = $this->bundleInfo->getBundleInfo($entity_type->id());
      return $bundles[$bundle] ?? FALSE;
    }
    return FALSE;
  }

  /**
   * Get the form title.
   *
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   Entity type.
   * @param string $bundle
   *   Entity bundle.
   *
   * @return string
   *   The page title.
   */
  public function getPageTitle(ContentEntityTypeInterface $entity_type = NULL, $bundle = NULL) {
    $bundle_info = $this->getBundleInfo($entity_type, $bundle);
    if (!empty($bundle_info)) {
      return $this->t('Entity Cloning Settings: @entity_type - @bundle', [
        '@entity_type' => $entity_type->getLabel(),
        '@bundle' => $bundle_info['label'] ?? ucfirst(strtr($bundle, '_', ' ')),
      ]);
    }
    return '';
  }

}
