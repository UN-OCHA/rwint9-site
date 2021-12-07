<?php

namespace Drupal\guidelines\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsButtonsWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\field\Entity\FieldConfig;

/**
 * Plugin implementation of the 'guideline_field_target_widget' widget.
 *
 * @FieldWidget(
 *   id = "guideline_field_target_widget",
 *   module = "guidelines",
 *   label = @Translation("Guideline field target widget"),
 *   multiple_values = true,
 *   field_types = {
 *     "guideline_field_target_type"
 *   }
 * )
 */
class GuidelineFieldTargetWidget extends OptionsButtonsWidget {

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

    $content_entities = array_keys(\Drupal::service('entity_type.repository')->getEntityTypeLabels(TRUE)['Content']);

    /** @var \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info */
    $entity_type_bundle_info = \Drupal::service('entity_type.bundle.info');
    $all_bundle_info = $entity_type_bundle_info->getAllBundleInfo();

    $options = [];
    foreach ($content_entities as $content_entity) {
      $options[$content_entity] = [];
      foreach ($all_bundle_info[$content_entity] as $bundle => $info) {
        $options[$content_entity][$content_entity . '.' . $bundle] = $info['label'];
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
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    if (!isset($this->options)) {
      $options = [];
      $hidden_base_fields = [
        'nid',
        'uuid',
        'vid',
        'type',
        'revision_timestamp',
        'revision_uid',
        'uid',
        'default_langcode',
        'revision_default',
        'revision_translation_affected',
      ];

      /** @var \Drupal\Core\Entity\EntityFieldManager $entity_field_manager */
      $entity_field_manager = \Drupal::service('entity_field.manager');

      $enabled_entities = array_filter($this->getSetting('enabled_entities'));
      foreach ($enabled_entities as $enabled_entity) {
        list($entity_type, $bundle) = explode('.', $enabled_entity);
        $field_definitions = $entity_field_manager->getFieldDefinitions($entity_type, $bundle);
        foreach ($field_definitions as $field_name => $field_definition) {
          if ($field_definition instanceof BaseFieldDefinition) {
            if (in_array($field_name, $hidden_base_fields)) {
              continue;
            }

            /** @var \Drupal\Core\Field\BaseFieldDefinition $field_definition */
            $key = $entity_type . '.' . $bundle . '.' . $field_name;
            $options[$key] = $this->getBundleLabel($entity_type, $bundle) . ' > ' . $field_definition->getLabel() . ' (' . $field_name . ')';
          }
          elseif ($field_definition instanceof FieldConfig) {
            /** @var \Drupal\field\Entity\FieldConfig $field_definition */
            $key = $entity_type . '.' . $bundle . '.' . $field_name;
            $options[$key] = $this->getBundleLabel($entity_type, $bundle) . ' > ' . $field_definition->getLabel() . ' (' . $field_name . ')';
          }
        }
      }

      $this->options = $options;
    }

    return $this->options;
  }

  /**
   * Get bundle label.
   */
  protected function getBundleLabel($entity_type_id, $bundle) {
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_type = $entity_type_manager
      ->getStorage($entity_type_id)
      ->getEntityType();
    $bundle_label = $entity_type_manager
      ->getStorage($entity_type->getBundleEntityType())
      ->load($bundle)
      ->label();

    return $entity_type->getLabel() . ' > ' . $bundle_label;
  }

}
