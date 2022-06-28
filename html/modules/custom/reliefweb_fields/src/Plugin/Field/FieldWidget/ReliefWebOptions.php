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
 *     "list_integer",
 *     "list_string",
 *     "list_float",
 *     "boolean",
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
    $element = OptionsWidgetBase::formElement($items, $delta, $element, $form, $form_state);

    $options = $this->getOptions($items->getEntity());
    $selected = $this->getSelectedOptions($items);

    // Load all terms, add description.
    $entity_type_id = $this->getReferencedEntityTypeId();
    $bundles = $this->getReferencedBundles();
    $entity_type_manager = $this->getEntityTypeManager();

    $entities = [];
    foreach ($bundles as $bundle) {
      if ($entity_type_id === 'taxonomy_term') {
        $entities += $entity_type_manager->getStorage($entity_type_id)->loadByProperties([
          'vid' => $bundle,
        ]);
      }
      else {
        $entities += $entity_type_manager->getStorage($entity_type_id)->loadByProperties([
          'bundle' => $bundle,
        ]);
      }
    }

    $options_attributes = [];
    foreach ($options as $key => $option) {
      if (isset($entities[$key]->description) && !empty($entities[$key]->description->value)) {
        $options_attributes[$key]['data-term-description'] = $entities[$key]->description->value;
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
        '#options_attributes' => $options_attributes,
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
        '#options_attributes' => $options_attributes,
      ];
    }

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
