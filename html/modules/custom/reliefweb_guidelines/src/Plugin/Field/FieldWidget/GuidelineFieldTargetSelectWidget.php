<?php

namespace Drupal\reliefweb_guidelines\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;

/**
 * Plugin implementation of the 'guideline_field_target_select_widget' widget.
 *
 * @FieldWidget(
 *   id = "guideline_field_target_select_widget",
 *   module = "reliefweb_guidelines",
 *   label = @Translation("Guideline field target select widget"),
 *   multiple_values = true,
 *   field_types = {
 *     "guideline_field_target_type"
 *   }
 * )
 */
class GuidelineFieldTargetSelectWidget extends OptionsSelectWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'enabled_entities' => [],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = [];

    /** @var \Drupal\Core\Entity\EntityFieldManager $entity_field_manager */
    $entity_type_manager = \Drupal::service('entity_type.manager');

    /** @var \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info */
    $entity_type_bundle_info = \Drupal::service('entity_type.bundle.info');

    $entity_types = $entity_type_manager->getDefinitions();
    $bundle_info = $entity_type_bundle_info->getAllBundleInfo();

    $options = [];
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface && isset($bundle_info[$entity_type_id])) {
        foreach ($bundle_info[$entity_type_id] as $bundle => $info) {
          $options[$entity_type_id][$entity_type_id . '.' . $bundle] = $info['label'];
        }
      }
    }

    $elements['enabled_entities'] = [
      '#type' => 'select',
      '#title' => $this->t('Enabled entities'),
      '#default_value' => $this->getSetting('enabled_entities'),
      '#required' => FALSE,
      '#multiple' => TRUE,
      '#options' => $options,
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $summary[] = $this->t('Enabled entities: @enabled_entities', [
      '@enabled_entities' => implode(', ', array_filter($this->getSetting('enabled_entities'))),
    ]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    if (!isset($this->options)) {
      $options = ['_none' => $this->getEmptyLabel()];
      $options += static::getAvailableFormFields($this->getSetting('enabled_entities'));

      // Options might be nested ("optgroups"). If the widget does not support
      // nested options, flatten the list.
      if (!$this->supportsGroups()) {
        $options = OptGroup::flattenOptions($options);
      }

      $this->options = $options;
    }

    return $this->options;
  }

  /**
   * Get the list of available form fields for the given entity bundles.
   *
   * @param array $enabled_entities
   *   List of entity_type + bundle that are allowed as targets. The list items
   *   are in the form "entity_type_id.bundle".
   *
   * @return array
   *   List of available target form fields.
   */
  public static function getAvailableFormFields(array $enabled_entities) {
    $options = [];

    /** @var \Drupal\Core\Entity\EntityFieldManager $entity_field_manager */
    $entity_field_manager = \Drupal::service('entity_field.manager');

    /** @var \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info */
    $entity_type_bundle_info = \Drupal::service('entity_type.bundle.info');

    $bundle_info = $entity_type_bundle_info->getAllBundleInfo();

    $enabled_entities = array_filter($enabled_entities);
    foreach ($enabled_entities as $enabled_entity) {
      [$entity_type_id, $bundle] = explode('.', $enabled_entity);
      $bundle_label = $bundle_info[$entity_type_id][$bundle]['label'] ?? $bundle;

      // Retrieve the list of fields displayed in the default entity form.
      // Only those fields can have attached guidelines.
      $form_fields = \Drupal::entityTypeManager()
        ->getStorage('entity_form_display')
        ?->load($entity_type_id . '.' . $bundle . '.default')
        ?->getComponents();

      $field_definitions = $entity_field_manager->getFieldDefinitions($entity_type_id, $bundle);
      foreach ($field_definitions as $field_name => $field_definition) {
        // Skip if the field is not supposed to be editable.
        if (!isset($form_fields[$field_name])) {
          continue;
        }

        // Skip computed, internal and read-only fields.
        if ($field_definition->isComputed() || $field_definition->isInternal() || $field_definition->isReadOnly()) {
          continue;
        }

        $key = $entity_type_id . '.' . $bundle . '.' . $field_name;
        $options[$key] = $bundle_label . ' > ' . $field_definition->getLabel() . ' (' . $field_name . ')';
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEmptyLabel() {
    return !$this->required ? $this->t('- None -') : $this->t('- Select a value -');
  }

}
