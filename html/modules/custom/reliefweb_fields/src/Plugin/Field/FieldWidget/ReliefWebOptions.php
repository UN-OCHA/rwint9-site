<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsButtonsWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;

/**
 * Plugin implementation of the 'reliefweb_options' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_options",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb Options with description"),
 *   multiple_values = true,
 *   field_types = {
 *     "entity_reference",
 *   }
 * )
 */
class ReliefWebOptions extends OptionsButtonsWidget {

  use EntityDatabaseInfoTrait;

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Only works for taxonomy terms.
    $entity_type_id = $this->getReferencedEntityTypeId();
    if ($entity_type_id !== 'taxonomy_term') {
      return parent::formElement($items, $delta, $element, $form, $form_state);
    }

    $element = OptionsWidgetBase::formElement($items, $delta, $element, $form, $form_state);
    $options = $this->getOptions($items->getEntity());
    $selected = $this->getSelectedOptions($items);

    // Load all terms, add description.
    $bundles = $this->getReferencedBundles();
    $entity_type_manager = $this->getEntityTypeManager();

    $entities = $entity_type_manager->getStorage($entity_type_id)->loadByProperties([
      $this->getEntityTypeBundleField($entity_type_id) => $bundles,
    ]);

    $option_attributes = [];
    foreach ($options as $key => $option) {
      if (isset($entities[$key]->description) && !empty($entities[$key]->description->value)) {
        $option_attributes[$key]['data-term-description'] = $entities[$key]->description->value;
      }
    }

    // If required and there is one single option, preselect it.
    if ($this->required && count($options) == 1) {
      reset($options);
      $selected = [key($options)];
    }

    if ($this->multiple) {
      $element += [
        '#type' => 'checkboxes',
        '#default_value' => $selected,
        '#options' => $options,
        '#option_attributes' => $option_attributes,
      ];
    }
    else {
      $element += [
        '#type' => 'radios',
        // Radio buttons need a scalar value. Take the first default value, or
        // default to NULL so that the form element is properly recognized as
        // not having a default value.
        '#default_value' => $selected ? reset($selected) : NULL,
        '#options' => $options,
        '#option_attributes' => $option_attributes,
      ];
    }

    $element['#attributes']['class'][] = 'data-with-term-descriptions';
    $element['#attached']['library'][] = 'reliefweb_fields/reliefweb-options';

    return $element;
  }

  /**
   * Get the referenced entity type id.
   *
   * @return string
   *   Entity type id.
   */
  protected function getReferencedEntityTypeId() {
    return $this->fieldDefinition->getSetting('target_type');
  }

  /**
   * Get the referenced entity bundles.
   *
   * @return array
   *   List of referenced bundles.
   */
  protected function getReferencedBundles() {
    $settings = $this->fieldDefinition->getSetting('handler_settings');
    return $settings['target_bundles'] ?? [];
  }

}
